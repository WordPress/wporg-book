<?php
/**
 * Imports the book's chapters from the Markdown in WordPress/library.
 *
 * The library repo's manifest.json maps each chapter file to the slug of an
 * existing post on this site. This keeps those posts in step with the repo,
 * so a fix merged on GitHub reaches the site without anyone copying it over.
 *
 * Only posts named in the current manifest are touched, and only their
 * content and title. Nothing is ever created or deleted.
 *
 * The scheduled import stays off until the `wporg_book_import_enabled`
 * option is set, so the first run can be checked with `wp book import
 * --dry-run` before anything changes.
 *
 * @package WordPressdotorg\Theme\Book
 */

namespace WordPressdotorg\Theme\Book;

use WordPressdotorg\Markdown\Importer;
use WP_CLI;
use WP_Error;
use WP_Query;
use WPCom_GHF_Markdown_Parser;

/**
 * Keeps chapter posts in step with the Markdown in WordPress/library.
 *
 * Uses the shared importer's Markdown conversion and post meta keys, but
 * replaces its manifest handling: that version creates posts for unmatched
 * entries, writes any meta the manifest lists, and exits WP-CLI on errors.
 */
class Chapter_Importer extends Importer {

	/**
	 * Option that turns the scheduled import on.
	 */
	const ENABLED_OPTION = 'wporg_book_import_enabled';

	/**
	 * Option holding the post IDs resolved from the last good manifest.
	 */
	const IDS_OPTION = 'wporg_book_import_post_ids';

	/**
	 * Cron hook for the scheduled import.
	 */
	const CRON_HOOK = 'wporg_book_import_chapters';

	/**
	 * Chapter sources must live in this repository.
	 */
	const SOURCE_BASE = 'https://raw.githubusercontent.com/WordPress/library/';

	/**
	 * Bump when the Markdown-to-HTML transform changes, so every chapter is
	 * converted again even if its file on GitHub has not changed.
	 */
	const TRANSFORM_VERSION = 1;

	/**
	 * Meta key storing the transform version a post was last imported with.
	 *
	 * @var string
	 */
	protected $transform_meta_key = 'wporg_book_transform_version';

	/**
	 * Hooks the importer up.
	 */
	public function init() {
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'run' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_warning' ) );
		add_action( 'edit_form_top', array( $this, 'render_editor_warning' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'book import', array( $this, 'cli_import' ) );
		}
	}

	/**
	 * Base URL for the parent class. Unused, since manifest matching is by slug.
	 *
	 * @return string
	 */
	protected function get_base() {
		return home_url( '/' );
	}

	/**
	 * URL of the manifest on the library repo's trunk.
	 *
	 * @return string
	 */
	protected function get_manifest_url() {
		/**
		 * Filters the manifest URL, for testing an import from a branch.
		 *
		 * @param string $url Manifest URL.
		 */
		return apply_filters( 'wporg_book_manifest_url', self::SOURCE_BASE . 'trunk/manifest.json' );
	}

	/**
	 * Chapters are regular posts.
	 *
	 * @return string
	 */
	public function get_post_type() {
		return 'post';
	}

	/**
	 * Schedules or clears the hourly import to match the enabled option.
	 */
	public function schedule() {
		$next = wp_next_scheduled( self::CRON_HOOK );

		if ( get_option( self::ENABLED_OPTION ) && ! $next ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		} elseif ( ! get_option( self::ENABLED_OPTION ) && $next ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Reads the manifest, then imports the chapters it lists.
	 *
	 * Stops before touching any chapter if the manifest can't be read or
	 * fails validation, so a bad manifest never leaves the book half updated.
	 *
	 * @param bool $force Convert every chapter even if unchanged on GitHub.
	 * @return array|WP_Error Counts of updated, unchanged, failed and skipped chapters.
	 */
	public function run( $force = false ) {
		$chapters = $this->resolve_manifest();
		if ( is_wp_error( $chapters ) ) {
			$this->log( $chapters->get_error_message(), 'warning' );
			return $chapters;
		}

		$counts = array(
			'updated'   => 0,
			'unchanged' => 0,
			'failed'    => 0,
			'skipped'   => count( $chapters['missing'] ),
		);
		foreach ( $chapters['missing'] as $slug ) {
			$this->log( "No published post with the slug '{$slug}', skipped.", 'warning' );
		}

		foreach ( $chapters['found'] as $post_id => $doc ) {
			$this->save_manifest_entry( $post_id, $doc );
		}
		update_option( self::IDS_OPTION, array_keys( $chapters['found'] ), false );

		foreach ( array_keys( $chapters['found'] ) as $post_id ) {
			$result = $this->update_post_from_markdown_source( $post_id, $force );
			if ( is_wp_error( $result ) ) {
				++$counts['failed'];
				$this->log( "Post {$post_id}: " . $result->get_error_message(), 'warning' );
			} elseif ( $result ) {
				++$counts['updated'];
				$this->log( "Post {$post_id}: updated." );
			} else {
				++$counts['unchanged'];
			}
		}

		return $counts;
	}

	/**
	 * Fetches and validates the manifest, and finds the post for each entry.
	 *
	 * Nothing is written here. Every entry must have a slug, a title, and a
	 * source inside the library repo, or the whole manifest is rejected.
	 *
	 * @return array|WP_Error 'found' (post ID => entry, with the source made
	 *                        absolute) and 'missing' (slugs with no post).
	 */
	public function resolve_manifest() {
		$response = wp_safe_remote_get( $this->get_manifest_url() );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'invalid-http-code', 'The manifest returned HTTP ' . wp_remote_retrieve_response_code( $response ) . '.' );
		}

		$manifest = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $manifest ) || ! $manifest ) {
			return new WP_Error( 'invalid-manifest', 'The manifest is not a JSON object.' );
		}

		$chapters = array(
			'found'   => array(),
			'missing' => array(),
		);
		foreach ( $manifest as $key => $doc ) {
			if ( ! is_array( $doc ) || ! isset( $doc['slug'], $doc['title'], $doc['markdown_source'] )
				|| ! is_string( $doc['slug'] ) || ! is_string( $doc['title'] ) || ! is_string( $doc['markdown_source'] )
			) {
				return new WP_Error( 'invalid-manifest', "Manifest entry '{$key}' needs string slug, title and markdown_source." );
			}

			if ( '' === trim( $doc['slug'] ) || sanitize_title( $doc['slug'] ) !== $doc['slug'] ) {
				return new WP_Error( 'invalid-manifest', "Manifest entry '{$key}' has an invalid slug." );
			}

			$source = $this->generate_markdown_source_url( $doc['markdown_source'] );
			if ( ! $this->is_library_url( $source ) ) {
				return new WP_Error( 'invalid-manifest', "Manifest entry '{$key}' points outside WordPress/library." );
			}

			$post_id = $this->find_chapter( $doc['slug'] );
			if ( ! $post_id ) {
				$chapters['missing'][] = $doc['slug'];
				continue;
			}

			$chapters['found'][ $post_id ] = array(
				'slug'            => $doc['slug'],
				'title'           => $doc['title'],
				'markdown_source' => $source,
			);
		}

		return $chapters;
	}

	/**
	 * Whether a URL is a file in the WordPress/library repo.
	 *
	 * Decodes the path first, so an encoded `..` can't climb out of the repo.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	protected function is_library_url( $url ) {
		$decoded = rawurldecode( $url );

		return 0 === strpos( $decoded, self::SOURCE_BASE )
			&& false === strpos( $decoded, '..' )
			&& false === strpos( $decoded, '\\' );
	}

	/**
	 * Finds the published post with a chapter's slug.
	 *
	 * @param string $slug Post slug.
	 * @return int Post ID, or 0 if there is none.
	 */
	protected function find_chapter( $slug ) {
		$query = new WP_Query(
			array(
				'post_type'      => $this->get_post_type(),
				'post_status'    => 'publish',
				'name'           => $slug,
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);

		return $query->posts ? (int) $query->posts[0] : 0;
	}

	/**
	 * Stores a chapter's manifest entry and source, and nothing else.
	 *
	 * Forgets the stored ETag when the source moves, so the new file is read.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $doc     Validated manifest entry.
	 */
	protected function save_manifest_entry( $post_id, $doc ) {
		update_post_meta( $post_id, $this->manifest_entry_meta_key, $doc );

		if ( update_post_meta( $post_id, $this->meta_key, esc_url_raw( $doc['markdown_source'] ) ) ) {
			delete_post_meta( $post_id, $this->etag_meta_key );
		}
	}

	/**
	 * Imports only the chapters in the last good manifest.
	 *
	 * The parent class imports every published post, which would keep
	 * importing chapters after they leave the manifest.
	 */
	public function import_all_markdown() {
		foreach ( (array) get_option( self::IDS_OPTION, array() ) as $post_id ) {
			$this->update_post_from_markdown_source( $post_id );
		}
	}

	/**
	 * Reads the manifest through the validated path instead of the parent's.
	 */
	public function import_manifest() {
		$this->run();
	}

	/**
	 * Updates one chapter from its Markdown on GitHub.
	 *
	 * Keeps every post field except the content and title, applies the title
	 * from the manifest even when the file is unchanged, and only stores the
	 * ETag after the post saves.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $force   Ignore the stored ETag.
	 * @return bool|WP_Error True if updated, false if unchanged.
	 */
	protected function update_post_from_markdown_source( $post_id, $force = false ) {
		$html = $this->fetch_chapter_html( $post_id, $force );
		if ( is_wp_error( $html ) ) {
			return $html;
		}

		$post_data = array( 'ID' => $post_id );

		$title = $this->get_manifest_title( $post_id );
		if ( $title && get_post_field( 'post_title', $post_id, 'raw' ) !== $title ) {
			$post_data['post_title'] = wp_slash( $title );
		}

		if ( null !== $html['html'] && get_post_field( 'post_content', $post_id, 'raw' ) !== $html['html'] ) {
			$post_data['post_content'] = wp_slash( $html['html'] );
		}

		if ( count( $post_data ) > 1 ) {
			$saved = wp_update_post( $post_data, true );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}

		if ( null !== $html['html'] ) {
			update_post_meta( $post_id, $this->etag_meta_key, wp_slash( $html['etag'] ) );
			update_post_meta( $post_id, $this->transform_meta_key, self::TRANSFORM_VERSION );
		}

		return count( $post_data ) > 1;
	}

	/**
	 * Fetches a chapter's Markdown and converts it to the post's HTML.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $force   Ignore the stored ETag.
	 * @return array|WP_Error 'html' (null if unchanged since the last import) and 'etag'.
	 */
	public function fetch_chapter_html( $post_id, $force = false ) {
		$source = $this->get_markdown_source( $post_id );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		if ( ! $this->is_library_url( $source ) ) {
			return new WP_Error( 'invalid-source', 'Markdown source is outside WordPress/library.' );
		}

		if ( ! class_exists( 'WPCom_GHF_Markdown_Parser' ) && defined( 'JETPACK__PLUGIN_DIR' ) ) {
			include JETPACK__PLUGIN_DIR . '/_inc/lib/markdown.php';
		}
		if ( ! class_exists( 'WPCom_GHF_Markdown_Parser' ) ) {
			return new WP_Error( 'missing-jetpack-markdown', 'Jetpack Markdown is missing on system.' );
		}

		$args = array(
			'headers'     => array(),
			'redirection' => 0,
		);
		$etag = get_post_meta( $post_id, $this->etag_meta_key, true );
		$same = (int) get_post_meta( $post_id, $this->transform_meta_key, true ) === self::TRANSFORM_VERSION;
		if ( $etag && $same && ! $force ) {
			$args['headers']['If-None-Match'] = $etag;
		}

		$response = wp_safe_remote_get( $source, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 304 === $code ) {
			return array(
				'html' => null,
				'etag' => $etag,
			);
		}
		if ( 200 !== $code ) {
			return new WP_Error( 'invalid-http-code', "Markdown source returned HTTP {$code}." );
		}

		$parser                      = new WPCom_GHF_Markdown_Parser();
		$parser->preserve_shortcodes = false;
		$html                        = $parser->transform( $this->prepare_markdown( wp_remote_retrieve_body( $response ) ) );
		$html                        = $this->prepare_html( $html, $source, $post_id );

		add_filter( 'wp_kses_allowed_html', array( $this, 'wp_kses_allow_links' ), 10, 2 );
		$html = wp_kses_post( $html );
		remove_filter( 'wp_kses_allowed_html', array( $this, 'wp_kses_allow_links' ), 10 );

		return array(
			'html' => $html,
			'etag' => wp_remote_retrieve_header( $response, 'etag' ),
		);
	}

	/**
	 * Drops the volume title and chapter heading from the top of a chapter.
	 *
	 * Each file opens with them for GitHub and the ebooks, but the site shows
	 * the post title instead. Later headings in the chapter stay.
	 *
	 * @param string $markdown Raw Markdown.
	 * @return string
	 */
	public function prepare_markdown( $markdown ) {
		$markdown = preg_replace( '#^---(.+)---#Us', '', $markdown );
		$markdown = preg_replace( '/\A\s*#\s[^\n]*\n/', '', $markdown );
		$markdown = preg_replace( '/\A\s*##\s[^\n]*\n/', '', $markdown );

		return trim( $markdown );
	}

	/**
	 * Fixes footnote anchors and relative image paths in converted HTML.
	 *
	 * Jetpack Markdown names footnote anchors like `fn:1`, and KSES strips the
	 * `#fn:1` links as a bad protocol, so they are renamed to `fn-266-1`. The
	 * post ID keeps them unique when more than one chapter is on a page.
	 *
	 * @param string $html    Converted HTML.
	 * @param string $source  URL of the chapter's Markdown file.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public function prepare_html( $html, $source, $post_id ) {
		$html = preg_replace(
			'/(id="|href="#)(fn|fnref\d*):([^"]+)"/',
			'$1$2-' . (int) $post_id . '-$3"',
			$html
		);

		return preg_replace_callback(
			'/(<img\s[^>]*src=")([^"]+)"/i',
			function ( $m ) use ( $source ) {
				return $m[1] . esc_url( $this->resolve_url( html_entity_decode( $m[2] ), $source ) ) . '"';
			},
			$html
		);
	}

	/**
	 * Resolves an image path relative to the Markdown file it appears in.
	 *
	 * Absolute URLs pass through. A path starting with `/` is relative to
	 * the root of the library repo at the same branch, as on GitHub.
	 *
	 * @param string $path   Path from the Markdown, such as `../Resources/a.png`.
	 * @param string $source URL of the Markdown file.
	 * @return string Absolute URL.
	 */
	protected function resolve_url( $path, $source ) {
		if ( preg_match( '#^([a-z][a-z0-9+.-]*:|//)#i', $path ) ) {
			return $path;
		}

		$suffix = '';
		if ( preg_match( '/[?#].*$/s', $path, $m ) ) {
			$suffix = $m[0];
			$path   = substr( $path, 0, -strlen( $suffix ) );
		}

		// Directory of the Markdown file, or the branch root for root-relative paths.
		$base  = strtok( $source, '?#' );
		$parts = explode( '/', substr( $base, 0, strrpos( $base, '/' ) ) );
		if ( '/' === substr( $path, 0, 1 ) ) {
			$parts = array_slice( explode( '/', $base ), 0, 6 ); // https:, '', host, WordPress, library, branch.
		}

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '..' === $segment ) {
				array_pop( $parts );
			} elseif ( '.' !== $segment && '' !== $segment ) {
				$parts[] = rawurlencode( rawurldecode( $segment ) );
			}
		}

		return implode( '/', $parts ) . $suffix;
	}

	/**
	 * The chapter title from the manifest entry stored on the post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	protected function get_manifest_title( $post_id ) {
		$entry = get_post_meta( $post_id, $this->manifest_entry_meta_key, true );

		return empty( $entry['title'] ) ? '' : sanitize_text_field( $entry['title'] );
	}

	/**
	 * GitHub page for editing an imported chapter.
	 *
	 * @param int $post_id Post ID.
	 * @return string URL, or empty if the post is not an imported chapter.
	 */
	protected function get_github_link( $post_id ) {
		if ( ! in_array( (int) $post_id, (array) get_option( self::IDS_OPTION, array() ), true ) ) {
			return '';
		}

		$source = $this->get_markdown_source( $post_id );
		if ( is_wp_error( $source ) || ! $this->is_library_url( $source ) ) {
			return '';
		}

		return 'https://github.com/WordPress/library/blob/' . substr( $source, strlen( self::SOURCE_BASE ) );
	}

	/**
	 * Warns block editor users that edits to an imported chapter get overwritten.
	 */
	public function enqueue_editor_warning() {
		$post = get_post();
		$link = $post ? $this->get_github_link( $post->ID ) : '';
		if ( ! $link ) {
			return;
		}

		wp_register_script( 'wporg-book-import-notice', false, array( 'wp-data', 'wp-notices', 'wp-dom-ready' ), '1', true );
		wp_enqueue_script( 'wporg-book-import-notice' );
		wp_add_inline_script(
			'wporg-book-import-notice',
			sprintf(
				'wp.domReady( function() { wp.data.dispatch( "core/notices" ).createWarningNotice( %s, { isDismissible: false, actions: [ { label: %s, url: %s } ] } ); } );',
				wp_json_encode( __( 'This chapter is imported from GitHub, and edits made here will be overwritten.', 'wporg-book' ) ),
				wp_json_encode( __( 'Edit it on GitHub', 'wporg-book' ) ),
				wp_json_encode( esc_url_raw( $link ) )
			)
		);
	}

	/**
	 * The same warning for the classic editor.
	 *
	 * @param \WP_Post $post Post being edited.
	 */
	public function render_editor_warning( $post ) {
		$link = $this->get_github_link( $post->ID );
		if ( ! $link ) {
			return;
		}

		printf(
			'<div class="notice notice-warning inline"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'This chapter is imported from GitHub, and edits made here will be overwritten.', 'wporg-book' ),
			esc_url( $link ),
			esc_html__( 'Edit it on GitHub', 'wporg-book' )
		);
	}

	/**
	 * Imports the book's chapters from WordPress/library.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show which chapters would change, without saving anything.
	 *
	 * [--force]
	 * : Convert every chapter again, even if unchanged on GitHub.
	 *
	 * ## EXAMPLES
	 *
	 *     wp book import --dry-run
	 *     wp book import
	 *     wp option update wporg_book_import_enabled 1
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 */
	public function cli_import( $args, $assoc_args ) {
		if ( ! empty( $assoc_args['dry-run'] ) ) {
			$this->cli_dry_run();
			return;
		}

		$counts = $this->run( ! empty( $assoc_args['force'] ) );
		if ( is_wp_error( $counts ) ) {
			WP_CLI::error( $counts->get_error_message() );
		}

		$summary = sprintf( '%d updated, %d unchanged, %d failed, %d skipped.', $counts['updated'], $counts['unchanged'], $counts['failed'], $counts['skipped'] );
		if ( $counts['failed'] || $counts['skipped'] ) {
			WP_CLI::error( $summary );
		}
		WP_CLI::success( $summary );
	}

	/**
	 * Lists what an import would change, without saving anything.
	 */
	protected function cli_dry_run() {
		$chapters = $this->resolve_manifest();
		if ( is_wp_error( $chapters ) ) {
			WP_CLI::error( $chapters->get_error_message() );
		}

		$problems = count( $chapters['missing'] );
		foreach ( $chapters['missing'] as $slug ) {
			WP_CLI::warning( "{$slug}: no published post with this slug." );
		}

		foreach ( $chapters['found'] as $post_id => $doc ) {
			$this->dry_run_source( $post_id, $doc['markdown_source'] );
			$parsed = $this->fetch_chapter_html( $post_id, true );
			$this->dry_run_source( $post_id, null );
			if ( is_wp_error( $parsed ) ) {
				++$problems;
				WP_CLI::warning( "{$doc['slug']}: " . $parsed->get_error_message() );
				continue;
			}

			$post = get_post( $post_id );
			WP_CLI::log(
				sprintf(
					'%-45s %s  words %5d -> %5d  title %s',
					$doc['slug'],
					$post->post_content === $parsed['html'] ? 'same   ' : 'changes',
					str_word_count( wp_strip_all_tags( $post->post_content ) ),
					str_word_count( wp_strip_all_tags( $parsed['html'] ) ),
					$post->post_title === $doc['title'] ? 'same' : "\"{$post->post_title}\" -> \"{$doc['title']}\""
				)
			);
		}

		if ( $problems ) {
			WP_CLI::error( "{$problems} chapters could not be checked." );
		}
	}

	/**
	 * Points a dry run at a source URL without saving it to the post.
	 *
	 * @param int         $post_id Post ID.
	 * @param string|null $source  Source URL, or null to stop overriding.
	 */
	protected function dry_run_source( $post_id, $source ) {
		static $filter = null;

		if ( $filter ) {
			remove_filter( 'get_post_metadata', $filter, 10 );
			$filter = null;
		}
		if ( null === $source ) {
			return;
		}

		$filter = function ( $value, $object_id, $meta_key ) use ( $post_id, $source ) {
			if ( (int) $object_id !== (int) $post_id ) {
				return $value;
			}
			if ( $this->meta_key === $meta_key ) {
				return array( $source );
			}
			if ( in_array( $meta_key, array( $this->etag_meta_key, $this->transform_meta_key ), true ) ) {
				return array( '' );
			}
			return $value;
		};
		add_filter( 'get_post_metadata', $filter, 10, 3 );
	}

	/**
	 * Logs a message to WP-CLI when running there.
	 *
	 * @param string $message Message.
	 * @param string $level   'log' or 'warning'.
	 */
	protected function log( $message, $level = 'log' ) {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::$level( $message );
		}
	}
}

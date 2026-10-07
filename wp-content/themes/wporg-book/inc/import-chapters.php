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
 */

namespace WordPressdotorg\Theme\Book;

use WordPressdotorg\Markdown\Importer;
use WP_CLI;
use WP_Error;
use WPCom_GHF_Markdown_Parser;

/**
 * Keeps chapter posts in step with the Markdown in WordPress/library.
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
	 * Post IDs matched while reading the manifest.
	 *
	 * @var int[]
	 */
	protected $resolved_ids = array();

	/**
	 * Hooks the importer up.
	 */
	public function init() {
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'run' ) );
		add_action( 'edit_form_top', array( $this, 'render_editor_warning' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'book import', array( $this, 'cli_import' ) );
		}
	}

	/**
	 * Base URL stripped from permalinks when matching posts to manifest keys.
	 *
	 * Chapter permalinks are dated, so keys never match on the full path and
	 * the parent class falls back to matching on the post slug.
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
		return apply_filters( 'wporg_book_manifest_url', 'https://raw.githubusercontent.com/WordPress/library/trunk/manifest.json' );
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
	 * Stops before touching any chapter if the manifest can't be read, so a
	 * bad manifest never leaves the book half updated.
	 *
	 * @param bool $force Convert every chapter even if unchanged on GitHub.
	 * @return array|WP_Error Counts of updated, unchanged and failed chapters.
	 */
	public function run( $force = false ) {
		$this->resolved_ids = array();

		$result = $this->import_manifest();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		update_option( self::IDS_OPTION, $this->resolved_ids, false );

		$counts = array(
			'updated'   => 0,
			'unchanged' => 0,
			'failed'    => 0,
		);
		foreach ( $this->resolved_ids as $post_id ) {
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
	 * Never creates posts.
	 *
	 * The parent class creates a post for any manifest entry it can't match,
	 * so a typo in the manifest would publish a stray post. New chapters get
	 * their post made by hand, then a manifest entry with its slug.
	 *
	 * @param array $doc      Manifest entry.
	 * @param array $manifest Whole manifest.
	 * @return false
	 */
	protected function process_manifest_doc( $doc, $manifest ) {
		$this->log( "No published post with the slug '{$doc['slug']}', skipped.", 'warning' );
		return false;
	}

	/**
	 * Records each matched post, and forgets its ETag when its source moves.
	 *
	 * @param int   $post_id Matched post ID.
	 * @param array $doc     Manifest entry.
	 * @return bool True if the post's Markdown source changed.
	 */
	protected function update_post_from_manifest_doc( $post_id, $doc ) {
		$this->resolved_ids[] = (int) $post_id;

		$changed = parent::update_post_from_manifest_doc( $post_id, $doc );
		if ( $changed ) {
			delete_post_meta( $post_id, $this->etag_meta_key );
		}

		return $changed;
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
	 * Updates one chapter from its Markdown on GitHub.
	 *
	 * Replaces the parent method to keep every post field except the content
	 * and title, apply the title from the manifest even when the file is
	 * unchanged, and only store the ETag after the post saves.
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

		if ( ! class_exists( 'WPCom_GHF_Markdown_Parser' ) && defined( 'JETPACK__PLUGIN_DIR' ) ) {
			include JETPACK__PLUGIN_DIR . '/_inc/lib/markdown.php';
		}
		if ( ! class_exists( 'WPCom_GHF_Markdown_Parser' ) ) {
			return new WP_Error( 'missing-jetpack-markdown', 'Jetpack Markdown is missing on system.' );
		}

		$args = array( 'headers' => array() );
		$etag = get_post_meta( $post_id, $this->etag_meta_key, true );
		$same = (int) get_post_meta( $post_id, $this->transform_meta_key, true ) === self::TRANSFORM_VERSION;
		if ( $etag && $same && ! $force ) {
			$args['headers']['If-None-Match'] = $etag;
		}

		$response = wp_remote_get( $source, $args );
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
				return $m[1] . esc_url( $this->resolve_url( $m[2], $source ) ) . '"';
			},
			$html
		);
	}

	/**
	 * Resolves a path relative to the Markdown file it appears in.
	 *
	 * @param string $path   Path from the Markdown, such as `../Resources/a.png`.
	 * @param string $source URL of the Markdown file.
	 * @return string Absolute URL.
	 */
	protected function resolve_url( $path, $source ) {
		if ( preg_match( '#^([a-z]+:)?//#i', $path ) ) {
			return $path;
		}

		$base  = strtok( $source, '?' );
		$parts = explode( '/', substr( $base, 0, strrpos( $base, '/' ) ) );
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '..' === $segment ) {
				array_pop( $parts );
			} elseif ( '.' !== $segment && '' !== $segment ) {
				$parts[] = rawurlencode( rawurldecode( $segment ) );
			}
		}

		return implode( '/', $parts );
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
	 * Tells editors that changes to an imported chapter will be overwritten.
	 *
	 * @param \WP_Post $post Post being edited.
	 */
	public function render_editor_warning( $post ) {
		if ( ! in_array( $post->ID, (array) get_option( self::IDS_OPTION, array() ), true ) ) {
			return;
		}

		$source = $this->get_markdown_source( $post->ID );
		$link   = is_wp_error( $source ) ? '' : str_replace( 'https://raw.githubusercontent.com/WordPress/library/', 'https://github.com/WordPress/library/blob/', $source );

		printf(
			'<div class="notice notice-warning inline"><p>%s</p></div>',
			sprintf(
				/* translators: %s: URL of the chapter on GitHub. */
				wp_kses_post( __( 'This chapter is imported from GitHub, and edits made here will be overwritten. <a href="%s">Edit it on GitHub</a> instead.', 'wporg-book' ) ),
				esc_url( $link )
			)
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
		$force = ! empty( $assoc_args['force'] );

		if ( empty( $assoc_args['dry-run'] ) ) {
			$counts = $this->run( $force );
			if ( is_wp_error( $counts ) ) {
				WP_CLI::error( $counts->get_error_message() );
			}
			WP_CLI::success( sprintf( '%d updated, %d unchanged, %d failed.', $counts['updated'], $counts['unchanged'], $counts['failed'] ) );
			return;
		}

		$manifest = json_decode( (string) wp_remote_retrieve_body( wp_remote_get( $this->get_manifest_url() ) ), true );
		if ( ! is_array( $manifest ) ) {
			WP_CLI::error( 'Could not read the manifest.' );
		}

		foreach ( $manifest as $doc ) {
			$post = get_page_by_path( $doc['slug'], OBJECT, 'post' );
			if ( ! $post || 'publish' !== $post->post_status ) {
				WP_CLI::warning( "{$doc['slug']}: no published post with this slug." );
				continue;
			}

			// Read the source from the manifest, not post meta, since nothing is saved yet.
			$this->dry_run_source( $post->ID, $this->generate_markdown_source_url( $doc['markdown_source'] ) );
			$parsed = $this->fetch_chapter_html( $post->ID, true );
			$this->dry_run_source( $post->ID, null );
			if ( is_wp_error( $parsed ) ) {
				WP_CLI::warning( "{$doc['slug']}: " . $parsed->get_error_message() );
				continue;
			}

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

( new Chapter_Importer() )->init();

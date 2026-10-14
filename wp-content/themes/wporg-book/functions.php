<?php
/**
 * WordPress Book functions and definitions.
 */

namespace WordPressdotorg\Theme\Book;

/**
 * Actions & fitlers.
 */
add_action( 'after_setup_theme', __NAMESPACE__ . '\setup_theme' );
add_action( 'after_setup_theme', __NAMESPACE__ . '\set_content_width', 0 ); // Priority 0 to make it available to lower priority callbacks.
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_assets' );
add_action( 'enqueue_block_assets', __NAMESPACE__ . '\enqueue_editor_assets' );
add_filter( 'jetpack_open_graph_tags', __NAMESPACE__ . '\open_graph_tags', 20 );

// Enable Jetpack opengraph by default, like the other wordpress.org themes.
add_filter( 'jetpack_enable_open_graph', '__return_true' );

/**
 * Sets up theme defaults and registers support for various WordPress features.
 *
 * Note that this function is hooked into the after_setup_theme hook, which
 * runs before the init hook. The init hook is too late for some features, such
 * as indicating support for post thumbnails.
 */
function setup_theme() {
	// Add default posts and comments RSS feed links to head.
	add_theme_support( 'automatic-feed-links' );

	/*
	 * Let WordPress manage the document title.
	 * By adding theme support, we declare that this theme does not use a
	 * hard-coded <title> tag in the document head, and expect WordPress to
	 * provide it for us.
	 */
	add_theme_support( 'title-tag' );

	/*
	 * Enable support for Post Thumbnails on posts and pages.
	 *
	 * @link https://developer.wordpress.org/themes/functionality/featured-images-post-thumbnails/
	 */
	add_theme_support( 'post-thumbnails' );

	// This theme uses wp_nav_menu() in one location.
	register_nav_menus(
		array(
			'home'          => esc_html__( 'Vol 1 Landing', 'wporg-book' ),
			'primary'       => esc_html__( 'Vol 1 Primary', 'wporg-book' ),
			'vol-2-home'    => esc_html__( 'Vol 2 Landing', 'wporg-book' ),
			'vol-2-primary' => esc_html__( 'Vol 2 Primary', 'wporg-book' ),
		)
	);

	/*
	 * Switch default core markup for search form, comment form, and comments
	 * to output valid HTML5.
	 */
	add_theme_support(
		'html5',
		array(
			'search-form',
			'comment-form',
			'comment-list',
			'gallery',
			'caption',
		)
	);

	/**
	 * Say that we provide the wp4 styles so that the global header doesn't add them.
	 */
	add_theme_support( 'wp4-styles' );
}

/**
 * Set the content width in pixels, based on the theme's design and stylesheet.
 *
 * @global int $content_width
 */
function set_content_width() {
	$GLOBALS['content_width'] = 1000;
}

/**
 * Enqueue scripts and styles.
 */
function enqueue_assets() {
	wp_enqueue_style(
		'wporg-book-style',
		get_stylesheet_uri(),
		array(),
		filemtime( __DIR__ . '/style.css' )
	);

	wp_enqueue_script(
		'wporg-book-skip-link-focus-fix',
		get_template_directory_uri() . '/js/skip-link-focus-fix.js',
		array(),
		'20130115',
		true
	);

	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
}

/**
 * Enqueue scripts and styles.
 */
function enqueue_editor_assets() {
	wp_enqueue_script(
		'wporg-book-editor-script',
		get_template_directory_uri() . '/js/editor.js',
		array( 'wp-element', 'wp-data', 'wp-core-data', 'wp-components', 'wp-plugins', 'wp-edit-post' ),
		filemtime( __DIR__ . '/js/editor.js' ),
		false
	);
}

/**
 * Adjust the social sharing tags for the book.
 *
 * The site's description only covers Volume 2, so the home page describes both
 * volumes. Jetpack skips images without a height attribute, which rules out
 * every chapter image, so a chapter uses its first image instead. Images named
 * like icon-256x256.jpg are passed over, because site-branding.php strips that
 * size from og:image and the resulting URL may not exist.
 *
 * @param array $tags Open Graph tags from Jetpack.
 * @return array
 */
function open_graph_tags( $tags ) {
	if ( is_front_page() ) {
		$tags['og:description'] = __( 'The story of WordPress in two volumes: Milestones, from a few lines of code in an apartment above a bar in Corsica to the web\'s most popular platform, and Building Blocks, its second decade from 2013 to 2023.', 'wporg-book' );
	}

	if ( is_singular( 'post' ) && preg_match_all( '/<img\s[^>]*?\bsrc="([^"]+)"/', get_post_field( 'post_content', get_queried_object_id() ), $matches ) ) {
		foreach ( $matches[1] as $src ) {
			$image = esc_url_raw( html_entity_decode( $src ) );
			if ( ! $image || preg_match( '/-\d+x\d+\./', $image ) ) {
				continue;
			}

			$tags['og:image'] = $image;
			unset( $tags['og:image:width'], $tags['og:image:height'], $tags['og:image:secure_url'], $tags['og:image:alt'] );

			if ( isset( $tags['twitter:image'] ) ) {
				$tags['twitter:image'] = $image;
			}
			break;
		}
	}

	return $tags;
}

/**
 * Custom template tags for this theme.
 */
require get_template_directory() . '/inc/template-tags.php';

/**
 * Custom functions that act independently of the theme templates.
 */
require get_template_directory() . '/inc/extras.php';

/**
 * Chapter importer, which needs the wporg-markdown plugin.
 */
if ( class_exists( '\\WordPressdotorg\\Markdown\\Importer' ) ) {
	require get_template_directory() . '/inc/class-chapter-importer.php';
	( new Chapter_Importer() )->init();
}

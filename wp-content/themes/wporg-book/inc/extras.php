<?php
/**
 * Custom functions that act independently of the theme templates.
 */

namespace WordPressdotorg\Theme\Book;

/**
 * Actions & fitlers.
 */
add_filter( 'post_class', __NAMESPACE__ . '\post_classes' );
add_action( 'init', __NAMESPACE__ . '\register_book_meta' );
add_filter( 'manage_post_posts_columns', __NAMESPACE__ . '\add_posts_columns' );
add_action( 'manage_post_posts_custom_column', __NAMESPACE__ . '\handle_custom_column', 10, 2 );

/**
 * Adds custom classes to the array of post classes.
 *
 * @param array $classes Classes for the post element.
 * @return array
 */
function post_classes( $classes ) {
	global $post;

	// Part landing pages have chapter = 0, but so does the Introduction, which is not a part cover.
	// The Introduction has part = 0, so we can skip that.
	if ( 0 === (int) get_post_meta( $post->ID, 'mb_chapter', true ) && 0 !== (int) get_post_meta( $post->ID, 'mb_part', true ) ) {
		$classes[] = 'part-cover';
	}

	if ( has_shortcode( $post->post_content, 'wporg_book_contents' ) ) {
		$classes[] = 'table-of-contents';
	}

	return $classes;
}


/**
 * Register the volume, part, & chapter meta fields.
 */
function register_book_meta() {
	register_post_meta(
		'post',
		'mb_vol',
		array(
			'show_in_rest' => true,
			'single' => true,
			'type' => 'integer',
		)
	);
	register_post_meta(
		'post',
		'mb_part',
		array(
			'show_in_rest' => true,
			'single' => true,
			'type' => 'integer',
		)
	);
	register_post_meta(
		'post',
		'mb_chapter',
		array(
			'show_in_rest' => true,
			'single' => true,
			'type' => 'integer',
		)
	);

	add_shortcode( 'wporg_book_contents', __NAMESPACE__ . '\book_contents_shortcode' );
}

/**
 * Add the chapter info column to the list table.
 */
function add_posts_columns( $columns ) {
	$columns = array_slice( $columns, 0, 3, true )
				+ array( 'part-chapter' => 'Order' )
				+ array_slice( $columns, 3, null, true );

	return $columns;
}

/**
 * Output the chapter info on the list table.
 */
function handle_custom_column( $column, $post_id ) {
	switch ( $column ) {
		case 'part-chapter':
			$volume = get_post_meta( $post_id, 'mb_vol', true );
			$part = get_post_meta( $post_id, 'mb_part', true );
			$chapter = get_post_meta( $post_id, 'mb_chapter', true );
			printf(
				'Vol %1$s<br /> Part %2$s, Ch %3$s',
				esc_html( $volume ),
				esc_html( $part ),
				esc_html( $chapter )
			);
			break;
	}
}

/**
 * Set up the shortcode for the table of contents.
 */
function book_contents_shortcode( $atts ) {
	$volume = $atts['vol'] ?? 1;
	$toc = build_table_of_contents( $volume );
	$output = '';

	while ( count( $toc ) > 0 ) { // phpcs:ignore
		$item = array_shift( $toc );
		if ( 0 === $item['chapter'] && 0 === $item['part'] ) {
			$output .= sprintf( '<ul><li><a href="%1$s">%2$s</a></li>', get_permalink( $item['ID'] ), $item['title'] );
		} elseif ( 0 === $item['chapter'] ) {
			$output .= sprintf( '</ul><li><a href="%1$s">%2$s</a></li><ul>', get_permalink( $item['ID'] ), $item['title'] );
		} else {
			$output .= sprintf( '<li><a href="%1$s">%2$s</a></li>', get_permalink( $item['ID'] ), $item['title'] );
		}

		if ( 0 === count( $toc ) ) {
			$output .= '</ul>';
		}
	}

	return "<ol>$output</ol>";
}

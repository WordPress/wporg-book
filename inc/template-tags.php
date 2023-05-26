<?php
/**
 * Custom template tags for this theme.
 */

namespace WordPressdotorg\Theme\Book;

/**
 * Prints HTML with meta information for the categories, tags and comments.
 */
function chapter_navigation() {
	$next = get_next_chapter();
	if ( ! $next ) {
		return;
	}
	if ( ! $next['part'] ) {
		/* translators: %1$s Chapter number, %2$s Chapter name */
		$link_text = sprintf( __( 'Next: Chapter %1$s: %2$s', 'wporg-book' ), $next['chapter'], $next['title'] );
	} else if ( $next['chapter'] ) {
		/* translators: %1$s Part number, %2$s Chapter number, %3$s Chapter name */
		$link_text = sprintf( __( 'Next: Part %1$s, Chapter %2$s: %3$s', 'wporg-book' ), $next['part'], $next['chapter'], $next['title'] );
	} else {
		/* translators: %1$s Part name */
		$link_text = sprintf( __( 'Next: %1$s', 'wporg-book' ), $next['title'] );
	}
	?>
	<nav class="navigation post-navigation" role="navigation">
		<h2 class="screen-reader-text"><?php esc_html_e( 'Chapter Navigation', 'wporg-book' ); ?></h2>
		<div class="nav-links">
			<div class="nav-next">
				<?php
				printf(
					'<a href="%s" rel="next">%s</a>',
					esc_url( get_permalink( $next['ID'] ) ),
					esc_html( $link_text )
				);
				?>
			</div>
		</div>
	</nav>
	<?php
}

/**
 * Get information about the next chapter or section (post), given a "starting" chapter.
 *
 * @param int|object $post Optional. A post ID or object. Default is the current post.
 * @return array|bool Associative array of information about the next chapter (or section).
 *                    False if there is no next chapter.
 */
function get_next_chapter( $post = 0 ) {
	$post = get_post( $post );
	if ( ! isset( $post ) ) {
		return false;
	}
	$toc = build_table_of_contents( get_post_meta( $post->ID, 'mb_vol', true ) );
	if ( ! isset( $toc[ $post->ID ] ) ) {
		return false;
	}
	$place = absint( $toc[ $post->ID ]['order'] );
	$result = wp_list_filter( $toc, [ 'order' => $place + 1 ] );
	if ( count( $result ) < 1 ) {
		return false;
	}
	return array_shift( $result );
}

/**
 * Build an array of chapters and sections, all posts. Sections have post_parent = 0,
 * and chapters have the post_parent of their section. They're put in order by menu_order,
 * this is set up in PressBooks.
 *
 * @return array Ordered list of chapters and sections, with enough information to create links.
 */
function build_table_of_contents( $vol = 1 ) {
	$posts = get_posts(
		array(
			'orderby' => 'meta_value_num',
			'meta_key' => 'mb_chapter',
			'meta_query' => array(
				array(
					'key' => 'mb_vol',
					'value' => $vol,
				),
			),
			'posts_per_page' => 100, // Get all chapters.
		)
	);

	$chapters = array();
	foreach ( $posts as $p ) {
		$chapters[ $p->ID ] = array(
			'ID' => $p->ID,
			'title' => get_the_title( $p->ID ),
			'part' => (int) get_post_meta( $p->ID, 'mb_part', true ),
			'chapter' => (int) get_post_meta( $p->ID, 'mb_chapter', true ),
		);
	}

	// Ensure this list is sorted by part/chapter order.
	usort(
		$chapters,
		function( $a, $b ) {
			if ( $a['part'] === $b['part'] ) {
				return ( $a['chapter'] < $b['chapter'] ) ? -1 : 1;
			}
			return ( $a['part'] < $b['part'] ) ? -1 : 1;
		}
	);

	$order_num = 0;
	$toc = array();
	foreach ( $chapters as $chapter ) {
		$chapter['order'] = $order_num;
		$toc[ $chapter['ID'] ] = $chapter;
		$order_num++;
	}

	return $toc;
}

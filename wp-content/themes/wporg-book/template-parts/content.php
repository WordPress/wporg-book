<?php
/**
 * Template part for displaying posts (chapters).
 */

$part = (int) get_post_meta( get_the_ID(), 'mb_part', true );
$chapter = (int) get_post_meta( get_the_ID(), 'mb_chapter', true );
?>

<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
	<header class="entry-header">
		<?php
		if ( $chapter ) {
			echo '<div class="entry-chapter">';
			if ( $part ) {
				/* translators: %1$s Part number, %2$s Chapter number */
				echo esc_html( sprintf( __( 'Part %1$s/Chapter %2$s', 'wporg-book' ), $part, $chapter ) );
			} else {
				/* translators: %s Chapter number */
				echo esc_html( sprintf( __( 'Chapter %s', 'wporg-book' ), $chapter ) );
			}
			echo '</div>';
		}

		if ( is_single() ) {
			the_title( '<h1 class="entry-title">', '</h1>' );
		} else {
			the_title( '<h2 class="entry-title"><a href="' . esc_url( get_permalink() ) . '" rel="bookmark">', '</a></h2>' );
		}
		?>
	</header><!-- .entry-header -->

	<div class="entry-content">
	<?php
	the_content(
		sprintf(
			/* translators: %s: Name of current post. */
			wp_kses( __( 'Continue reading %s <span class="meta-nav">&rarr;</span>', 'wporg-book' ), array( 'span' => array( 'class' => array() ) ) ),
			the_title( '<span class="screen-reader-text">"', '"</span>', false )
		)
	);

	wp_link_pages(
		array(
			'before' => '<div class="page-links">' . esc_html__( 'Pages:', 'wporg-book' ),
			'after'  => '</div>',
		)
	);
	?>
	</div><!-- .entry-content -->
</article><!-- #post-## -->

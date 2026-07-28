<?php
/**
 * The template for displaying all single posts.
 */

use function WordPressdotorg\Theme\Book\chapter_navigation;

get_header(); ?>

	<div id="primary" class="content-area">
		<main id="main" class="site-main" role="main">

		<?php
		while ( have_posts() ) :
			the_post();
			get_template_part( 'template-parts/content' );

		endwhile; // End of the loop.
		?>

		</main><!-- #main -->

		<?php chapter_navigation(); ?>
	</div><!-- #primary -->

<?php
get_sidebar();
get_footer();

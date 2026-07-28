<?php
/**
 * Template part for displaying a message that posts cannot be found.
 */

?>

<section class="no-results not-found">
	<header class="page-header">
		<h1 class="page-title"><?php esc_html_e( 'Nothing Found', 'wporg-book' ); ?></h1>
	</header><!-- .page-header -->

	<div class="page-content">
		<p><?php esc_html_e( 'It seems we can&rsquo;t find what you&rsquo;re looking for. Perhaps searching can help.', 'wporg-book' ); ?></p>
		<?php get_search_form(); ?>
	</div><!-- .page-content -->
</section><!-- .no-results -->

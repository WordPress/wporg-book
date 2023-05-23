<?php
/**
 * The template for displaying 404 pages (not found).
 */

get_header(); ?>

	<div id="primary" class="content-area">
		<main id="main" class="site-main" role="main">

			<section class="error-404 not-found">
				<header class="page-header">
					<h1 class="page-title"><?php esc_html_e( 'Oops! That page can&rsquo;t be found.', 'wporg-book' ); ?></h1>
				</header><!-- .page-header -->

				<div class="page-content">
					<p><a href="/table-of-contents/"><?php esc_html_e( 'Return to the Table of Contents', 'wporg-book' ); ?></a></p>
				</div><!-- .page-content -->
			</section><!-- .error-404 -->

		</main><!-- #main -->
	</div><!-- #primary -->

<?php
get_footer();

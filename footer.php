<?php
/**
 * The template for displaying the footer.
 *
 * Contains the closing of the #content div and all content after.
 */

$book_github_url = 'https://github.com/WordPress/wp20-book';
if ( 1 === (int) get_post_meta( get_the_ID(), 'mb_vol', true ) ) {
	$book_github_url = 'https://github.com/WordPress/book/';
}
?>

	</div><!-- #content -->

	<footer id="colophon" class="site-footer" role="contentinfo">
		<div class="site-info">
			<div class="code-is-poetry">
				<img src="https://wordpress.org/wp-content/mu-plugins/pub-sync/blocks/global-header-footer/images/code-is-poetry-for-light-bg.svg" alt="Code is Poetry" width="188" height="13">
			</div>
			<span class="dashicons dashicons-wordpress"></span>
			<?php
			// phpcs:disable
			printf(
				__( 'Copyright GPLv2 and Creative Commons Sharealike. <a href="%s">Help make this book better</a>.', 'wporg-book' ),
				$book_github_url
			);
			// phpcs:enable
			?>
		</div><!-- .site-info -->
	</footer><!-- #colophon -->
</div><!-- #page -->

<?php wp_footer(); ?>

</body>
</html>

<?php
/**
 * The template for displaying the footer.
 *
 * Contains the closing of the #content div and all content after.
 */

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
				'https://github.com/WordPress/book/'
			);
			// phpcs:enable
			?>
		</div><!-- .site-info -->
	</footer><!-- #colophon -->
</div><!-- #page -->

<?php wp_footer(); ?>

</body>
</html>

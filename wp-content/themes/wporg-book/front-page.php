<?php
/**
 * The template for displaying all pages.
 *
 * This is the template that displays all pages by default.
 * Please note that this is the WordPress construct of pages
 * and that other 'pages' on your WordPress site may use a
 * different template.
 */

get_header( 'home' );

while ( have_posts() ) :
	the_post();
	$src = '';
	$image_id = get_post_thumbnail_id( $post );
	if ( $image_id ) {
		$image = wp_get_attachment_image_src( $image_id, 'full' );
		if ( $image ) {
			list( $src, $width, $height ) = $image;
		}
	}
	?>

	<div class="site-header" style="background-image: url('<?php echo esc_url( $src ); ?>');">
		<header class="site-branding">
			<h1 class="site-title"><a href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home"><?php bloginfo( 'name' ); ?></a></h1>
			<p class="site-description"><?php bloginfo( 'description', 'display' ); ?></p>
		</header><!-- .site-branding -->

		<div class="entry-content">
			<?php the_content(); ?>
		</div><!-- .entry-content -->
	</div>

	<div id="primary" class="content-area">
		<nav class="main-navigation" role="navigation">
			<?php
			wp_nav_menu(
				array(
					'theme_location' => 'home',
					'menu_id' => 'home-menu',
				)
			);
			?>
		</nav><!-- #site-navigation -->
	</div><!-- #primary -->

<?php endwhile; // End of the loop.
get_sidebar();
get_footer();

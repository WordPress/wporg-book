<?php
/**
 * The header for our theme.
 *
 * This is the template that displays all of the <head> section and everything up until <div id="content">
 */

$volume = (int) get_post_meta( get_the_ID(), 'mb_vol', true );
$menu_location = 'primary';
if ( 2 === $volume ) {
	$menu_location = 'vol-2-primary';
}

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="profile" href="http://gmpg.org/xfn/11">
<link rel="pingback" href="<?php bloginfo( 'pingback_url' ); ?>">

<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<div id="page" class="site">
	<a class="skip-link screen-reader-text" href="#content"><?php esc_html_e( 'Skip to content', 'wporg-book' ); ?></a>

	<header id="masthead" class="site-header">
		<div class="site-branding">
			<?php if ( is_front_page() && is_home() ) : ?>
				<h1 class="site-title"><a href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home"><?php bloginfo( 'name' ); ?></a></h1>
			<?php else : ?>
				<p class="site-title"><a href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home"><?php bloginfo( 'name' ); ?></a></p>
			<?php endif; ?>

			<nav id="site-navigation" class="main-navigation" role="navigation">
				<?php wp_nav_menu(
					array(
						'theme_location' => $menu_location,
						'menu_id' => 'primary-menu',
					)
				); ?>
			</nav><!-- #site-navigation -->
		</div><!-- .site-branding -->
	</header><!-- #masthead -->

	<div id="content" class="site-content">

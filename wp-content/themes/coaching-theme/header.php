<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip-link" href="#main">Skip to content</a>

<header class="site-header">
	<div class="container site-header__bar">
		<a class="brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?> home">
			<span class="brand__mark" aria-hidden="true">A</span>
			<span class="brand__name"><?php bloginfo( 'name' ); ?></span>
		</a>

		<button class="nav-toggle" type="button" aria-expanded="false" aria-controls="primary-nav" aria-label="Open menu">
			<span class="nav-toggle__bars" aria-hidden="true"></span>
		</button>

		<nav id="primary-nav" class="nav" aria-label="Primary">
			<?php
			wp_nav_menu(
				array(
					'theme_location' => 'primary',
					'container'      => false,
					'menu_class'     => 'nav__list',
					'fallback_cb'    => 'astona_menu_fallback',
				)
			);
			?>
			<a class="btn btn--primary nav__cta" href="<?php echo esc_url( astona_apply_url() ); ?>">Apply Now</a>
		</nav>
	</div>
</header>

<main id="main" class="site-main" tabindex="-1">

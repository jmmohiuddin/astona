<?php
/**
 * Student portal sub-navigation. Args: current (dashboard|courses|notices|payments|profile).
 */
$current = isset( $args['current'] ) ? (string) $args['current'] : '';
$items   = array(
	'dashboard' => 'Dashboard',
	'courses'   => 'My courses',
	'notices'   => 'Notices',
	'payments'  => 'Payments',
	'profile'   => 'Profile',
);
?>
<nav class="portal-nav" aria-label="Student portal">
	<ul class="portal-nav__list">
		<?php foreach ( $items as $view => $label ) : ?>
			<li><a href="<?php echo esc_url( CC_Portal_Router::url( $view ) ); ?>"<?php echo $current === $view ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a></li>
		<?php endforeach; ?>
		<li><button type="button" class="portal-nav__logout" id="portal-logout">Log out</button></li>
	</ul>
</nav>

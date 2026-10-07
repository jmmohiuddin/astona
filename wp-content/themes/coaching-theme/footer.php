</main>

<?php $contact = astona_contact(); ?>
<footer class="site-footer">
	<div class="container site-footer__grid">
		<div>
			<p class="brand brand--footer"><span class="brand__mark" aria-hidden="true">A</span><span class="brand__name"><?php bloginfo( 'name' ); ?></span></p>
			<p class="muted"><?php bloginfo( 'description' ); ?></p>
		</div>
		<div>
			<h2 class="footer-title">Explore</h2>
			<ul class="footer-list">
				<li><a href="<?php echo esc_url( get_post_type_archive_link( 'cc_course' ) ); ?>">Courses</a></li>
				<li><a href="<?php echo esc_url( get_post_type_archive_link( 'cc_notice' ) ); ?>">Notices</a></li>
				<li><a href="<?php echo esc_url( get_post_type_archive_link( 'cc_faculty' ) ); ?>">Faculty</a></li>
				<li><a href="<?php echo esc_url( home_url( '/faq/' ) ); ?>">FAQ</a></li>
			</ul>
		</div>
		<div>
			<h2 class="footer-title">Contact</h2>
			<ul class="footer-list">
				<li><?php echo esc_html( $contact['address'] ); ?></li>
				<li><a href="tel:<?php echo esc_attr( preg_replace( '/[^+\d]/', '', $contact['phone'] ) ); ?>"><?php echo esc_html( $contact['phone'] ); ?></a></li>
				<li><a href="mailto:<?php echo esc_attr( $contact['email'] ); ?>"><?php echo esc_html( $contact['email'] ); ?></a></li>
			</ul>
		</div>
	</div>
	<div class="container site-footer__legal">
		<span>© <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?></span>
		<span><a href="<?php echo esc_url( home_url( '/privacy/' ) ); ?>">Privacy</a> · <a href="<?php echo esc_url( home_url( '/terms/' ) ); ?>">Terms</a></span>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>

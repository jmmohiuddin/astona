<?php
defined( 'ABSPATH' ) || exit;

/**
 * Staff roles and capabilities for the admin area. The student role (CC_Roles::ROLE) never receives any of these.
 *
 * Bumping VERSION re-runs apply(), which converges every role back to role_definitions(): caps the definition grants
 * are re-added (including any an owner removed by hand) and caps it does not grant are removed. Per-site capability
 * edits to cc_owner, cc_staff or cc_instructor therefore do not survive a VERSION bump. Grant extra access to an
 * individual user instead of editing the role, or change role_definitions() and bump VERSION.
 */
final class CC_Admin_Roles {

	const CAP_REVIEW_APPLICATIONS = 'cc_review_applications';
	const CAP_VIEW_STUDENTS       = 'cc_view_students';
	const CAP_MANAGE_STUDENTS     = 'cc_manage_students';
	const CAP_VIEW_PAYMENTS       = 'cc_view_payments';
	const CAP_RECONCILE_PAYMENTS  = 'cc_reconcile_payments';
	const CAP_EXPORT_DATA         = 'cc_export_data';
	const CAP_VIEW_AUDIT          = 'cc_view_audit';
	const CAP_MANAGE_SETTINGS     = 'cc_manage_settings';
	const CAP_MANAGE_STAFF        = 'cc_manage_staff';
	const CAP_VIEW_DASHBOARD      = 'cc_view_dashboard';
	const CAP_MANAGE_CONTENT      = 'cc_manage_content';
	const CAP_MANAGE_LIVE         = 'cc_manage_live';
	const CAP_MANAGE_NOTICES      = 'cc_manage_notices';
	const CAP_MANAGE_BLOG         = 'cc_manage_blog';
	const CAP_MANAGE_GALLERY      = 'cc_manage_gallery';
	const CAP_VIEW_INQUIRIES      = 'cc_view_inquiries';
	const CAP_MANAGE_INQUIRIES    = 'cc_manage_inquiries';
	const CAP_MANAGE_MEDIA        = 'cc_manage_media';

	const ROLE_OWNER      = 'cc_owner';
	const ROLE_STAFF      = 'cc_staff';
	const ROLE_INSTRUCTOR = 'cc_instructor';

	const VERSION_OPTION = 'cc_admin_roles_version';
	const VERSION        = '3';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_apply' ), 5 );
		add_filter( 'login_redirect', array( __CLASS__, 'filter_login_redirect' ), 20, 3 );
	}

	/** Staff land on the Astona dashboard instead of profile.php; explicit redirect_to targets are left alone. */
	public static function filter_login_redirect( $redirect_to, $requested, $user ) {
		if ( ! $user instanceof WP_User || ! array_intersect( array( self::ROLE_OWNER, self::ROLE_STAFF, self::ROLE_INSTRUCTOR ), (array) $user->roles ) ) {
			return $redirect_to;
		}
		$is_default = '' === (string) $requested || untrailingslashit( admin_url() ) === untrailingslashit( (string) $requested );
		return $is_default ? admin_url( 'admin.php?page=cc-dashboard' ) : $redirect_to;
	}

	/** @return string[] */
	public static function all_caps(): array {
		return array(
			self::CAP_REVIEW_APPLICATIONS,
			self::CAP_VIEW_STUDENTS,
			self::CAP_MANAGE_STUDENTS,
			self::CAP_VIEW_PAYMENTS,
			self::CAP_RECONCILE_PAYMENTS,
			self::CAP_EXPORT_DATA,
			self::CAP_VIEW_AUDIT,
			self::CAP_MANAGE_SETTINGS,
			self::CAP_MANAGE_STAFF,
			self::CAP_VIEW_DASHBOARD,
			self::CAP_MANAGE_CONTENT,
			self::CAP_MANAGE_LIVE,
			self::CAP_MANAGE_NOTICES,
			self::CAP_MANAGE_BLOG,
			self::CAP_MANAGE_GALLERY,
			self::CAP_VIEW_INQUIRIES,
			self::CAP_MANAGE_INQUIRIES,
			self::CAP_MANAGE_MEDIA,
		);
	}

	/** @return array<string,array{label:string,caps:string[]}> */
	public static function role_definitions(): array {
		return array(
			self::ROLE_OWNER      => array(
				'label' => 'Owner',
				'caps'  => array_merge( array( 'read' ), self::all_caps() ),
			),
			self::ROLE_STAFF      => array(
				'label' => 'Staff',
				'caps'  => array(
					'read',
					self::CAP_REVIEW_APPLICATIONS,
					self::CAP_VIEW_STUDENTS,
					self::CAP_MANAGE_STUDENTS,
					self::CAP_VIEW_PAYMENTS,
					self::CAP_EXPORT_DATA,
					self::CAP_VIEW_DASHBOARD,
					self::CAP_MANAGE_CONTENT,
					self::CAP_MANAGE_LIVE,
					self::CAP_MANAGE_NOTICES,
					self::CAP_MANAGE_BLOG,
					self::CAP_MANAGE_GALLERY,
					self::CAP_VIEW_INQUIRIES,
					self::CAP_MANAGE_INQUIRIES,
					self::CAP_MANAGE_MEDIA,
				),
			),
			self::ROLE_INSTRUCTOR => array(
				'label' => 'Instructor',
				'caps'  => array( 'read', self::CAP_VIEW_DASHBOARD ),
			),
		);
	}

	public static function maybe_apply(): void {
		if ( get_option( self::VERSION_OPTION ) !== self::VERSION ) {
			self::apply();
		}
	}

	/** Idempotent: converges each role to its definition and grants the administrator every cc_ cap. */
	public static function apply(): void {
		foreach ( self::role_definitions() as $name => $definition ) {
			$role = get_role( $name );
			if ( null === $role ) {
				add_role( $name, $definition['label'], array() );
				$role = get_role( $name );
			}
			foreach ( $definition['caps'] as $cap ) {
				$role->add_cap( $cap );
			}
			foreach ( array_diff( self::all_caps(), $definition['caps'] ) as $cap ) {
				$role->remove_cap( $cap );
			}
		}

		$administrator = get_role( 'administrator' );
		if ( null !== $administrator ) {
			foreach ( self::all_caps() as $cap ) {
				$administrator->add_cap( $cap );
			}
		}

		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/** @param string[] $caps */
	public static function user_can_any( array $caps ): bool {
		foreach ( $caps as $cap ) {
			if ( current_user_can( $cap ) ) {
				return true;
			}
		}
		return false;
	}
}

<?php
defined( 'ABSPATH' ) || exit;

/**
 * POST /cc/v1/live-classes/{id}/join. Cookie auth means core requires the wp_rest nonce. The meeting URL is
 * decrypted per request and appears only in the 200 body; denials, logs and errors never carry it.
 */
final class CC_Rest_Live {

	const NAMESPACE_  = 'cc/v1';
	const JOIN_LIMIT  = 10;
	const JOIN_WINDOW = 60;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'no_store' ), 10, 3 );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_,
			'/live-classes/(?P<id>\d+)/join',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'join' ),
				'permission_callback' => array( __CLASS__, 'is_student_request' ),
				'args'                => array(
					'id' => array( 'type' => 'integer', 'required' => true, 'minimum' => 1 ),
				),
			)
		);
	}

	public static function is_student_request(): bool {
		$user = wp_get_current_user();
		return $user->exists() && CC_Student_Guard::is_student( $user );
	}

	/** Core-generated errors (bad nonce, 401/403, argument validation) bypass respond(); cover the whole route family. */
	public static function no_store( $response, $server, $request ) {
		if ( $response instanceof WP_HTTP_Response && 0 === strpos( $request->get_route(), '/' . self::NAMESPACE_ . '/live-classes/' ) ) {
			$response->header( 'Cache-Control', 'private, no-store' );
		}
		return $response;
	}

	public static function join( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		if ( ! CC_Rate_Limiter::allow( 'live_join|' . $user_id, self::JOIN_LIMIT, self::JOIN_WINDOW ) ) {
			return self::error( 429, 'rate_limited', 'Too many attempts. Please wait a minute and try again.' );
		}
		$id  = (int) $request['id'];
		$row = CC_Live_Repository::find( $id );
		if ( null === $row ) {
			return self::error( 404, 'not_found', 'Live class not found.' );
		}
		if ( ! CC_Enrollment_Repository::user_has_active( $user_id, (int) $row['batch_id'] ) ) {
			return self::error( 403, 'not_enrolled', 'You do not have access to this class.' );
		}
		if ( CC_Live_Repository::STATE_ACTIVE !== CC_Live_Repository::state( $row ) ) {
			return self::error( 409, 'outside_window', 'This class is not open to join right now.' );
		}

		try {
			$url = CC_Live_Repository::meeting_url( $id );
		} catch ( Throwable $e ) {
			error_log( sprintf( 'CC_Rest_Live join: live class %d link unreadable: %s', $id, get_class( $e ) ) );
			return self::error( 500, 'unavailable', 'The class link is unavailable. Please contact support.' );
		}
		if ( '' === $url ) {
			return self::error( 404, 'not_found', 'Live class not found.' );
		}
		self::log_join( $id, $user_id );
		return self::respond( array( 'url' => $url ) );
	}

	private static function log_join( int $live_class_id, int $user_id ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'cc_join_log',
			array(
				'live_class_id' => $live_class_id,
				'user_id'       => $user_id,
				'joined_at'     => gmdate( 'Y-m-d H:i:s' ),
				'ip_hash'       => hash_hmac( 'sha256', CC_Rate_Limiter::client_ip(), wp_salt( 'auth' ) ),
			),
			array( '%d', '%d', '%s', '%s' )
		);
	}

	private static function respond( array $body, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $body, $status );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	private static function error( int $status, string $code, string $message ): WP_REST_Response {
		return self::respond( array( 'code' => $code, 'message' => $message ), $status );
	}
}

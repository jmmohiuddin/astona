<?php
defined( 'ABSPATH' ) || exit;

/** PATCH /cc/v1/me/profile for the logged-in student. Errors use {code, message, details?}. */
final class CC_Rest_Portal {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			'cc/v1',
			'/me/profile',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'update' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => array(
					'guardian_name'  => array( 'type' => 'string' ),
					'guardian_phone' => array( 'type' => 'string' ),
					'email'          => array( 'type' => 'string' ),
				),
			)
		);
	}

	public static function can_edit(): bool {
		$user = wp_get_current_user();
		return $user->exists() && in_array( 'cc_student', (array) $user->roles, true );
	}

	public static function update( WP_REST_Request $request ): WP_REST_Response {
		$result = CC_Portal_Data::update_profile( get_current_user_id(), $request->get_json_params() ?: $request->get_body_params() );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			return self::respond(
				array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'details' => $data['fields'] ?? null ),
				(int) ( $data['status'] ?? 400 )
			);
		}
		return self::respond( array( 'message' => 'Details saved', 'profile' => $result ) );
	}

	/** @param array<string,mixed> $body */
	private static function respond( array $body, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $body, $status );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}
}

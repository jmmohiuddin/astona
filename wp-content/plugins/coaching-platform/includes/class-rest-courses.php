<?php
defined( 'ABSPATH' ) || exit;

/**
 * GET /wp-json/cc/v1/courses — public, cacheable catalogue (SDD §5.1).
 */
final class CC_Rest_Courses {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			'cc/v1',
			'/courses',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'index' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'category' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_title',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'mode'     => array(
						'type'              => 'string',
						'enum'              => CC_Batch_Repository::MODES,
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);
	}

	public static function index( WP_REST_Request $request ): WP_REST_Response {
		$filters = array_filter(
			array(
				'category' => (string) $request->get_param( 'category' ),
				'mode'     => (string) $request->get_param( 'mode' ),
			)
		);

		$response = new WP_REST_Response( array( 'items' => CC_Batch_Repository::course_summaries( $filters ) ) );
		$response->header( 'Cache-Control', 'public, max-age=60' );
		return $response;
	}
}

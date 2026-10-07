<?php
defined( 'ABSPATH' ) || exit;

/**
 * POST /cc/v1/contact (public). Order: Origin/Referer host check, validation, honeypot, captcha, rate limits. Validation
 * runs first so a filled honeypot cannot be told apart from an empty one by the error reply; only a valid honeypot
 * submission gets the silent success reply (nothing stored). Errors use the envelope {code, message, details?}.
 *
 * Captcha: the cc_verify_captcha filter is implemented with Cloudflare Turnstile (TURNSTILE_SECRET constant or env var).
 * Without a secret it passes only in local/development environments and fails closed everywhere else.
 */
final class CC_Rest_Contact {

	const HONEYPOT        = 'company_site';
	const FIELDS          = array( 'name', 'phone', 'email', 'topic', 'course_id', 'message' );
	const IP_LIMIT        = 3;
	// Per phone number per day. Deliberately loose: a phone is attacker-chosen, so a low cap would let anyone lock a
	// victim out of the form. GLOBAL_LIMIT is the real backstop.
	const PHONE_LIMIT     = 20;
	const GLOBAL_LIMIT    = 200;
	const TURNSTILE_FIELD = 'cf-turnstile-response';
	const TURNSTILE_URL   = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	const CAPTCHA_TIMEOUT = 5;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'cc_verify_captcha', array( __CLASS__, 'verify_turnstile' ), 10, 2 );
		CC_Inquiry_Repository::schedule_purge();
	}

	public static function register_routes(): void {
		$args = array();
		foreach ( array_merge( self::FIELDS, array( self::HONEYPOT, self::TURNSTILE_FIELD ) ) as $name ) {
			$args[ $name ] = array( 'type' => 'string' );
		}
		register_rest_route(
			'cc/v1',
			'/contact',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'submit' ),
				'permission_callback' => '__return_true', // Public: anonymous visitors; guarded by honeypot, captcha filter and rate limits.
				'args'                => $args,
			)
		);
	}

	public static function submit( WP_REST_Request $request ): WP_REST_Response {
		if ( ! self::origin_allowed( $request ) ) {
			return self::error( 403, 'forbidden', 'Request not allowed.' );
		}

		$checked = CC_Inquiry_Repository::validate( self::clean_input( $request ) );
		if ( $checked['errors'] ) {
			return self::error( 422, 'validation_failed', 'Please correct the highlighted fields.', $checked['errors'] );
		}
		if ( '' !== trim( (string) $request->get_param( self::HONEYPOT ) ) ) {
			return self::respond( array( 'ok' => true ) );
		}
		if ( ! apply_filters( 'cc_verify_captcha', true, $request ) ) {
			return self::error( 400, 'bot_check_failed', 'Submission rejected.' );
		}

		// Limits apply after validation so a visitor fixing typos does not use up the hourly allowance. The phone bucket
		// is only counted once the IP and global buckets pass, so a flooding client cannot burn a victim's phone allowance.
		if ( ! CC_Rate_Limiter::allow( 'contact-ip:' . CC_Rate_Limiter::client_ip(), self::IP_LIMIT, HOUR_IN_SECONDS )
			|| ! CC_Rate_Limiter::allow( 'contact-global', self::GLOBAL_LIMIT, DAY_IN_SECONDS )
			|| ! CC_Rate_Limiter::allow( 'contact-phone:' . $checked['data']['phone'], self::PHONE_LIMIT, DAY_IN_SECONDS ) ) {
			return self::error( 429, 'rate_limited', 'Too many messages. Please try again later or call us.' );
		}

		$id = CC_Inquiry_Repository::create( $checked['data'] );
		if ( is_wp_error( $id ) ) {
			return self::error( 500, 'server_error', 'Could not send your message. Please try again or call us.' );
		}
		return self::respond( array( 'ok' => true ) );
	}

	/**
	 * Turnstile verification behind the cc_verify_captcha filter. Fails closed on any transport or API problem.
	 *
	 * @param mixed $pass Result so far; a false from an earlier filter stays false.
	 */
	public static function verify_turnstile( $pass, WP_REST_Request $request ): bool {
		if ( true !== $pass ) {
			return false;
		}
		$secret = self::secret();
		if ( '' === $secret ) {
			if ( in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
				return true;
			}
			error_log( 'CC_Rest_Contact: TURNSTILE_SECRET is not configured; contact submissions are refused outside local/development.' );
			return false;
		}
		$token = trim( (string) $request->get_param( self::TURNSTILE_FIELD ) );
		if ( '' === $token ) {
			return false;
		}
		$response = wp_remote_post(
			self::TURNSTILE_URL,
			array(
				'timeout' => self::CAPTCHA_TIMEOUT,
				'body'    => array( 'secret' => $secret, 'response' => $token, 'remoteip' => CC_Rate_Limiter::client_ip() ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			error_log( 'CC_Rest_Contact: Turnstile verification request failed.' );
			return false;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) && true === ( $body['success'] ?? null );
	}

	/** Pure: whether an Origin/Referer header value points at $home_host. A header that is present but unparsable fails. */
	public static function host_matches( string $header_value, string $home_host ): bool {
		$host = parse_url( $header_value, PHP_URL_HOST );
		return is_string( $host ) && '' !== $home_host && 0 === strcasecmp( $host, $home_host );
	}

	/** Browsers always send Origin or Referer on a cross-site POST; clients that send neither (curl, server calls) pass. */
	private static function origin_allowed( WP_REST_Request $request ): bool {
		$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( array( 'origin', 'referer' ) as $header ) {
			$value = trim( (string) $request->get_header( $header ) );
			if ( '' !== $value && ! self::host_matches( $value, $home_host ) ) {
				return false;
			}
		}
		return true;
	}

	private static function secret(): string {
		if ( defined( 'TURNSTILE_SECRET' ) ) {
			return (string) TURNSTILE_SECRET;
		}
		return (string) getenv( 'TURNSTILE_SECRET' );
	}

	/** @return array<string,string> */
	private static function clean_input( WP_REST_Request $request ): array {
		$clean = array();
		foreach ( self::FIELDS as $name ) {
			$value         = (string) $request->get_param( $name );
			$clean[ $name ] = 'message' === $name ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
		}
		return $clean;
	}

	private static function respond( array $body, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $body, $status );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	private static function error( int $status, string $code, string $message, array $details = array() ): WP_REST_Response {
		$body = array( 'code' => $code, 'message' => $message );
		if ( $details ) {
			$body['details'] = $details;
		}
		return self::respond( $body, $status );
	}
}

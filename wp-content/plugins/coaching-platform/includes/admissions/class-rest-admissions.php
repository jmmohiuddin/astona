<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admission REST API (SDD §5): token, submit, retry payment, status.
 * Errors use the envelope {code, message, details?}.
 */
final class CC_Rest_Admissions {

	const HONEYPOT   = 'company_site';
	const REF_ROUTE  = '(?P<ref>[0-9A-HJKMNP-TV-Z]{26})';
	const GENDERS    = array( 'm', 'f', 'o' );
	const DOC_TYPES  = array( 'nid', 'birth_cert', 'passport' );
	const IP_RETRY_LIMIT  = 20;
	const IP_STATUS_LIMIT = 120;
	const PAYMENT_OPEN_SECONDS = 1800;
	const DOC_NUMBER = '/^[A-Za-z0-9-]{5,30}$/';
	const MSG_PHONE_PROOF = 'Verify your phone number first.';
	const MSG_IDEMPOTENCY_CONFLICT = 'This submission does not match the earlier one. Reload the page and submit again.';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$fields = array();
		foreach ( array( 'batch_id', 'full_name', 'gender', 'dob', 'id_doc_type', 'id_doc_number', 'student_phone', 'guardian_name', 'guardian_phone', 'email', 'institution', 'class_level', 'passing_year', 'roll_no', 'branch_pref', 'consent', 'phone_proof', self::HONEYPOT ) as $name ) {
			$fields[ $name ] = array( 'type' => 'string' );
		}

		register_rest_route(
			'cc/v1',
			'/forms/admission-token',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'token' ),
				'permission_callback' => '__return_true', // Public: anonymous applicants.
			)
		);
		register_rest_route(
			'cc/v1',
			'/applications',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'submit' ),
				'permission_callback' => '__return_true', // Public: anonymous applicants; guarded by idempotency key, honeypot, rate limit.
				'args'                => $fields,
			)
		);
		register_rest_route(
			'cc/v1',
			'/applications/' . self::REF_ROUTE . '/payment',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'retry_payment' ),
				'permission_callback' => '__return_true', // Public: possession of the unguessable ref; rate limited.
				'args'                => array( 'ref' => array( 'type' => 'string' ) ),
			)
		);
		register_rest_route(
			'cc/v1',
			'/applications/' . self::REF_ROUTE . '/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => '__return_true', // Public: minimal fields only; rate limited.
				'args'                => array( 'ref' => array( 'type' => 'string' ) ),
			)
		);
	}

	public static function token(): WP_REST_Response {
		return self::respond( array( 'idempotency_key' => wp_generate_uuid4(), 'honeypot' => self::HONEYPOT ) );
	}

	public static function submit( WP_REST_Request $request ): WP_REST_Response {
		$key = (string) $request->get_header( 'Idempotency-Key' );
		if ( ! CC_Idempotency::valid_key( $key ) ) {
			return self::error( 400, 'idempotency_key_required', 'A valid Idempotency-Key header is required.' );
		}

		$existing = CC_Application_Repository::find_by_idempotency_key( $key );
		if ( $existing ) {
			if ( ! self::same_submission( $existing, $request ) ) {
				return self::error( 409, 'idempotency_conflict', self::MSG_IDEMPOTENCY_CONFLICT );
			}
			return self::payment_response( CC_Application_Repository::find_by_ref( (string) $existing['public_ref'] ), 200 );
		}

		if ( '' !== trim( (string) $request->get_param( self::HONEYPOT ) ) || ! apply_filters( 'cc_verify_captcha', true, $request ) ) {
			return self::error( 400, 'bot_check_failed', 'Submission rejected.' );
		}
		if ( ! CC_Rate_Limiter::allow( 'apply:' . CC_Rate_Limiter::ip_bucket_key(), 5, 10 * MINUTE_IN_SECONDS ) ) {
			return self::error( 429, 'rate_limited', 'Too many attempts. Please try again later.' );
		}

		$checked = self::validate( $request );
		if ( isset( $checked['errors'] ) ) {
			return self::error( 422, 'validation_failed', 'Please correct the highlighted fields.', $checked['errors'] );
		}
		$data  = $checked['data'];
		$batch = $checked['batch'];
		$proof = $checked['proof'];

		$photo = CC_Photo_Store::store( (array) ( $request->get_file_params()['photo'] ?? array() ), '' );
		if ( is_wp_error( $photo ) ) {
			if ( 500 === (int) ( $photo->get_error_data()['status'] ?? 0 ) ) {
				return self::error( 500, 'server_error', 'Could not process the application.' );
			}
			return self::error( 422, 'validation_failed', 'Please correct the highlighted fields.', array( 'photo' => $photo->get_error_message() ) );
		}

		try {
			$data['id_doc_enc'] = CC_Crypto::encrypt( $checked['id_doc_number'] );
		} catch ( RuntimeException $e ) {
			CC_Photo_Store::delete( $photo );
			return self::error( 500, 'server_error', 'Could not process the application.' );
		}
		$data['photo_path']        = $photo;
		$data['batch_id']          = (int) $batch['id'];
		$data['phone_verified_at'] = gmdate( 'Y-m-d H:i:s' );

		// Claim the proof only now that everything else is valid; it is handed back below if no application results.
		if ( ! CC_Phone_Proof::claim( $proof ) ) {
			CC_Photo_Store::delete( $photo );
			return self::error( 422, 'validation_failed', 'Please correct the highlighted fields.', array( 'phone_proof' => self::MSG_PHONE_PROOF ) );
		}
		return self::finish_create( CC_Application_Repository::create( $data, $key ), $proof, $photo, $key, $request );
	}

	/**
	 * Decides what happens to the claimed proof and the stored photo once create() has answered. They are given back only
	 * when no application can exist for $key: a COMMIT that reported failure may still have landed, and releasing the proof
	 * then would let it be spent on a second application. In that case the client's retry replays the stored one.
	 *
	 * @param array|WP_Error $created
	 */
	private static function finish_create( $created, array $proof, string $photo, string $key, WP_REST_Request $request ): WP_REST_Response {
		if ( is_wp_error( $created ) ) {
			$status = (int) ( $created->get_error_data()['status'] ?? 500 );
			if ( 500 === $status && null !== CC_Application_Repository::find_by_idempotency_key( $key ) ) {
				return self::error( 500, $created->get_error_code(), $created->get_error_message() );
			}
			CC_Phone_Proof::release( $proof );
			CC_Photo_Store::delete( $photo );
			return self::error( $status, $created->get_error_code(), $created->get_error_message() );
		}
		if ( $created['replayed'] ) {
			CC_Phone_Proof::release( $proof );
			CC_Photo_Store::delete( $photo );
			if ( ! self::same_submission( $created, $request ) ) {
				return self::error( 409, 'idempotency_conflict', self::MSG_IDEMPOTENCY_CONFLICT );
			}
		}
		return self::payment_response( $created, $created['replayed'] ? 200 : 201 );
	}

	/** A replay under a known Idempotency-Key must carry the same student phone and batch as the application it created. */
	private static function same_submission( array $application, WP_REST_Request $request ): bool {
		$phone = CC_Phone::normalize( trim( sanitize_text_field( (string) $request->get_param( 'student_phone' ) ) ) );
		return null !== $phone
			&& hash_equals( (string) $application['student_phone'], $phone )
			&& (string) (int) $application['batch_id'] === trim( (string) $request->get_param( 'batch_id' ) );
	}

	public static function retry_payment( WP_REST_Request $request ): WP_REST_Response {
		$ref         = (string) $request['ref'];
		$application = CC_Application_Repository::find_by_ref( $ref );
		$invoice     = $application ? CC_Application_Repository::find_invoice( (int) $application['id'] ) : null;
		if ( ! $invoice ) {
			return self::error( 404, 'not_found', 'Application not found.' );
		}
		if ( ! CC_Rate_Limiter::allow( 'retry-ip:' . CC_Rate_Limiter::ip_bucket_key(), self::IP_RETRY_LIMIT, HOUR_IN_SECONDS )
			|| ! CC_Rate_Limiter::allow( 'retry:' . $ref, 5, HOUR_IN_SECONDS ) ) {
			return self::error( 429, 'rate_limited', 'Too many attempts. Please try again later.' );
		}
		if ( 'unpaid' !== $invoice['status'] || 'pending' !== $application['status'] ) {
			return self::error( 409, 'not_payable', 'This application cannot be paid again.' );
		}

		// Serialise concurrent retries for one invoice so two requests cannot both pass the checks below.
		global $wpdb;
		$lock_name = 'cc_retry_' . (int) $invoice['id'];
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) ) ) {
			return self::error( 409, 'payment_in_progress', 'A payment is already being processed. Please wait a moment and check again.' );
		}
		try {
			$blocked = self::retry_blocker( $application, $invoice );
			return $blocked ?? self::start_payment( $application, $invoice, 200 );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/** A 409/503 response when a new payment must not be started, or null when it is safe. */
	private static function retry_blocker( array $application, array $invoice ): ?WP_REST_Response {
		$review = self::error( 409, 'payment_needs_review', 'Payment needs review. Please contact us.' );
		$busy   = self::error( 409, 'payment_in_progress', 'A payment is still in progress. Please wait a few minutes and check again.' );

		foreach ( CC_Application_Repository::payments_for_invoice( (int) $invoice['id'] ) as $payment ) {
			if ( in_array( $payment['status'], array( 'completed', 'reconcile_needed', 'refunded' ), true ) ) {
				return $review;
			}
			if ( ! in_array( $payment['status'], array( 'initiated', 'executing' ), true ) ) {
				continue;
			}
			try {
				$settled = CC_Settlement::settle( (int) $payment['id'], 'callback' );
			} catch ( Throwable $e ) {
				return self::error( 503, 'gateway_unavailable', 'Payment is temporarily unavailable. Please try again shortly.' );
			}
			if ( 'settled' === $settled['result'] ) {
				return self::error( 409, 'already_paid', 'Your payment has been received.' );
			}
			if ( 'not_completed' !== $settled['result'] ) {
				return $review;
			}
			$fresh = CC_Application_Repository::find_payment( (int) $payment['id'] );
			$age   = time() - (int) strtotime( (string) $payment['created_at'] . ' UTC' );
			if ( $fresh && in_array( $fresh['status'], array( 'initiated', 'executing' ), true ) && $age < self::PAYMENT_OPEN_SECONDS ) {
				return $busy;
			}
		}

		$batch = CC_Batch_Repository::find( (int) $application['batch_id'] );
		if ( ! $batch || (int) $batch['seats_taken'] >= (int) $batch['capacity'] ) {
			return self::error( 409, 'batch_full', 'No seats are left in this batch. Please contact us.' );
		}
		return null;
	}

	public static function status( WP_REST_Request $request ): WP_REST_Response {
		$ref  = (string) $request['ref'];
		$view = CC_Application_Repository::status_view( $ref );
		if ( ! $view ) {
			return self::error( 404, 'not_found', 'Application not found.' );
		}
		$ip = CC_Rate_Limiter::ip_bucket_key();
		if ( ! CC_Rate_Limiter::allow( 'status-ip:' . $ip, self::IP_STATUS_LIMIT, MINUTE_IN_SECONDS )
			|| ! CC_Rate_Limiter::allow( 'status:' . $ip . ':' . $ref, 30, MINUTE_IN_SECONDS ) ) {
			return self::error( 429, 'rate_limited', 'Too many requests.' );
		}
		return self::respond( array( 'status' => $view['status'], 'payment_status' => $view['payment_status'] ) );
	}

	/** Replays hand back the existing open payment's redirect; new applications start one. */
	private static function payment_response( array $application, int $status ): WP_REST_Response {
		$invoice = CC_Application_Repository::find_invoice( (int) $application['id'] );
		if ( 200 === $status ) {
			$payment  = $invoice ? CC_Application_Repository::latest_payment( (int) $invoice['id'] ) : null;
			$stored   = $payment ? json_decode( (string) $payment['response_json'], true ) : null;
			$redirect = is_array( $stored ) && 'initiated' === $payment['status'] ? (string) ( $stored['redirect_url'] ?? '' ) : '';
			if ( '' === $redirect ) {
				$redirect = add_query_arg( 'ref', $application['public_ref'], home_url( '/admissions/' ) );
			}
			return self::respond( array( 'ref' => $application['public_ref'], 'redirect_url' => $redirect ), 200 );
		}
		return self::start_payment( $application, $invoice, $status );
	}

	private static function start_payment( array $application, array $invoice, int $status ): WP_REST_Response {
		try {
			$gateway = CC_Gateway_Factory::make();
		} catch ( Throwable $e ) {
			return self::error( 503, 'gateway_unavailable', 'Payment is temporarily unavailable. Please try again shortly.' );
		}

		$payment_id = CC_Application_Repository::insert_payment( $invoice, $gateway->id() );
		try {
			$result = $gateway->create_payment(
				array(
					'id'              => (int) $invoice['id'],
					'number'          => $invoice['number'],
					'amount'          => (float) $invoice['amount'],
					'currency'        => $invoice['currency'],
					'application_ref' => $application['public_ref'],
					'payment_id'      => $payment_id,
				),
				rest_url( 'cc/v1/payments/callback' )
			);
		} catch ( Throwable $e ) {
			CC_Application_Repository::mark_payment_failed( $payment_id );
			return self::error( 503, 'gateway_unavailable', 'Payment is temporarily unavailable. Please try again shortly.' );
		}

		list( $gateway_payment_id, $redirect_url ) = array_values( $result );
		CC_Application_Repository::attach_gateway_payment( $payment_id, (string) $gateway_payment_id, (string) $redirect_url );
		return self::respond( array( 'ref' => $application['public_ref'], 'redirect_url' => $redirect_url ), $status );
	}

	/**
	 * @return array{errors:array<string,string>}|array{data:array,batch:array,id_doc_number:string,proof:array}
	 */
	private static function validate( WP_REST_Request $request ): array {
		$errors = array();
		$text   = static fn( string $name ): string => trim( sanitize_text_field( (string) $request->get_param( $name ) ) );
		$length = static fn( string $v, int $min, int $max ): bool => mb_strlen( $v ) >= $min && mb_strlen( $v ) <= $max;

		$batch_raw = (string) $request->get_param( 'batch_id' );
		$batch     = ctype_digit( $batch_raw ) ? CC_Batch_Repository::find( (int) $batch_raw ) : null;
		if ( ! $batch || 'draft' === $batch['status'] ) {
			$errors['batch_id'] = 'Batch not found.';
		} elseif ( CC_Status_Chip::CLOSED === CC_Status_Chip::for_batch( (string) $batch['status'], (bool) $batch['application_open'], (int) $batch['capacity'], (int) $batch['seats_taken'] ) ) {
			$errors['batch_id'] = 'This batch is not accepting applications.';
		}

		$full_name = $text( 'full_name' );
		if ( ! $length( $full_name, 2, 190 ) ) {
			$errors['full_name'] = 'Enter the full name (2 to 190 characters).';
		}
		$gender = $text( 'gender' );
		if ( ! in_array( $gender, self::GENDERS, true ) ) {
			$errors['gender'] = 'Select a gender.';
		}
		$dob = $text( 'dob' );
		if ( ! self::valid_dob( $dob ) ) {
			$errors['dob'] = 'Enter a valid date of birth.';
		}
		$doc_type = $text( 'id_doc_type' );
		if ( ! in_array( $doc_type, self::DOC_TYPES, true ) ) {
			$errors['id_doc_type'] = 'Select an ID document type.';
		}
		$doc_number = $text( 'id_doc_number' );
		if ( ! preg_match( self::DOC_NUMBER, $doc_number ) ) {
			$errors['id_doc_number'] = 'Enter a valid ID number (5 to 30 letters, digits or dashes).';
		}
		$student_phone = CC_Phone::normalize( $text( 'student_phone' ) );
		if ( null === $student_phone ) {
			$errors['student_phone'] = 'Enter a valid Bangladeshi mobile number.';
		}
		// Only a phone the applicant proved they control may be attached to an application (and later to an account).
		$proof = null === $student_phone ? null : CC_Phone_Proof::verify( (string) $request->get_param( 'phone_proof' ), $student_phone );
		if ( null === $proof && null !== $student_phone ) {
			$errors['phone_proof'] = self::MSG_PHONE_PROOF;
		}
		$guardian_name = $text( 'guardian_name' );
		if ( ! $length( $guardian_name, 2, 190 ) ) {
			$errors['guardian_name'] = 'Enter the guardian name (2 to 190 characters).';
		}
		$guardian_phone = CC_Phone::normalize( $text( 'guardian_phone' ) );
		if ( null === $guardian_phone ) {
			$errors['guardian_phone'] = 'Enter a valid Bangladeshi mobile number.';
		}
		$email = $text( 'email' );
		if ( '' !== $email && ( ! is_email( $email ) || mb_strlen( $email ) > 190 ) ) {
			$errors['email'] = 'Enter a valid email address.';
		}
		$institution = $text( 'institution' );
		if ( ! $length( $institution, 2, 190 ) ) {
			$errors['institution'] = 'Enter the institution (2 to 190 characters).';
		}
		$class_level = $text( 'class_level' );
		if ( ! $length( $class_level, 1, 60 ) ) {
			$errors['class_level'] = 'Enter the class or level (up to 60 characters).';
		}
		$year_raw = $text( 'passing_year' );
		if ( '' !== $year_raw && ( ! ctype_digit( $year_raw ) || (int) $year_raw < 1980 || (int) $year_raw > (int) gmdate( 'Y' ) + 1 ) ) {
			$errors['passing_year'] = 'Enter a valid year.';
		}
		$roll_no = $text( 'roll_no' );
		if ( mb_strlen( $roll_no ) > 40 ) {
			$errors['roll_no'] = 'Roll number is too long.';
		}
		$branch_raw = $text( 'branch_pref' );
		if ( '' !== $branch_raw && ! ctype_digit( $branch_raw ) ) {
			$errors['branch_pref'] = 'Invalid branch.';
		}
		if ( ! in_array( strtolower( $text( 'consent' ) ), array( '1', 'on', 'true', 'yes' ), true ) ) {
			$errors['consent'] = 'You must agree to continue.';
		}

		if ( $errors ) {
			return array( 'errors' => $errors );
		}

		return array(
			'batch'         => $batch,
			'id_doc_number' => $doc_number,
			'proof'         => $proof,
			'data'          => array(
				'public_ref'     => CC_Application_Repository::ulid(),
				'student_phone'  => $student_phone,
				'full_name'      => $full_name,
				'gender'         => $gender,
				'dob'            => $dob,
				'id_doc_type'    => $doc_type,
				'guardian_name'  => $guardian_name,
				'guardian_phone' => $guardian_phone,
				'email'          => '' === $email ? null : $email,
				'institution'    => $institution,
				'class_level'    => $class_level,
				'passing_year'   => '' === $year_raw ? null : (int) $year_raw,
				'roll_no'        => '' === $roll_no ? null : $roll_no,
				'branch_pref'    => '' === $branch_raw ? null : (int) $branch_raw,
				'consent_at'     => gmdate( 'Y-m-d H:i:s' ),
			),
		);
	}

	private static function valid_dob( string $dob ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $dob, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return false;
		}
		$today = gmdate( 'Y-m-d' );
		return $dob <= $today && (int) $m[1] >= (int) gmdate( 'Y' ) - 100;
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

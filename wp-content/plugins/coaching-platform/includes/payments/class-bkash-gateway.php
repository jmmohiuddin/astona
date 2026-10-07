<?php
defined( 'ABSPATH' ) || exit;

/**
 * bKash Tokenized Checkout (hosted, PCI-scoped) behind CC_Payment_Gateway (TRD FR-003, SDD 5.4).
 *
 * Flow: grant token (cached) -> create payment (returns paymentID + bkashURL) -> payer returns to the callback route
 * with paymentID -> execute -> query. The report handed to CC_Settlement always comes from query(); nothing the payer's
 * browser sends is trusted. Never retries execute() blindly: a failed or ambiguous execute falls back to query().
 *
 * Secrets come from constants or the environment only (BKASH_APP_KEY, BKASH_APP_SECRET, BKASH_USERNAME,
 * BKASH_PASSWORD, BKASH_MODE=sandbox|live). Nothing is stored in wp_options and tokens are never logged.
 * Endpoint paths and field names follow bKash's published tokenized-checkout API and must be confirmed against the
 * merchant sandbox before launch (TRD C-04 / OQ-05).
 */
final class CC_Bkash_Gateway implements CC_Payment_Gateway {

	const BASE_URLS = array(
		'sandbox' => 'https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout',
		'live'    => 'https://tokenized.pay.bka.sh/v1.2.0-beta/tokenized/checkout',
	);

	/** Short on purpose: query() runs inside settle() while row locks are held (see CC_Settlement). */
	const HTTP_TIMEOUT = 5;

	const TOKEN_TRANSIENT = 'cc_bkash_id_token';
	const TOKEN_SAFETY    = 60;

	const SUCCESS_CODE = '0000';
	/** bKash codes meaning "id_token missing, invalid or expired". */
	const BAD_TOKEN_CODES = array( '2001', '2002', '2003', '2079' );

	/** @var callable(string $url, array $headers, array $body): array{0:int,1:array} [http status, decoded JSON body] */
	private $http;
	private string $mode;
	/** @var array{app_key:string,app_secret:string,username:string,password:string} */
	private array $credentials;
	/** @var callable():int Seam for tests. */
	private $clock;
	/** In-process copy of the token for a CLI/test run without a persistent cache. */
	private ?array $token = null;

	/**
	 * @param array|null    $config Test seam; null reads constants/environment. Keys: mode, app_key, app_secret, username, password.
	 * @param callable|null $http   Test seam; null uses wp_remote_post.
	 * @throws RuntimeException When a credential or the mode is missing.
	 */
	public function __construct( ?array $config = null, ?callable $http = null, ?callable $clock = null ) {
		$config = $config ?? array(
			'mode'       => self::setting( 'BKASH_MODE' ),
			'app_key'    => self::setting( 'BKASH_APP_KEY' ),
			'app_secret' => self::setting( 'BKASH_APP_SECRET' ),
			'username'   => self::setting( 'BKASH_USERNAME' ),
			'password'   => self::setting( 'BKASH_PASSWORD' ),
		);
		$mode   = strtolower( trim( (string) ( $config['mode'] ?? '' ) ) );
		if ( ! isset( self::BASE_URLS[ $mode ] ) ) {
			throw new RuntimeException( 'BKASH_MODE must be "sandbox" or "live".' );
		}
		foreach ( array( 'app_key', 'app_secret', 'username', 'password' ) as $key ) {
			if ( '' === trim( (string) ( $config[ $key ] ?? '' ) ) ) {
				throw new RuntimeException( 'bKash credentials are not configured.' );
			}
		}
		$this->mode        = $mode;
		$this->credentials = array(
			'app_key'    => (string) $config['app_key'],
			'app_secret' => (string) $config['app_secret'],
			'username'   => (string) $config['username'],
			'password'   => (string) $config['password'],
		);
		$this->http        = $http ?? array( $this, 'wp_http' );
		$this->clock       = $clock ?? 'time';
	}

	public function id(): string {
		return 'bkash';
	}

	public function create_payment( array $invoice, string $callback_url ): array {
		$body = array(
			'mode'                  => '0011',
			'payerReference'        => (string) $invoice['number'],
			'callbackURL'           => $callback_url,
			'amount'                => number_format( (float) $invoice['amount'], 2, '.', '' ),
			'currency'              => (string) ( $invoice['currency'] ?? 'BDT' ),
			'intent'                => 'sale',
			'merchantInvoiceNumber' => (string) $invoice['number'],
		);
		$res  = $this->call( 'create', $body, true );
		$pid  = (string) ( $res['paymentID'] ?? '' );
		$url  = (string) ( $res['bkashURL'] ?? '' );
		if ( self::SUCCESS_CODE !== (string) ( $res['statusCode'] ?? '' ) || '' === $pid || '' === $url ) {
			throw new RuntimeException( 'bKash create payment failed: ' . self::describe( $res ) );
		}
		if ( 'https' !== strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) ) ) {
			throw new RuntimeException( 'bKash returned a non-HTTPS checkout URL.' );
		}
		return array( $pid, $url );
	}

	public function execute( string $gateway_payment_id ): array {
		// Not retried on a network error: if the first call reached bKash the money may have moved. query() decides.
		try {
			$res = $this->call( 'execute', array( 'paymentID' => $gateway_payment_id ), false );
		} catch ( RuntimeException $e ) {
			return $this->query( $gateway_payment_id );
		}
		$code = (string) ( $res['statusCode'] ?? '' );
		if ( self::SUCCESS_CODE === $code ) {
			return self::normalise( $res );
		}
		// Declined, duplicate, timed out on bKash's side: the authoritative answer is the query.
		return $this->query( $gateway_payment_id );
	}

	public function query( string $gateway_payment_id ): array {
		$res = $this->call( 'payment/status', array( 'paymentID' => $gateway_payment_id ), true );
		return self::normalise( $res );
	}

	/**
	 * Maps a bKash response to the gateway-neutral report. Anything unrecognised is "pending": the money is never
	 * considered received on an ambiguous answer, and the reconciler cancels stale payments by age.
	 *
	 * @return array{status:string,trx_id:string,amount:float,invoice:string,currency:string,raw:array}
	 */
	public static function normalise( array $res ): array {
		$code   = (string) ( $res['statusCode'] ?? '' );
		$tx     = strtolower( (string) ( $res['transactionStatus'] ?? '' ) );
		$status = 'pending';
		if ( self::SUCCESS_CODE === $code && 'completed' === $tx && '' !== (string) ( $res['trxID'] ?? '' ) ) {
			$status = 'completed';
		} elseif ( in_array( $tx, array( 'failed', 'declined', 'expired' ), true ) ) {
			$status = 'failed';
		} elseif ( in_array( $tx, array( 'cancelled', 'canceled' ), true ) ) {
			$status = 'cancelled';
		}
		return array(
			'status'   => $status,
			'trx_id'   => (string) ( $res['trxID'] ?? '' ),
			'amount'   => (float) ( $res['amount'] ?? 0 ),
			'invoice'  => (string) ( $res['merchantInvoiceNumber'] ?? '' ),
			'currency' => (string) ( $res['currency'] ?? '' ),
			'raw'      => array_intersect_key(
				$res,
				array_flip( array( 'statusCode', 'statusMessage', 'transactionStatus', 'trxID', 'paymentID', 'amount', 'currency', 'merchantInvoiceNumber', 'paymentExecuteTime', 'customerMsisdn' ) )
			),
		);
	}

	/**
	 * One authenticated POST. Refreshes the token once when bKash says it is invalid and retries a transport failure
	 * once when $retry_network is set.
	 *
	 * @return array Decoded JSON body.
	 * @throws RuntimeException On transport failure, non-JSON or HTTP 5xx.
	 */
	private function call( string $endpoint, array $body, bool $retry_network ): array {
		$refreshed = false;
		$attempts  = $retry_network ? 2 : 1;
		for ( $try = 1; ; $try++ ) {
			$headers = array(
				'Authorization' => $this->id_token( $refreshed ),
				'X-APP-Key'     => $this->credentials['app_key'],
			);
			try {
				list( $code, $res ) = ( $this->http )( self::BASE_URLS[ $this->mode ] . '/' . $endpoint, $headers, $body );
			} catch ( RuntimeException $e ) {
				if ( $try < $attempts ) {
					continue;
				}
				throw $e;
			}
			if ( $code >= 500 ) {
				if ( $try < $attempts ) {
					continue;
				}
				throw new RuntimeException( sprintf( 'bKash %s returned HTTP %d.', $endpoint, $code ) );
			}
			if ( ! $refreshed && ( 401 === $code || in_array( (string) ( $res['statusCode'] ?? '' ), self::BAD_TOKEN_CODES, true ) ) ) {
				$this->forget_token();
				$refreshed = true;
				$try--;
				continue;
			}
			return $res;
		}
	}

	/** @throws RuntimeException When bKash refuses the credentials. */
	private function id_token( bool $force_new ): string {
		$now = ( $this->clock )();
		if ( ! $force_new ) {
			$cached = $this->token ?? get_transient( self::TOKEN_TRANSIENT );
			if ( is_array( $cached ) && isset( $cached['token'], $cached['expires'] ) && (int) $cached['expires'] > $now ) {
				return (string) $cached['token'];
			}
		}
		list( , $res ) = ( $this->http )(
			self::BASE_URLS[ $this->mode ] . '/token/grant',
			array(
				'username' => $this->credentials['username'],
				'password' => $this->credentials['password'],
			),
			array(
				'app_key'    => $this->credentials['app_key'],
				'app_secret' => $this->credentials['app_secret'],
			)
		);
		$token = (string) ( $res['id_token'] ?? '' );
		if ( '' === $token ) {
			throw new RuntimeException( 'bKash token grant failed: ' . self::describe( $res ) );
		}
		$ttl         = max( 60, (int) ( $res['expires_in'] ?? 3600 ) - self::TOKEN_SAFETY );
		$this->token = array( 'token' => $token, 'expires' => $now + $ttl );
		set_transient( self::TOKEN_TRANSIENT, $this->token, $ttl );
		return $token;
	}

	private function forget_token(): void {
		$this->token = null;
		delete_transient( self::TOKEN_TRANSIENT );
	}

	/** Status code and message only; never the whole body (it can carry tokens). */
	private static function describe( array $res ): string {
		return trim( (string) ( $res['statusCode'] ?? 'no-code' ) . ' ' . substr( (string) ( $res['statusMessage'] ?? '' ), 0, 120 ) );
	}

	/** @return array{0:int,1:array} */
	private function wp_http( string $url, array $headers, array $body ): array {
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => self::HTTP_TIMEOUT,
				'redirection' => 0,
				'headers'     => array_merge( array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ), $headers ),
				'body'        => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'bKash transport error: ' . $response->get_error_code() );
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return array( (int) wp_remote_retrieve_response_code( $response ), is_array( $decoded ) ? $decoded : array() );
	}

	private static function setting( string $name ): string {
		return defined( $name ) ? (string) constant( $name ) : (string) getenv( $name );
	}
}

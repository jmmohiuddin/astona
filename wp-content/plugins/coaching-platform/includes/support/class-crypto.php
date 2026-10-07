<?php
defined( 'ABSPATH' ) || exit;

/**
 * Authenticated encryption (libsodium secretbox) for sensitive columns such as ID document numbers.
 * Blob format: "k1:" . base64( nonce . ciphertext ). The "k1" prefix leaves room for key rotation.
 */
final class CC_Crypto {

	const PREFIX = 'k1:';

	public static function encrypt( string $plain ): string {
		$key   = self::key();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$blob  = self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) );
		sodium_memzero( $key );
		return $blob;
	}

	/**
	 * @throws RuntimeException When the blob is malformed, tampered with, or the key is wrong/missing.
	 */
	public static function decrypt( string $blob ): string {
		if ( 0 !== strpos( $blob, self::PREFIX ) ) {
			throw new RuntimeException( 'Unsupported ciphertext format.' );
		}
		$raw = base64_decode( substr( $blob, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			throw new RuntimeException( 'Malformed ciphertext.' );
		}
		$key   = self::key();
		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$key
		);
		sodium_memzero( $key );
		if ( false === $plain ) {
			throw new RuntimeException( 'Decryption failed.' );
		}
		return $plain;
	}

	private static function key(): string {
		$configured = defined( 'CC_ENC_KEY' ) ? (string) CC_ENC_KEY : (string) getenv( 'CC_ENC_KEY' );
		if ( '' !== $configured ) {
			$key = base64_decode( $configured, true );
			if ( false === $key || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $key ) ) {
				throw new RuntimeException( 'CC_ENC_KEY must be base64 of 32 bytes.' );
			}
			return $key;
		}
		if ( 'local' !== wp_get_environment_type() ) {
			throw new RuntimeException( 'CC_ENC_KEY is not configured.' );
		}
		return hash( 'sha256', 'cc-dev-key|' . wp_salt( 'auth' ), true );
	}
}

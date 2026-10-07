<?php
defined( 'ABSPATH' ) || exit;

final class CC_Sms_Factory {

	/** @var array<string,callable():CC_Sms_Driver> Real drivers plug in here by name. */
	private static array $drivers = array();

	/** @var array<string,?string> Test seam: per-role name overrides; absent key means read const/env. */
	private static array $name_overrides = array();

	public static function register_driver( string $name, callable $maker ): void {
		self::$drivers[ strtolower( $name ) ] = $maker;
	}

	/** Test seam: pass null to fall back to const/env for that role. */
	public static function override_name( string $role, ?string $name ): void {
		if ( null === $name ) {
			unset( self::$name_overrides[ $role ] );
			return;
		}
		self::$name_overrides[ $role ] = $name;
	}

	/** Configured driver name for 'primary' or 'fallback' (override, constant, then env), lower-cased; '' when unset. */
	public static function configured_name( string $role ): string {
		$key = 'CC_SMS_' . strtoupper( $role );
		if ( array_key_exists( $role, self::$name_overrides ) ) {
			$name = (string) self::$name_overrides[ $role ];
		} else {
			$name = defined( $key ) ? (string) constant( $key ) : (string) getenv( $key );
		}
		return strtolower( trim( $name ) );
	}

	/** @throws RuntimeException When CC_SMS_PRIMARY is unset, unknown, or the fake driver is not allowed here. */
	public static function primary(): CC_Sms_Driver {
		$name = self::configured_name( 'primary' );
		if ( '' === $name ) {
			throw new RuntimeException( 'CC_SMS_PRIMARY is not configured; set it explicitly (e.g. "fake" on local/development only).' );
		}
		return self::make( $name );
	}

	/**
	 * @return CC_Sms_Driver|null Null when CC_SMS_FALLBACK is unset.
	 * @throws RuntimeException When the configured fallback is unknown or not allowed here.
	 */
	public static function fallback(): ?CC_Sms_Driver {
		$name = self::configured_name( 'fallback' );
		return '' === $name ? null : self::make( $name );
	}

	private static function make( string $name ): CC_Sms_Driver {
		if ( isset( self::$drivers[ $name ] ) ) {
			return ( self::$drivers[ $name ] )();
		}
		if ( 'fake' === $name ) {
			return new CC_Sms_Fake_Driver();
		}
		if ( 'bulksmsbd' === $name ) {
			return new CC_Sms_Bulksmsbd_Driver();
		}
		if ( 'greenweb' === $name ) {
			return new CC_Sms_Greenweb_Driver();
		}
		throw new RuntimeException( sprintf( 'Unknown SMS driver "%s".', $name ) );
	}
}

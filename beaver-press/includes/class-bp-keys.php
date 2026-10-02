<?php
/**
 * API keys, one per provider, entered in wp-admin and stored encrypted.
 *
 * Encrypted with libsodium (WordPress ships a polyfill) under a key derived from this
 * site's salts, so a database copy alone does not reveal them. If the salts change, the
 * saved keys cannot be read any more and must be entered again. A wp-config.php constant
 * (BEAVER_PRESS_<PROVIDER>_API_KEY, then BEAVER_PRESS_API_KEY) always wins over a saved key.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Encrypted per-provider key store.
 */
final class BP_Keys {

	/** Option holding the encrypted keys (not autoloaded). */
	const OPTION = 'beaver_press_keys';

	/**
	 * Key for a provider, or '' when none is set.
	 *
	 * @param string $provider Provider slug (claude, openai, deepseek, gemini, deepl, custom).
	 * @return string
	 */
	public static function get( $provider ) {
		$constant = self::constant_name( $provider );
		if ( '' !== $constant ) {
			return (string) constant( $constant );
		}
		$stored = self::stored();
		if ( empty( $stored[ $provider ] ) ) {
			return '';
		}
		return self::decrypt( (string) $stored[ $provider ] );
	}

	/**
	 * Where the key comes from: 'constant', 'saved' or ''.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	public static function source( $provider ) {
		if ( '' !== self::constant_name( $provider ) ) {
			return 'constant';
		}
		return '' !== self::get( $provider ) ? 'saved' : '';
	}

	/**
	 * Last four characters, for "saved, ending ...abcd". Never the key itself.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	public static function hint( $provider ) {
		$key = self::get( $provider );
		return strlen( $key ) >= 8 ? substr( $key, -4 ) : '';
	}

	/**
	 * Save (encrypt) a key. Empty input is ignored so a blank field keeps the saved key.
	 *
	 * @param string $provider Provider slug.
	 * @param string $key      Plain key.
	 * @return bool Whether something was saved.
	 */
	public static function set( $provider, $key ) {
		$key = trim( (string) $key );
		if ( '' === $key || '' === (string) $provider ) {
			return false;
		}
		$stored              = self::stored();
		$stored[ $provider ] = self::encrypt( $key );
		return update_option( self::OPTION, $stored, false );
	}

	/**
	 * Forget a saved key.
	 *
	 * @param string $provider Provider slug.
	 */
	public static function delete( $provider ) {
		$stored = self::stored();
		unset( $stored[ $provider ] );
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Name of the wp-config constant that overrides this provider's key, or ''.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	private static function constant_name( $provider ) {
		$specific = 'BEAVER_PRESS_' . strtoupper( preg_replace( '/[^a-z0-9]/i', '_', (string) $provider ) ) . '_API_KEY';
		foreach ( array( $specific, 'BEAVER_PRESS_API_KEY' ) as $name ) {
			if ( defined( $name ) && '' !== trim( (string) constant( $name ) ) ) {
				return $name;
			}
		}
		return '';
	}

	/**
	 * Encrypted keys as saved.
	 *
	 * @return array<string, string>
	 */
	private static function stored() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * 32-byte secret from this site's salts.
	 *
	 * @return string
	 */
	private static function secret() {
		return sodium_crypto_generichash( 'beaver-press|' . wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Encrypt to base64(nonce . ciphertext).
	 *
	 * @param string $plain Plain text.
	 * @return string
	 */
	private static function encrypt( $plain ) {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::secret() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary storage.
	}

	/**
	 * Decrypt; '' when the value cannot be read (e.g. the salts changed).
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	private static function decrypt( $stored ) {
		$raw = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary storage.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			self::secret()
		);
		return false === $plain ? '' : $plain;
	}
}

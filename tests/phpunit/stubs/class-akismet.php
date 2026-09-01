<?php
/**
 * A recording stand-in for the Akismet plugin.
 *
 * Defines the two static methods `includes/spam.php` touches — `get_api_key()`
 * and `http_post()` — so every request the plugin would make to Akismet can be
 * counted without the Akismet plugin installed and without a byte leaving the
 * test run. The tests reset it between cases.
 *
 * Guarded so a test environment that does have Akismet loaded is left alone.
 *
 * @package AllTerrain_Forms
 */

if ( ! class_exists( 'Akismet' ) ) {
	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Stands in for Akismet's own class, so it has to carry Akismet's name.

	/**
	 * The recorder.
	 */
	class Akismet {

		/**
		 * What `get_api_key()` answers. Empty means "not configured".
		 *
		 * @var string
		 */
		public static $api_key = '';

		/**
		 * What `http_post()` answers as the response body.
		 *
		 * @var string
		 */
		public static $body = 'false';

		/**
		 * Every http_post call, as [path, parsed request].
		 *
		 * @var array<int, array{path: string, request: array}>
		 */
		public static $calls = array();

		/**
		 * Back to a blank slate: no key, nothing recorded.
		 *
		 * @return void
		 */
		public static function reset() {
			self::$api_key = '';
			self::$body    = 'false';
			self::$calls   = array();
		}

		/**
		 * Mirrors `Akismet::get_api_key()`.
		 *
		 * @return string
		 */
		public static function get_api_key() {
			return self::$api_key;
		}

		/**
		 * Mirrors `Akismet::http_post()`, recording instead of sending.
		 *
		 * @param string      $request The URL-encoded request body.
		 * @param string      $path    `comment-check`, `submit-spam` or `submit-ham`.
		 * @param string|null $ip      Unused; part of the real signature.
		 * @return array { 0: array headers, 1: string body }
		 */
		public static function http_post( $request, $path, $ip = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Mirrors the real signature.
			$parsed = array();
			parse_str( (string) $request, $parsed );

			self::$calls[] = array(
				'path'    => $path,
				'request' => $parsed,
			);

			return array( array(), self::$body );
		}
	}

	// phpcs:enable
}

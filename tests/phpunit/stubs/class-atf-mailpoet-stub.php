<?php
/**
 * A recording stand-in for MailPoet's public API.
 *
 * Defines the two symbols `includes/mailpoet.php` touches — the namespaced
 * `\MailPoet\API\API` gate and the instance behind `MP( 'v1' )` — so the
 * bridge can be exercised without MailPoet installed. Everything recorded and
 * every throwable behaviour is driven through `ATF_MailPoet_Stub`, which the
 * tests reset between cases.
 *
 * @package AllTerrain_Forms
 */

/**
 * The recorder the namespaced facade forwards to.
 */
class ATF_MailPoet_Stub {

	/**
	 * What `getLists()` answers.
	 *
	 * @var array<int, array>
	 */
	public static $lists = array();

	/**
	 * Every addSubscriber call.
	 *
	 * @var array<int, array{subscriber: array, lists: array}>
	 */
	public static $added = array();

	/**
	 * Every subscribeToLists call, as [email, lists].
	 *
	 * @var array<int, array>
	 */
	public static $subscribed = array();

	/**
	 * When non-empty, addSubscriber throws with this message.
	 *
	 * @var string
	 */
	public static $add_throws = '';

	/**
	 * When non-empty, subscribeToLists throws with this message.
	 *
	 * @var string
	 */
	public static $subscribe_throws = '';

	/**
	 * Back to a blank slate.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$lists            = array();
		self::$added            = array();
		self::$subscribed       = array();
		self::$add_throws       = '';
		self::$subscribe_throws = '';
	}

	/**
	 * Mirrors MailPoet's getLists().
	 *
	 * @return array
	 */
	public function getLists() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- MailPoet's own method name.
		return self::$lists;
	}

	/**
	 * Mirrors MailPoet's addSubscriber().
	 *
	 * @param array $subscriber The subscriber.
	 * @param array $lists      List ids.
	 * @return array
	 * @throws Exception When the test says so.
	 */
	public function addSubscriber( $subscriber, $lists = array() ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- MailPoet's own method name.
		if ( self::$add_throws ) {
			throw new Exception( self::$add_throws ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test stub, message never rendered.
		}

		self::$added[] = array(
			'subscriber' => $subscriber,
			'lists'      => $lists,
		);

		return $subscriber;
	}

	/**
	 * Mirrors MailPoet's subscribeToLists().
	 *
	 * @param string $email The subscriber.
	 * @param array  $lists List ids.
	 * @return array
	 * @throws Exception When the test says so.
	 */
	public function subscribeToLists( $email, $lists = array() ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- MailPoet's own method name.
		if ( self::$subscribe_throws ) {
			throw new Exception( self::$subscribe_throws ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test stub, message never rendered.
		}

		self::$subscribed[] = array( $email, $lists );

		return array();
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The stub and its facade belong together.
if ( ! class_exists( '\MailPoet\API\API' ) ) {
	eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- The one way to define a namespaced class from an unnamespaced test file.
		'namespace MailPoet\API; class API {
			public static function MP( $version ) {
				return new \ATF_MailPoet_Stub();
			}
		}'
	);
}
// phpcs:enable

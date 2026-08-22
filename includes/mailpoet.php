<?php
/**
 * MailPoet integration.
 *
 * A form is where a relationship with a visitor starts; the newsletter is how
 * it continues. This file is the bridge: a post-submit action that hands a
 * submission's email and name to MailPoet on the same site, so the person who
 * ticked "keep me posted" actually ends up on the list.
 *
 * Everything here is gated on MailPoet being active and degrades to nothing
 * when it is not -- the action lies dormant, the REST route answers
 * `active: false`, and the MailPoet window pitches the integration instead of
 * configuring it. No MailPoet code ships with this plugin; the API used is the
 * one MailPoet documents for exactly this purpose.
 *
 * Consent is the load-bearing design decision. The hub UI binds the action to
 * an explicit opt-in field by default, the stored condition is an ordinary
 * logic block `atf_logic_conditions_met()` evaluates like any other, and
 * MailPoet's own signup confirmation (double opt-in) runs on top. Subscribing
 * someone who did not ask is the one way this feature can hurt a site's
 * visitors, so every layer leans against it.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether MailPoet is installed and its API is reachable.
 *
 * @since 0.2.0
 *
 * @return bool
 */
function atf_mailpoet_active() {
	return class_exists( '\MailPoet\API\API' );
}

/**
 * The MailPoet API instance, or null when MailPoet is absent.
 *
 * Wrapped because `MP()` can throw on version mismatch, and a broken MailPoet
 * install must read as "not connected" rather than as a fatal on every submit.
 *
 * @since 0.2.0
 *
 * @return object|null
 */
function atf_mailpoet_api() {
	if ( ! atf_mailpoet_active() ) {
		return null;
	}

	try {
		return \MailPoet\API\API::MP( 'v1' );
	} catch ( \Throwable $e ) {
		return null;
	}
}

/**
 * The subscribable MailPoet lists.
 *
 * Only lists of type `default` are offered: MailPoet also models its
 * WordPress-users and WooCommerce-customers segments as lists, and those are
 * not things a form should subscribe strangers to.
 *
 * @since 0.2.0
 *
 * @return array<int, array{id: int, name: string, subscribers: int}>
 */
function atf_mailpoet_lists() {
	$api = atf_mailpoet_api();

	if ( ! $api ) {
		return array();
	}

	try {
		$raw = $api->getLists();
	} catch ( \Throwable $e ) {
		return array();
	}

	$lists = array();

	foreach ( (array) $raw as $list ) {
		if ( ! is_array( $list ) || ! isset( $list['id'], $list['name'] ) ) {
			continue;
		}

		if ( isset( $list['type'] ) && 'default' !== $list['type'] ) {
			continue;
		}

		$lists[] = array(
			'id'   => (int) $list['id'],
			'name' => (string) $list['name'],
		);
	}

	return $lists;
}

/**
 * MailPoet's wordmark, shipped with this plugin.
 *
 * Bundled brand assets rather than a file borrowed from MailPoet's install, so
 * the window can introduce MailPoet properly *before* it is installed — which
 * is exactly when the introduction matters most.
 *
 * @since 0.2.0
 *
 * @return string
 */
function atf_mailpoet_logo_url() {
	return ATF_URL . 'assets/img/mailpoet-logo.png';
}

/**
 * MailPoet's symbol — the M over the open envelope — for compact badges.
 *
 * @since 0.2.0
 *
 * @return string
 */
function atf_mailpoet_symbol_url() {
	return ATF_URL . 'assets/img/mailpoet-symbol.png';
}

/**
 * The REST payload the MailPoet window boots from.
 *
 * @since 0.2.0
 *
 * @return WP_REST_Response
 */
function atf_rest_mailpoet() {
	return rest_ensure_response(
		array(
			'active'   => atf_mailpoet_active(),
			'lists'    => atf_mailpoet_lists(),
			'logo'     => atf_mailpoet_logo_url(),
			'symbol'   => atf_mailpoet_symbol_url(),
			// Where "manage your lists" should send people: MailPoet's own
			// admin when it is installed, the plugin installer when it is not.
			'adminUrl' => atf_mailpoet_active()
				? admin_url( 'admin.php?page=mailpoet-lists' )
				: admin_url( 'plugin-install.php?s=mailpoet&tab=search&type=term' ),
		)
	);
}

/**
 * Runs the `mailpoet` post-submit action: subscribes the visitor.
 *
 * Settings shape (sanitised here, because this is the handler that knows it):
 *
 *     lists            int[]  MailPoet list ids to subscribe to.
 *     email_field      string The field id carrying the address.
 *     first_name_field string Optional field id for the first name.
 *     last_name_field  string Optional field id for the last name.
 *
 * The conditional gate has already run -- `atf_run_actions()` checks the
 * action's logic block before calling any handler -- so by the time this runs,
 * the visitor has met whatever consent condition the form set.
 *
 * MailPoet's signup confirmation is left at the site's own setting: with
 * double opt-in on (its default), the visitor gets MailPoet's confirmation
 * email and only becomes a confirmed subscriber by clicking it.
 *
 * @since 0.2.0
 *
 * @param array $action The action, with `settings`.
 * @param array $values The accepted submission values, keyed by field id.
 * @return true|WP_Error
 */
function atf_action_mailpoet( $action, $values ) {
	$api = atf_mailpoet_api();

	if ( ! $api ) {
		return new WP_Error( 'atf_mailpoet_inactive', __( 'MailPoet is not active on this site.', 'allterrain-forms' ) );
	}

	$settings = isset( $action['settings'] ) && is_array( $action['settings'] ) ? $action['settings'] : array();

	$lists = array();

	if ( isset( $settings['lists'] ) && is_array( $settings['lists'] ) ) {
		$lists = array_values( array_filter( array_map( 'absint', $settings['lists'] ) ) );
	}

	if ( ! $lists ) {
		return new WP_Error( 'atf_mailpoet_no_lists', __( 'No MailPoet list is selected.', 'allterrain-forms' ) );
	}

	$email_field = isset( $settings['email_field'] ) ? sanitize_key( $settings['email_field'] ) : '';
	$email       = $email_field && isset( $values[ $email_field ] ) ? sanitize_email( (string) $values[ $email_field ] ) : '';

	if ( ! is_email( $email ) ) {
		return new WP_Error( 'atf_mailpoet_no_email', __( 'The submission carried no usable email address.', 'allterrain-forms' ) );
	}

	$subscriber = array( 'email' => $email );

	foreach ( array(
		'first_name' => 'first_name_field',
		'last_name'  => 'last_name_field',
	) as $key => $setting ) {
		$field = isset( $settings[ $setting ] ) ? sanitize_key( $settings[ $setting ] ) : '';

		if ( $field && isset( $values[ $field ] ) && is_scalar( $values[ $field ] ) ) {
			$name = sanitize_text_field( (string) $values[ $field ] );

			if ( '' !== $name ) {
				$subscriber[ $key ] = $name;
			}
		}
	}

	try {
		$api->addSubscriber( $subscriber, $lists );

		return true;
	} catch ( \Throwable $e ) {
		// The one refusal that is not a failure: the address is already a
		// subscriber. Fall through to adding the lists to the existing
		// subscription, which is what the visitor asked for.
		try {
			$api->subscribeToLists( $email, $lists );

			return true;
		} catch ( \Throwable $again ) {
			// "Already subscribed to these lists" means the visitor asked for
			// something that is already true -- an outcome, not an error worth
			// stamping on their entry.
			if ( false !== stripos( $again->getMessage(), 'already' ) ) {
				return true;
			}

			return new WP_Error( 'atf_mailpoet_refused', $again->getMessage() );
		}
	}
}

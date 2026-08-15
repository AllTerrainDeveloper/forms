<?php
/**
 * What the visitor sees after they submit.
 *
 * A form carries a list of confirmations, each with its own conditional logic,
 * and the first one whose conditions are met wins. That ordering is what makes
 * "if they picked Support, send them to the support page; otherwise say thank
 * you" expressible without any branching in the form itself.
 *
 * Three kinds: an inline message that replaces the form, a redirect to a URL, or
 * a redirect to a page on this site.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Picks the confirmation for a submission and resolves it.
 *
 * @since 0.1.0
 *
 * @param array $schema   The form schema.
 * @param array $values   The accepted values.
 * @param int   $entry_id The stored entry, or 0.
 * @param int   $form_id  The form.
 * @return array { type: string, message: string, url: string }
 */
function atf_resolve_confirmation( $schema, $values, $entry_id, $form_id ) {
	$context = array(
		'schema'   => $schema,
		'values'   => $values,
		'form_id'  => $form_id,
		'entry_id' => $entry_id,
		'entry'    => $entry_id ? atf_prepare_entry( $entry_id ) : array(),
		'format'   => 'html',
	);

	$chosen = null;

	foreach ( $schema['confirmations'] as $confirmation ) {
		if ( empty( $confirmation['enabled'] ) ) {
			continue;
		}

		if ( atf_logic_conditions_met( $confirmation['logic'], $values, $schema ) ) {
			$chosen = $confirmation;
			break;
		}
	}

	if ( ! $chosen ) {
		$chosen = atf_default_confirmation();
	}

	$resolved = array(
		'type'    => $chosen['type'],
		'message' => '',
		'url'     => '',
	);

	if ( 'message' === $chosen['type'] ) {
		$message = '' !== trim( (string) $chosen['message'] )
			? $chosen['message']
			: __( 'Thank you. Your submission has been received.', 'allterrain-forms' );

		$resolved['message'] = atf_replace_merge_tags( $message, $context );
	} else {
		$resolved['url'] = atf_confirmation_url( $chosen, $context );

		// A redirect with nowhere to go would leave the visitor looking at a
		// form that appears to have done nothing. Falling back to a message is
		// the only outcome that still tells them it worked.
		if ( '' === $resolved['url'] ) {
			$resolved['type']    = 'message';
			$resolved['message'] = __( 'Thank you. Your submission has been received.', 'allterrain-forms' );
		}
	}

	/**
	 * Filters the resolved confirmation for a submission.
	 *
	 * @since 0.1.0
	 *
	 * @param array $resolved { type, message, url }.
	 * @param array $schema   The form schema.
	 * @param array $values   The accepted values.
	 * @param int   $entry_id The stored entry.
	 */
	return apply_filters( 'atf_confirmation', $resolved, $schema, $values, $entry_id );
}

/**
 * The confirmation a form gets when it configures none.
 *
 * @since 0.1.0
 *
 * @return array
 */
function atf_default_confirmation() {
	/**
	 * Filters the confirmation used when a form configures none.
	 *
	 * @since 0.1.0
	 *
	 * @param array $confirmation The default confirmation.
	 */
	return apply_filters(
		'atf_default_confirmation',
		array(
			'id'      => 'default',
			'enabled' => true,
			'name'    => __( 'Default', 'allterrain-forms' ),
			'type'    => 'message',
			'message' => __( 'Thank you. Your submission has been received.', 'allterrain-forms' ),
			'url'     => '',
			'pageId'  => 0,
			'query'   => '',
			'logic'   => array(
				'enabled' => false,
				'action'  => 'show',
				'match'   => 'all',
				'rules'   => array(),
			),
		)
	);
}

/**
 * Builds the URL a redirect confirmation goes to.
 *
 * The query string may carry merge tags, so a confirmation page can greet the
 * visitor by name. Each value is URL-encoded after merging, because a merge tag
 * resolves to whatever somebody typed into a form -- including ampersands, which
 * would otherwise inject extra parameters.
 *
 * @since 0.1.0
 *
 * @param array $confirmation The chosen confirmation.
 * @param array $context      The merge-tag context.
 * @return string An absolute URL, or an empty string.
 */
function atf_confirmation_url( $confirmation, $context ) {
	$url = '';

	if ( 'page' === $confirmation['type'] && $confirmation['pageId'] ) {
		$url = (string) get_permalink( $confirmation['pageId'] );
	}

	if ( 'redirect' === $confirmation['type'] ) {
		$url = atf_replace_merge_tags( $confirmation['url'], array_merge( $context, array( 'format' => 'text' ) ) );
	}

	if ( '' === $url ) {
		return '';
	}

	if ( '' !== $confirmation['query'] ) {
		$query = atf_replace_merge_tags( $confirmation['query'], array_merge( $context, array( 'format' => 'text' ) ) );
		$pairs = array();

		parse_str( $query, $pairs );

		if ( $pairs ) {
			$url = add_query_arg( array_map( 'rawurlencode', wp_unslash( $pairs ) ), $url );
		}
	}

	// Control characters out; the host check happens at redirect time in
	// `atf_redirect_to_confirmation()`.
	return wp_sanitize_redirect( $url );
}

/**
 * Sends the visitor to a confirmation URL.
 *
 * `wp_safe_redirect()` refuses any host but this one, which is the correct
 * default and the wrong answer here: sending a visitor to a payment provider or
 * an external thank-you page is a legitimate thing for a form to do, and it is
 * configured by somebody who already holds `atf_edit_forms`.
 *
 * So the target's host is allowed explicitly, for this one redirect, and the
 * safe redirect still runs. That keeps the protection that matters -- a URL
 * arriving in the *request* can never redirect anywhere, because only a stored
 * confirmation reaches this function -- while letting a form author point
 * somewhere real. The filter is removed immediately afterwards so the allowance
 * cannot leak into another redirect later in the request.
 *
 * @since 0.1.0
 *
 * @param string $url The confirmation URL.
 * @return void
 */
function atf_redirect_to_confirmation( $url ) {
	$host = wp_parse_url( $url, PHP_URL_HOST );

	$allow = static function ( $hosts ) use ( $host ) {
		if ( $host ) {
			$hosts[] = $host;
		}

		return $hosts;
	};

	add_filter( 'allowed_redirect_hosts', $allow );

	$redirected = wp_safe_redirect( $url );

	remove_filter( 'allowed_redirect_hosts', $allow );

	if ( $redirected ) {
		exit;
	}
}

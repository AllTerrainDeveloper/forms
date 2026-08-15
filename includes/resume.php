<?php
/**
 * Save and continue later.
 *
 * A long form -- a job application, a grant submission, an insurance claim -- is
 * routinely abandoned not because somebody changed their mind but because they
 * had to go and find a document. Without a way back, the whole thing is retyped
 * or never finished. It is a paid feature in every other forms plugin and it is
 * the difference between a completed application and an empty afternoon.
 *
 * The design here is deliberately anonymous. A partial submission is stored as
 * an entry in the `atf-partial` status, addressed by a **random token** that is
 * never derived from anything about the person. No account is needed, no cookie
 * is set, and nothing links two partials together. The token is the only key.
 *
 * That means the resume link is a bearer credential: anyone holding it can read
 * the half-finished answers. So it is long, it expires, and the plugin will
 * e-mail it rather than only showing it on screen -- but a site collecting
 * genuinely sensitive answers should require login instead, and the builder says
 * so.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/** The query flag that resumes a form. */
const ATF_RESUME_QUERY = 'atf_resume';

/**
 * Saves a partial submission and returns the way back to it.
 *
 * Not validated. That is the whole point: a half-finished form is by definition
 * missing required answers, and refusing to save it because of that would make
 * the feature useless. Values are still sanitised through their own field types,
 * because they are about to be stored and later echoed back into a page.
 *
 * @since 0.1.0
 *
 * @param int    $form_id The form.
 * @param array  $raw     The `atf` slice of the request.
 * @param string $token   An existing token to update, or empty to mint one.
 * @return array|WP_Error { token, url, expires } or why not.
 */
function atf_save_partial( $form_id, $raw, $token = '' ) {
	$form_id = absint( $form_id );
	$schema  = atf_get_form_schema( $form_id );

	if ( empty( $schema['settings']['resume']['enabled'] ) ) {
		return new WP_Error(
			'atf_resume_disabled',
			__( 'This form does not offer saving for later.', 'allterrain-forms' ),
			array( 'status' => 400 )
		);
	}

	$availability = atf_form_availability( $form_id, $schema );

	if ( ! $availability['open'] ) {
		return new WP_Error( 'atf_form_closed', $availability['message'], array( 'status' => 403 ) );
	}

	$values = atf_sanitize_submission( $schema, is_array( $raw ) ? $raw : array() );
	$days   = max( 1, (int) $schema['settings']['resume']['days'] );
	$expiry = time() + ( $days * DAY_IN_SECONDS );

	$existing = '' !== $token ? atf_find_partial( $token ) : null;

	// Updating in place rather than writing a second row, so a visitor who saves
	// five times over an afternoon leaves one partial rather than five -- and so
	// the retention sweep has one thing to expire.
	if ( $existing ) {
		$entry_id = $existing->ID;
	} else {
		// 32 hex characters from `wp_generate_password()`'s CSPRNG. Long enough
		// that guessing one is not a strategy, which matters because the token
		// is the only thing standing between a stranger and these answers.
		$token = wp_generate_password( 32, false, false );

		$entry_id = wp_insert_post(
			array(
				'post_type'   => ATF_ENTRY_TYPE,
				'post_title'  => sprintf(
					/* translators: %s: the form's title. */
					__( 'Incomplete — %s', 'allterrain-forms' ),
					get_the_title( $form_id )
				),
				'post_status' => ATF_STATUS_PARTIAL,
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $entry_id ) ) {
			return $entry_id;
		}

		update_post_meta( $entry_id, ATF_META_FORM, $form_id );
	}

	update_post_meta( $entry_id, ATF_META_VALUES, wp_slash( wp_json_encode( $values ) ) );
	update_post_meta(
		$entry_id,
		ATF_META_RESUME,
		wp_slash(
			wp_json_encode(
				array(
					'token'   => $token,
					'expires' => $expiry,
					'saved'   => time(),
				)
			)
		)
	);

	$url = add_query_arg( ATF_RESUME_QUERY, $token, atf_form_action_url() );

	/**
	 * Fires after a partial submission is saved.
	 *
	 * The seam for e-mailing the resume link somewhere, or pushing it into a
	 * CRM so somebody can chase the application.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $entry_id The partial entry.
	 * @param int    $form_id  The form.
	 * @param string $url      The resume link.
	 * @param array  $values   What has been filled in so far.
	 */
	do_action( 'atf_partial_saved', $entry_id, $form_id, $url, $values );

	return array(
		'token'   => $token,
		'url'     => $url,
		'expires' => gmdate( 'c', $expiry ),
		'days'    => $days,
	);
}

/**
 * Finds a partial entry by its token.
 *
 * The comparison is `hash_equals()` rather than `===` even though this is a
 * lookup rather than a verification, because the query below narrows by form and
 * status and the final match is still on a secret.
 *
 * @since 0.1.0
 *
 * @param string $token The token.
 * @return WP_Post|null The partial entry, or null when it is unknown or expired.
 */
function atf_find_partial( $token ) {
	$token = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $token );

	if ( strlen( $token ) < 16 ) {
		return null;
	}

	$found = get_posts(
		array(
			'post_type'        => ATF_ENTRY_TYPE,
			'post_status'      => ATF_STATUS_PARTIAL,
			'numberposts'      => 1,
			'suppress_filters' => false,
			'meta_query'       => array(
				array(
					'key'     => ATF_META_RESUME,
					'value'   => '"' . $token . '"',
					'compare' => 'LIKE',
				),
			),
		)
	);

	if ( ! $found ) {
		return null;
	}

	$post   = $found[0];
	$resume = json_decode( (string) get_post_meta( $post->ID, ATF_META_RESUME, true ), true );

	if ( ! is_array( $resume ) || empty( $resume['token'] ) ) {
		return null;
	}

	// The `LIKE` above can match a token that merely contains this one, so the
	// real comparison happens here.
	if ( ! hash_equals( (string) $resume['token'], $token ) ) {
		return null;
	}

	if ( ! empty( $resume['expires'] ) && time() > (int) $resume['expires'] ) {
		return null;
	}

	return $post;
}

/**
 * The values a resumed form should open with.
 *
 * @since 0.1.0
 *
 * @param string $token The token from the URL.
 * @return array { form_id: int, values: array, token: string } or an empty array.
 */
function atf_resume_values( $token ) {
	$post = atf_find_partial( $token );

	if ( ! $post ) {
		return array();
	}

	$values = json_decode( (string) get_post_meta( $post->ID, ATF_META_VALUES, true ), true );

	return array(
		'form_id' => (int) get_post_meta( $post->ID, ATF_META_FORM, true ),
		'values'  => is_array( $values ) ? $values : array(),
		'token'   => $token,
		'entry'   => $post->ID,
	);
}

/**
 * Deletes the partial a completed submission came from.
 *
 * Hooked on `atf_entry_created`, so finishing a form clears the half-finished
 * copy of it. Without this the entries list carries a permanent shadow of every
 * application that was ever saved and then completed, and the resume link keeps
 * working long after it means anything.
 *
 * @since 0.1.0
 *
 * @param int   $entry_id The finished entry.
 * @param int   $form_id  The form.
 * @param array $values   The accepted values.
 * @return void
 */
function atf_clear_partial_on_submit( $entry_id, $form_id, $values ) {
	// The token travels in the submission that finishes the form, which is the
	// only thing tying the two together -- the partial is anonymous by design.
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- The submission's own nonce was verified before this action fired.
	$token = isset( $_POST[ ATF_RESUME_QUERY ] )
		? sanitize_text_field( wp_unslash( $_POST[ ATF_RESUME_QUERY ] ) )
		: '';
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	if ( '' === $token ) {
		return;
	}

	$partial = atf_find_partial( $token );

	if ( $partial ) {
		wp_delete_post( $partial->ID, true );
	}
}
add_action( 'atf_entry_created', 'atf_clear_partial_on_submit', 10, 3 );

/**
 * Expires partials whose resume window has passed.
 *
 * Runs on the same daily sweep as the retention policy. Partials expire on their
 * own schedule regardless of the form's retention setting, because a resume link
 * that no longer works should not leave the answers behind it lying around.
 *
 * @since 0.1.0
 *
 * @return int How many were removed.
 */
function atf_expire_partials() {
	$partials = get_posts(
		array(
			'post_type'        => ATF_ENTRY_TYPE,
			'post_status'      => ATF_STATUS_PARTIAL,
			'numberposts'      => 200,
			'fields'           => 'ids',
			'suppress_filters' => false,
		)
	);

	$removed = 0;

	foreach ( $partials as $entry_id ) {
		$resume = json_decode( (string) get_post_meta( $entry_id, ATF_META_RESUME, true ), true );

		// A partial with no expiry recorded is malformed, and leaving it forever
		// would mean somebody's half-typed answers outliving every policy on the
		// site.
		$expires = is_array( $resume ) && ! empty( $resume['expires'] )
			? (int) $resume['expires']
			: 0;

		if ( $expires && time() <= $expires ) {
			continue;
		}

		atf_delete_entry_completely( $entry_id );
		++$removed;
	}

	return $removed;
}
add_action( 'atf_apply_retention', 'atf_expire_partials' );

/**
 * The button that saves a form for later.
 *
 * Rendered beside the submit button, and only when the form offers it. A plain
 * `<button type="button">` so it cannot submit the form by accident, which is
 * exactly what a `<button>` with no explicit type inside a form does.
 *
 * @since 0.1.0
 *
 * @param array  $schema The form schema.
 * @param string $token  The token this form was resumed from, if any.
 * @return string
 */
function atf_render_resume_button( $schema, $token = '' ) {
	if ( empty( $schema['settings']['resume']['enabled'] ) ) {
		return '';
	}

	return sprintf(
		'<button type="button" class="atf-button atf-button--ghost atf-resume" data-atf-resume>%s</button>'
		. '<input type="hidden" name="%s" value="%s" data-atf-resume-token>',
		esc_html__( 'Save and continue later', 'allterrain-forms' ),
		esc_attr( ATF_RESUME_QUERY ),
		esc_attr( $token )
	);
}

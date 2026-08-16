<?php
/**
 * Whether a form is accepting submissions right now.
 *
 * Scheduling, submission limits and login requirements all answer the same
 * question, so they answer it in one place. `atf_form_availability()` is called
 * twice for every submission: once by the renderer, to decide whether to draw
 * the form at all, and once by the submission handler, to decide whether to
 * accept it. The second call is the one that matters -- a closed form that
 * renders a notice can still be posted to by anyone with the URL, and only the
 * server-side check stands between that and an entry.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether a form is open, and why not when it is not.
 *
 * @since 0.1.0
 *
 * @param int   $form_id The form.
 * @param array $schema  Its schema. Read from the form when omitted.
 * @return array { open: bool, reason: string, message: string }
 */
function atf_form_availability( $form_id, $schema = null ) {
	$form_id = absint( $form_id );
	$schema  = null === $schema ? atf_get_form_schema( $form_id ) : $schema;

	$settings = $schema['settings'];
	$open     = array(
		'open'    => true,
		'reason'  => '',
		'message' => '',
	);

	// Someone who can edit forms is never locked out by a schedule or a limit.
	// They are the person who needs to test the form, and a closed notice with
	// no way past it is how a scheduling bug survives to launch day.
	$is_editor = atf_can_edit_forms();

	if ( ! empty( $settings['requireLogin'] ) && ! is_user_logged_in() ) {
		return array(
			'open'    => false,
			'reason'  => 'login',
			'message' => '' !== $settings['loginMessage']
				? $settings['loginMessage']
				: __( 'Please log in to fill in this form.', 'allterrain-forms' ),
		);
	}

	if ( ! empty( $settings['roles'] ) && ! $is_editor ) {
		// A logged-out visitor holds no role at all, so a roles list closes the
		// form to them even when `requireLogin` is off -- otherwise logging out
		// would be the way past the role gate.
		$user_roles = is_user_logged_in() ? (array) wp_get_current_user()->roles : array();
		$match      = array_intersect( (array) $settings['roles'], $user_roles );

		if ( ! $match ) {
			return array(
				'open'    => false,
				'reason'  => 'role',
				'message' => '' !== $settings['loginMessage']
					? $settings['loginMessage']
					: __( 'This form is not available to your account.', 'allterrain-forms' ),
			);
		}
	}

	$schedule = $settings['schedule'];
	$now      = current_time( 'timestamp' );

	if ( ! $is_editor && ! empty( $schedule['start'] ) ) {
		$start = strtotime( $schedule['start'] );

		if ( $start && $now < $start ) {
			return array(
				'open'    => false,
				'reason'  => 'not_yet_open',
				'message' => '' !== $schedule['message']
					? $schedule['message']
					: __( 'This form is not open yet.', 'allterrain-forms' ),
			);
		}
	}

	if ( ! $is_editor && ! empty( $schedule['end'] ) ) {
		$end = strtotime( $schedule['end'] );

		if ( $end && $now > $end ) {
			return array(
				'open'    => false,
				'reason'  => 'closed',
				'message' => '' !== $schedule['message']
					? $schedule['message']
					: __( 'This form is closed.', 'allterrain-forms' ),
			);
		}
	}

	$limit = $settings['limit'];

	if ( ! $is_editor && $limit['total'] > 0 && atf_count_entries( $form_id ) >= $limit['total'] ) {
		return array(
			'open'    => false,
			'reason'  => 'limit_total',
			'message' => '' !== $limit['message']
				? $limit['message']
				: __( 'This form is no longer accepting responses.', 'allterrain-forms' ),
		);
	}

	if ( ! $is_editor && $limit['perUser'] > 0 && is_user_logged_in() ) {
		if ( atf_count_entries( $form_id, get_current_user_id() ) >= $limit['perUser'] ) {
			return array(
				'open'    => false,
				'reason'  => 'limit_user',
				'message' => '' !== $limit['message']
					? $limit['message']
					: __( 'You have already responded to this form.', 'allterrain-forms' ),
			);
		}
	}

	/**
	 * Filters whether a form is accepting submissions.
	 *
	 * Called for both the render and the submit, so anything closed here is
	 * closed in both -- which is the property that makes it safe to add a
	 * condition without also having to guard the handler.
	 *
	 * @since 0.1.0
	 *
	 * @param array $open    { open, reason, message }.
	 * @param int   $form_id The form.
	 * @param array $schema  The form schema.
	 */
	return apply_filters( 'atf_form_availability', $open, $form_id, $schema );
}

/**
 * How many entries a form has, optionally from one user.
 *
 * Spam and incomplete entries do not count towards a limit. A limit of 100 that
 * fills up with rejected spam would close a form nobody successfully used.
 *
 * @since 0.1.0
 *
 * @param int $form_id The form.
 * @param int $user_id Optional. Restrict to one author.
 * @return int
 */
function atf_count_entries( $form_id, $user_id = 0 ) {
	$args = array(
		'post_type'      => ATF_ENTRY_TYPE,
		'post_status'    => array( ATF_STATUS_UNREAD, ATF_STATUS_READ ),
		'fields'         => 'ids',
		'posts_per_page' => 1,
		'no_found_rows'  => false,
		'meta_query'     => array(
			array(
				'key'   => ATF_META_FORM,
				'value' => absint( $form_id ),
			),
		),
	);

	if ( $user_id ) {
		$args['author'] = absint( $user_id );
	}

	$query = new WP_Query( $args );

	return (int) $query->found_posts;
}

/**
 * Whether a schema contains a field that uploads files.
 *
 * Decides the form's `enctype`, which has to be set at render time and cannot be
 * corrected later -- a form posted without `multipart/form-data` arrives with an
 * empty `$_FILES` and no indication anything went wrong.
 *
 * @since 0.1.0
 *
 * @param array $schema The form schema.
 * @return bool
 */
function atf_has_upload_field( $schema ) {
	foreach ( isset( $schema['fields'] ) ? $schema['fields'] : array() as $field ) {
		if ( 'file' === $field['type'] ) {
			return true;
		}

		foreach ( isset( $field['fields'] ) && is_array( $field['fields'] ) ? $field['fields'] : array() as $sub ) {
			if ( 'file' === $sub['type'] ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Fills in the values a form should open with.
 *
 * Three sources, each beating the one before: the field's own default, whatever
 * the field's `prefill` names, and finally anything passed in -- which is what a
 * failed submit sends back so the visitor does not retype a page of answers.
 *
 * @since 0.1.0
 *
 * @param array $schema The form schema.
 * @param array $values Values to override with.
 * @return array Field id => value.
 */
function atf_prefill_values( $schema, $values = array() ) {
	$resolved = array();

	foreach ( atf_input_fields( $schema ) as $field ) {
		$value = $field['default'];

		if ( '' !== $field['prefill'] ) {
			$prefilled = atf_resolve_prefill( $field['prefill'], $field );

			if ( '' !== $prefilled ) {
				$value = $prefilled;
			}
		}

		$resolved[ $field['id'] ] = $value;
	}

	foreach ( $values as $id => $value ) {
		$resolved[ $id ] = $value;
	}

	/**
	 * Filters the values a form opens with.
	 *
	 * @since 0.1.0
	 *
	 * @param array $resolved Field id => value.
	 * @param array $schema   The form schema.
	 */
	return apply_filters( 'atf_prefill_values', $resolved, $schema );
}

/**
 * Resolves one field's prefill source.
 *
 * A `query:` source reads the URL, which is unsanitised visitor input arriving
 * in a field that will be echoed back into the page -- so it goes through the
 * field's own sanitiser before it goes anywhere near the renderer.
 *
 * @since 0.1.0
 *
 * @param string $source The prefill source, e.g. `query:utm_source` or `user:email`.
 * @param array  $field  The field it is for.
 * @return string The resolved value, or an empty string.
 */
function atf_resolve_prefill( $source, $field ) {
	$parts = explode( ':', $source, 2 );
	$kind  = strtolower( trim( $parts[0] ) );
	$key   = isset( $parts[1] ) ? trim( $parts[1] ) : '';

	switch ( $kind ) {
		case 'query':
			if ( '' === $key || ! isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a public URL parameter to pre-fill a public form; nothing is written.
				return '';
			}

			$raw = wp_unslash( $_GET[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised on the next line by the field's own type.

			return atf_sanitize_field_value( $raw, $field );

		case 'user':
			return atf_resolve_user_tag( $key );

		case 'site':
			if ( 'url' === $key ) {
				return home_url();
			}

			if ( 'admin_email' === $key ) {
				return (string) get_option( 'admin_email' );
			}

			return wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );

		case 'date':
			// The key was previously ignored, which made `date:today` and
			// `date:now` and `date:anything` all produce the same `Y-m-d` — while
			// the builder's own hint said `date:today` as though the word were
			// load-bearing. Either the key means something or it should not be in
			// the syntax; this makes it mean something.
			//
			// `today` and `now` are named rather than left as formats because they
			// are what a date field and a time field respectively want, and a
			// person should not have to know `H:i` to pre-fill the time.
			if ( 'now' === $key ) {
				return wp_date( 'H:i' );
			}

			if ( '' === $key || 'today' === $key ) {
				return wp_date( 'Y-m-d' );
			}

			return wp_date( $key );
	}

	/**
	 * Resolves a prefill source this plugin does not know.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value  The resolved value, empty until something sets it.
	 * @param string $source The whole source string.
	 * @param array  $field  The field.
	 */
	return (string) apply_filters( 'atf_resolve_prefill', '', $source, $field );
}

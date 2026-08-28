<?php
/**
 * The submission pipeline.
 *
 * One function, `alltfo_process_submission()`, and every route into the plugin ends
 * at it: the REST endpoint the bundle posts to, the plain `POST` a form makes
 * with JavaScript off, and any programmatic call. There is exactly one place
 * where a submission is accepted, which is the only way to be sure the AJAX path
 * and the no-JavaScript path enforce the same rules.
 *
 * The order is fixed and each step earns its position:
 *
 * 1. **Availability** — a closed form rejects before anything is parsed.
 * 2. **Sanitise** — every value through its field type, before it is read.
 * 3. **Uploads** — files become attachments, or the submission fails here.
 * 4. **Calculations** — recomputed server-side; the browser's totals are ignored.
 * 5. **Validate** — against the fields conditional logic says were visible.
 * 6. **Spam** — after validation, so a real visitor sees their typo first.
 * 7. **Store** — the entry, unless the form is set not to keep them.
 * 8. **Actions** — create a post, register a user, call a webhook.
 * 9. **Notify** — e-mail, conditionally.
 * 10. **Confirm** — resolve what the visitor is shown next.
 *
 * Notifications and actions run after the entry is stored, so a mail server that
 * is refusing connections costs the site an e-mail rather than a submission.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Processes a submission from start to finish.
 *
 * @since 0.1.0
 *
 * @param int   $form_id The form.
 * @param array $request The request body, unslashed. Deep-sanitised on entry
 *                       via `alltfo_sanitize_request()`, so callers may hand
 *                       it over raw.
 * @param array $files   The `$_FILES` array.
 * @return array {
 *     The result.
 *
 *     @type bool   $success      Whether the submission was accepted.
 *     @type array  $errors       Field id => message, when it was not.
 *     @type string $message      A form-level message, when there is one.
 *     @type int    $entry_id     The stored entry, or 0.
 *     @type array  $confirmation The resolved confirmation.
 * }
 */
function alltfo_process_submission( $form_id, $request, $files = array() ) {
	$form_id = absint( $form_id );
	$form    = $form_id ? get_post( $form_id ) : null;

	// The same three-way gate as the renderer, trash included: a deleted form
	// must refuse a POST as surely as it refuses to render, or a browser tab
	// opened before the deletion could keep feeding it entries afterwards.
	if ( ! $form || ALLTFO_FORM_TYPE !== $form->post_type || 'trash' === $form->post_status ) {
		return alltfo_submission_failure( __( 'That form does not exist.', 'allterrain-forms' ) );
	}

	$schema = alltfo_get_form_schema( $form_id );

	// Sanitise early: the whole request is cleaned here, before any code —
	// including the `alltfo_before_submission` action below — reads a byte of
	// it. This is the coarse pass; each answer is then sanitised *again*
	// through its own field type in `alltfo_sanitize_submission()`, which
	// knows the shape it expects.
	$request = alltfo_sanitize_request( $request );

	/**
	 * Fires before a submission is processed.
	 *
	 * @since 0.1.0
	 * @since 0.4.0 `$request` arrives deep-sanitised via `alltfo_sanitize_request()`.
	 *
	 * @param int   $form_id The form.
	 * @param array $request The request, deep-sanitised.
	 * @param array $schema  The form schema.
	 */
	do_action( 'alltfo_before_submission', $form_id, $request, $schema );

	$availability = alltfo_form_availability( $form_id, $schema );

	if ( ! $availability['open'] ) {
		return alltfo_submission_failure( $availability['message'] );
	}

	// A preview never stores anything, never e-mails anyone and never counts.
	// The builder's preview posts through this same pipeline so that what it
	// shows is what a visitor would get -- including the validation errors.
	$is_preview = ! empty( $request['alltfo_preview'] ) && alltfo_can_edit_forms();

	$raw    = isset( $request['atf'] ) && is_array( $request['atf'] ) ? $request['atf'] : array();
	$values = alltfo_sanitize_submission( $schema, $raw );

	$uploads = alltfo_handle_uploads( $schema, $files, $form_id );

	if ( $uploads['errors'] ) {
		// Fields whose uploads succeeded before another field's failed do not
		// keep their attachments: the submission as a whole was refused, and an
		// attachment with no entry to belong to is an orphan nothing can find.
		foreach ( $uploads['values'] as $ids ) {
			alltfo_delete_upload_attachments( $ids );
		}

		return alltfo_submission_failure( alltfo_generic_error_message(), $uploads['errors'] );
	}

	foreach ( $uploads['values'] as $field_id => $ids ) {
		$values[ $field_id ] = $ids;
	}

	$values = alltfo_apply_other_values( $schema, $values, $request );
	$values = alltfo_apply_calculations( $schema, $values );

	$errors = alltfo_validate_submission( $schema, $values, array( 'form_id' => $form_id ) );

	if ( $errors ) {
		// The uploads were stored before validation ran -- they had to be, a
		// broken file fails a submission too -- so a refused submission has to
		// take them back out or every failed attempt leaves files behind.
		foreach ( $uploads['values'] as $ids ) {
			alltfo_delete_upload_attachments( $ids );
		}

		return alltfo_submission_failure( alltfo_generic_error_message(), $errors );
	}

	$spam = alltfo_screen_for_spam( $schema, $values, $request );

	if ( $is_preview ) {
		return array(
			'success'      => true,
			'errors'       => array(),
			'message'      => '',
			'entry_id'     => 0,
			'confirmation' => alltfo_resolve_confirmation( $schema, $values, 0, $form_id ),
			'preview'      => true,
		);
	}

	$entry_id = 0;

	if ( ! empty( $schema['settings']['storage']['entries'] ) ) {
		$entry_id = alltfo_store_entry( $form_id, $schema, $values, $spam );

		if ( is_wp_error( $entry_id ) ) {
			return alltfo_submission_failure( $entry_id->get_error_message() );
		}
	}

	// Spam is stored and then stops. Sending the notification would put the
	// spam in somebody's inbox, which is the outcome the screening exists to
	// prevent -- but the visitor is told it worked, because telling a spammer
	// their submission was caught is how they learn to get past it, and telling
	// a false positive that they failed is how a real enquiry is lost twice.
	if ( $spam['spam'] ) {
		/**
		 * Fires when a submission is filed as spam.
		 *
		 * @since 0.1.0
		 *
		 * @param int   $entry_id The stored entry, or 0.
		 * @param int   $form_id  The form.
		 * @param array $spam     { spam, reason }.
		 */
		do_action( 'alltfo_submission_spam', $entry_id, $form_id, $spam );

		return array(
			'success'      => true,
			'errors'       => array(),
			'message'      => '',
			'entry_id'     => $entry_id,
			'confirmation' => alltfo_resolve_confirmation( $schema, $values, $entry_id, $form_id ),
		);
	}

	alltfo_record_submission( $form_id );

	/**
	 * Fires once a submission has been accepted and stored.
	 *
	 * The main integration point. Everything downstream -- actions, e-mails,
	 * webhooks -- hangs off this, so anything hooked here sees the same entry
	 * they do.
	 *
	 * @since 0.1.0
	 *
	 * @param int   $entry_id The stored entry, or 0 when storage is off.
	 * @param int   $form_id  The form.
	 * @param array $values   The accepted values.
	 * @param array $schema   The form schema.
	 */
	do_action( 'alltfo_entry_created', $entry_id, $form_id, $values, $schema );

	// The resume token travels in the submission that finishes a saved form.
	// It is read from the parsed `$request` here rather than from `$_POST`,
	// because a REST submission with a JSON body never populates the
	// superglobal -- the `$_POST` path in `alltfo_clear_partial_on_submit()`
	// only ever saw the no-JavaScript form post.
	if ( function_exists( 'alltfo_clear_partial' ) && ! empty( $request[ ALLTFO_RESUME_QUERY ] ) ) {
		alltfo_clear_partial( (string) $request[ ALLTFO_RESUME_QUERY ] );
	}

	alltfo_run_actions( $schema, $values, $entry_id, $form_id, $request );
	alltfo_send_notifications( $schema, $values, $entry_id, $form_id );

	return array(
		'success'      => true,
		'errors'       => array(),
		'message'      => '',
		'entry_id'     => $entry_id,
		'confirmation' => alltfo_resolve_confirmation( $schema, $values, $entry_id, $form_id ),
	);
}

/**
 * Deep-sanitises a raw request body, early.
 *
 * The coarse first pass over everything a request carries, run at the top of
 * the pipeline so nothing downstream ever holds dirty data: keys become plain
 * text, every scalar goes through `sanitize_textarea_field()` — tags and
 * control characters stripped, the newlines a message field exists to collect
 * kept — and anything that is neither an array nor a scalar is dropped. Each
 * answer is then sanitised *again* through its own field type in
 * `alltfo_sanitize_submission()`; this pass is about never trusting the wire,
 * that one about the shape each field expects.
 *
 * The depth cap matches nothing a real form produces — the deepest legitimate
 * shape, a value inside a repeater row, sits four levels under `atf` — and
 * exists so a hand-crafted JSON body cannot turn the recursion into work.
 *
 * @since 0.4.0
 *
 * @param mixed $request The raw request body, unslashed.
 * @param int   $depth   Internal recursion depth.
 * @return array The sanitised request.
 */
function alltfo_sanitize_request( $request, $depth = 0 ) {
	if ( ! is_array( $request ) || $depth > 8 ) {
		return array();
	}

	$clean = array();

	foreach ( $request as $key => $value ) {
		$key = is_int( $key ) ? $key : sanitize_text_field( (string) $key );

		if ( is_array( $value ) ) {
			$clean[ $key ] = alltfo_sanitize_request( $value, $depth + 1 );
		} elseif ( null === $value || is_scalar( $value ) ) {
			$clean[ $key ] = sanitize_textarea_field( (string) $value );
		}
	}

	return $clean;
}

/**
 * Sanitises every submitted value through its own field type.
 *
 * Walks the schema rather than the request, which is the important direction: a
 * request carrying a key no field asked for is simply never read, so a forged
 * `atf[administrator]` cannot reach anything downstream.
 *
 * @since 0.1.0
 *
 * @param array $schema The form schema.
 * @param array $raw    The `atf` slice of the request.
 * @return array Field id => sanitised value.
 */
function alltfo_sanitize_submission( $schema, $raw ) {
	$values = array();

	foreach ( alltfo_input_fields( $schema ) as $field ) {
		// A file field's value never comes from the request body -- it is built
		// from `$_FILES` by the upload handler. Reading it here would let a
		// forged body claim an attachment id it does not own.
		if ( 'file' === $field['type'] ) {
			continue;
		}

		$submitted = array_key_exists( $field['id'], $raw ) ? $raw[ $field['id'] ] : '';

		$values[ $field['id'] ] = alltfo_sanitize_field_value( $submitted, $field );
	}

	/**
	 * Filters the sanitised values of a submission.
	 *
	 * @since 0.1.0
	 *
	 * @param array $values Field id => value.
	 * @param array $schema The form schema.
	 * @param array $raw    The raw request slice.
	 */
	return apply_filters( 'alltfo_sanitized_values', $values, $schema, $raw );
}

/**
 * Replaces the `__other__` marker with what was typed beside it.
 *
 * The choice group posts `__other__` as its value and the free-text box posts
 * separately, so the two are rejoined here, before validation. The choice
 * whitelist in `alltfo_validate_bounds()` is skipped for fields with "Other"
 * enabled, because once the marker has been replaced the visitor's free text
 * is a legitimate answer no list could anticipate.
 *
 * @since 0.1.0
 *
 * @param array $schema  The form schema.
 * @param array $values  The sanitised values.
 * @param array $request The raw request.
 * @return array The values, with `__other__` resolved.
 */
function alltfo_apply_other_values( $schema, $values, $request ) {
	$other = isset( $request['alltfo_other'] ) && is_array( $request['alltfo_other'] ) ? $request['alltfo_other'] : array();

	if ( ! $other ) {
		return $values;
	}

	foreach ( alltfo_input_fields( $schema ) as $field ) {
		if ( empty( $field['other'] ) || ! isset( $other[ $field['id'] ] ) ) {
			continue;
		}

		$typed = sanitize_text_field( (string) $other[ $field['id'] ] );

		if ( '' === $typed ) {
			continue;
		}

		$value = isset( $values[ $field['id'] ] ) ? $values[ $field['id'] ] : '';

		if ( is_array( $value ) ) {
			$values[ $field['id'] ] = array_map(
				static function ( $item ) use ( $typed ) {
					return '__other__' === $item ? $typed : $item;
				},
				$value
			);
			continue;
		}

		if ( '__other__' === $value ) {
			$values[ $field['id'] ] = $typed;
		}
	}

	return $values;
}

/**
 * Stores an entry.
 *
 * @since 0.1.0
 *
 * @param int   $form_id The form.
 * @param array $schema  The form schema.
 * @param array $values  The accepted values.
 * @param array $spam    The spam verdict.
 * @return int|WP_Error The entry id.
 */
function alltfo_store_entry( $form_id, $schema, $values, $spam = array() ) {
	$storage = $schema['settings']['storage'];

	// A password never reaches storage. The registration action has already read
	// it out of the in-memory values by this point; what is written here is the
	// version with every password field blanked.
	foreach ( alltfo_input_fields( $schema ) as $field ) {
		if ( 'password' === $field['type'] ) {
			unset( $values[ $field['id'] ] );
		}
	}

	$entry_id = wp_insert_post(
		array(
			'post_type'    => ALLTFO_ENTRY_TYPE,
			'post_title'   => alltfo_entry_title( $schema, $values, $form_id ),
			'post_status'  => ! empty( $spam['spam'] ) ? ALLTFO_STATUS_SPAM : ALLTFO_STATUS_UNREAD,
			'post_author'  => get_current_user_id(),
			'post_content' => '',
		),
		true
	);

	if ( is_wp_error( $entry_id ) ) {
		return $entry_id;
	}

	$ip = $storage['ip'] ? alltfo_client_ip() : '';

	if ( $ip && $storage['anonymise'] ) {
		$ip = wp_privacy_anonymize_ip( $ip );
	}

	$context = array(
		'ip'        => $ip,
		'userAgent' => $storage['userAgent'] && isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '',
		'referrer'  => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
		'userId'    => get_current_user_id(),
		'spam'      => ! empty( $spam['spam'] ) ? (string) $spam['reason'] : '',
		'submitted' => current_time( 'mysql', true ),
	);

	if ( ! empty( $schema['settings']['quiz']['enabled'] ) ) {
		$context['quiz'] = alltfo_score_quiz( $schema, $values );
	}

	update_post_meta( $entry_id, ALLTFO_META_FORM, $form_id );
	update_post_meta( $entry_id, ALLTFO_META_VALUES, wp_slash( wp_json_encode( $values ) ) );
	update_post_meta( $entry_id, ALLTFO_META_CONTEXT, wp_slash( wp_json_encode( $context ) ) );

	// Files uploaded with this submission are parented to the entry, so deleting
	// the entry takes them with it and the media library shows what they belong
	// to rather than a wall of orphans.
	foreach ( $values as $field_id => $value ) {
		$field = alltfo_find_field( $schema, $field_id );

		if ( ! $field || 'file' !== $field['type'] ) {
			continue;
		}

		foreach ( (array) $value as $attachment_id ) {
			wp_update_post(
				array(
					'ID'          => absint( $attachment_id ),
					'post_parent' => $entry_id,
				)
			);
		}
	}

	return $entry_id;
}

/**
 * A readable title for an entry.
 *
 * Built from the form's first meaningful answer, because "Entry #418" tells
 * nobody anything and the entries list is mostly read by scanning titles.
 *
 * @since 0.1.0
 *
 * @param array $schema  The form schema.
 * @param array $values  The values.
 * @param int   $form_id The form.
 * @return string
 */
function alltfo_entry_title( $schema, $values, $form_id ) {
	foreach ( alltfo_input_fields( $schema ) as $field ) {
		if ( ! in_array( $field['type'], array( 'text', 'email', 'name' ), true ) ) {
			continue;
		}

		$text = alltfo_format_field_value(
			isset( $values[ $field['id'] ] ) ? $values[ $field['id'] ] : '',
			$field,
			'table'
		);

		if ( '' !== trim( $text ) ) {
			return wp_trim_words( $text, 8, '…' );
		}
	}

	return sprintf(
		/* translators: 1: form title, 2: submission date. */
		__( '%1$s — %2$s', 'allterrain-forms' ),
		get_the_title( $form_id ),
		wp_date( (string) get_option( 'date_format' ) )
	);
}

/**
 * A failure result.
 *
 * @since 0.1.0
 *
 * @param string $message A form-level message.
 * @param array  $errors  Field id => message.
 * @return array
 */
function alltfo_submission_failure( $message, $errors = array() ) {
	return array(
		'success'      => false,
		'errors'       => $errors,
		'message'      => $message,
		'entry_id'     => 0,
		'confirmation' => array(),
	);
}

/**
 * The message shown above a form that failed validation.
 *
 * @since 0.1.0
 *
 * @return string
 */
function alltfo_generic_error_message() {
	/**
	 * Filters the message shown when a submission fails validation.
	 *
	 * @since 0.1.0
	 *
	 * @param string $message The message.
	 */
	return (string) apply_filters(
		'alltfo_validation_message',
		__( 'Please check the form and try again.', 'allterrain-forms' )
	);
}

/**
 * Handles a plain, non-JavaScript form post.
 *
 * Hooked on `wp` rather than `init` so conditional tags work, and so the
 * redirect confirmation can run before any output. The result is stashed for the
 * shortcode to pick up when it renders, which is what lets a failed submit come
 * back on the page the form is on with the answers still in it.
 *
 * @since 0.1.0
 *
 * @return void
 */
function alltfo_handle_post_submission() {
	if ( is_admin() || ! isset( $_POST['alltfo_form_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is checked below, once the form it belongs to is known.
		return;
	}

	$form_id = absint( $_POST['alltfo_form_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- As above.

	if ( ! $form_id ) {
		return;
	}

	$nonce = isset( $_POST['alltfo_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['alltfo_nonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, 'alltfo_submit_' . $form_id ) ) {
		// An expired nonce is the common case here -- a form left open in a tab
		// overnight -- so it is reported as something to retry rather than as an
		// attack.
		alltfo_stash_result(
			$form_id,
			alltfo_submission_failure( __( 'This form expired. Please reload the page and try again.', 'allterrain-forms' ) )
		);

		return;
	}

	$request = alltfo_sanitize_request( wp_unslash( $_POST ) );
	$files   = isset( $_FILES ) ? $_FILES : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated in full by `alltfo_handle_uploads()`.

	$result = alltfo_process_submission( $form_id, $request, $files );

	// A redirect confirmation has to happen before anything is printed, which is
	// the whole reason this runs on `wp` and not at render time.
	if ( $result['success'] && ! empty( $result['confirmation']['type'] ) && 'message' !== $result['confirmation']['type'] ) {
		$url = isset( $result['confirmation']['url'] ) ? $result['confirmation']['url'] : '';

		if ( '' !== $url ) {
			alltfo_redirect_to_confirmation( $url );
		}
	}

	alltfo_stash_result( $form_id, $result, $request );
}
add_action( 'wp', 'alltfo_handle_post_submission' );

/**
 * Remembers a submission's result for the shortcode to render.
 *
 * @since 0.1.0
 *
 * @param int   $form_id The form.
 * @param array $result  The result, or null to read.
 * @param array $request The request, so a failed form can be re-filled.
 * @return array|null The stashed result when reading.
 */
function alltfo_stash_result( $form_id = 0, $result = null, $request = array() ) {
	static $results = array();

	if ( null !== $result ) {
		$results[ $form_id ] = array(
			'result'  => $result,
			'request' => isset( $request['atf'] ) && is_array( $request['atf'] ) ? $request['atf'] : array(),
		);

		return null;
	}

	return isset( $results[ $form_id ] ) ? $results[ $form_id ] : null;
}

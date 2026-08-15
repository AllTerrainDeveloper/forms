<?php
/**
 * Post-submit actions.
 *
 * Things a form does besides storing an entry and sending an e-mail: create a
 * post, register a user, update user meta, call a webhook. Each is conditional
 * on the same logic shape as everything else, so "register them only if they
 * ticked the box" needs no special support.
 *
 * Front-end post submission and front-end user registration are both paid
 * add-ons elsewhere. They are two `case`s here.
 *
 * Every action runs *after* the entry is stored. An action that throws, times
 * out or is refused must not cost the site the submission -- so failures are
 * recorded against the entry and the pipeline carries on.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs every action whose conditions are met.
 *
 * @since 0.1.0
 *
 * @param array $schema   The form schema.
 * @param array $values   The accepted values.
 * @param int   $entry_id The stored entry, or 0.
 * @param int   $form_id  The form.
 * @param array $request  The raw request, for values never stored (a password).
 * @return void
 */
function atf_run_actions( $schema, $values, $entry_id, $form_id, $request = array() ) {
	foreach ( $schema['actions'] as $action ) {
		if ( empty( $action['enabled'] ) ) {
			continue;
		}

		if ( ! atf_logic_conditions_met( $action['logic'], $values, $schema ) ) {
			continue;
		}

		$result = atf_run_action( $action, $schema, $values, $entry_id, $form_id, $request );

		if ( is_wp_error( $result ) && $entry_id ) {
			// Recorded on the entry rather than thrown, so somebody reading the
			// submission can see that the webhook failed -- which is the only
			// place they would ever think to look.
			add_post_meta(
				$entry_id,
				'_atf_action_error',
				sprintf( '%s: %s', $action['type'], $result->get_error_message() )
			);
		}
	}
}

/**
 * Runs one action.
 *
 * @since 0.1.0
 *
 * @param array $action   The action.
 * @param array $schema   The form schema.
 * @param array $values   The accepted values.
 * @param int   $entry_id The stored entry.
 * @param int   $form_id  The form.
 * @param array $request  The raw request.
 * @return mixed|WP_Error
 */
function atf_run_action( $action, $schema, $values, $entry_id, $form_id, $request ) {
	$context = array(
		'schema'   => $schema,
		'values'   => $values,
		'form_id'  => $form_id,
		'entry_id' => $entry_id,
		'entry'    => $entry_id ? atf_prepare_entry( $entry_id ) : array(),
		'format'   => 'text',
	);

	switch ( $action['type'] ) {
		case 'create_post':
			return atf_action_create_post( $action['settings'], $context );

		case 'register_user':
			return atf_action_register_user( $action['settings'], $context, $request );

		case 'update_user_meta':
			return atf_action_update_user_meta( $action['settings'], $context );

		case 'webhook':
			return atf_action_webhook( $action['settings'], $context );
	}

	/**
	 * Runs an action type this plugin does not know.
	 *
	 * The extension point for a plugin adding its own post-submit behaviour --
	 * push to a CRM, create a calendar event, charge a card.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed  $result  Null until something handles it.
	 * @param array  $action  The action.
	 * @param array  $context The merge-tag context.
	 */
	return apply_filters( 'atf_run_action', null, $action, $context );
}

/**
 * Creates a post from a submission.
 *
 * The post type is constrained to what the site has explicitly allowed rather
 * than to whatever the schema says. A form's settings are editable by anyone
 * with `atf_edit_forms`, which is a lower bar than "may publish to any post
 * type" -- without this constraint an editor could build a form that publishes
 * to a type they cannot otherwise touch.
 *
 * @since 0.1.0
 *
 * @param array $settings The action's settings.
 * @param array $context  The merge-tag context.
 * @return int|WP_Error The new post id.
 */
function atf_action_create_post( $settings, $context ) {
	$type = isset( $settings['postType'] ) ? sanitize_key( $settings['postType'] ) : 'post';

	/**
	 * Filters which post types a form may create.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $types   Post type slugs.
	 * @param array    $context The merge-tag context.
	 */
	$allowed = apply_filters( 'atf_creatable_post_types', array( 'post', 'page' ), $context );

	if ( ! in_array( $type, $allowed, true ) ) {
		return new WP_Error( 'atf_post_type', __( 'That post type cannot be created from a form.', 'allterrain-forms' ) );
	}

	$status = isset( $settings['status'] ) ? sanitize_key( $settings['status'] ) : 'draft';

	// Anything a stranger submits is a draft or pending unless the site says
	// otherwise. A form that publishes straight to the front page is a
	// defacement waiting to happen, so `publish` has to be asked for twice.
	if ( ! in_array( $status, array( 'draft', 'pending', 'private', 'publish' ), true ) ) {
		$status = 'draft';
	}

	if ( 'publish' === $status ) {
		/**
		 * Filters whether a form may publish a post immediately.
		 *
		 * @since 0.1.0
		 *
		 * @param bool  $allow   Whether immediate publishing is allowed.
		 * @param array $context The merge-tag context.
		 */
		if ( ! apply_filters( 'atf_allow_direct_publish', false, $context ) ) {
			$status = 'pending';
		}
	}

	$postarr = array(
		'post_type'    => $type,
		'post_status'  => $status,
		'post_title'   => atf_replace_merge_tags( isset( $settings['title'] ) ? $settings['title'] : '', $context ),
		'post_content' => atf_replace_merge_tags( isset( $settings['content'] ) ? $settings['content'] : '{all_fields}', $context ),
		'post_author'  => get_current_user_id(),
	);

	if ( '' === trim( $postarr['post_title'] ) ) {
		$postarr['post_title'] = get_the_title( $context['form_id'] );
	}

	// Not `wp_kses_post()`: the content is assembled from merge tags whose values
	// are already sanitised per field, and the template around them is
	// author-supplied. Running it through `wp_kses_post()` respects the *current
	// user's* capabilities, which for a logged-out visitor strips more than the
	// author intended.
	$post_id = wp_insert_post( wp_slash( $postarr ), true );

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	if ( ! empty( $settings['meta'] ) && is_array( $settings['meta'] ) ) {
		foreach ( $settings['meta'] as $key => $template ) {
			$key = sanitize_key( $key );

			// A leading underscore hides meta from the editor's custom-fields
			// box, and a form should not be able to write into a protected key
			// another plugin relies on.
			if ( '' === $key || '_' === $key[0] ) {
				continue;
			}

			update_post_meta( $post_id, $key, atf_replace_merge_tags( (string) $template, $context ) );
		}
	}

	if ( $context['entry_id'] ) {
		update_post_meta( $context['entry_id'], '_atf_created_post', $post_id );
	}

	/**
	 * Fires after a form creates a post.
	 *
	 * @since 0.1.0
	 *
	 * @param int   $post_id The new post.
	 * @param array $context The merge-tag context.
	 */
	do_action( 'atf_post_created', $post_id, $context );

	return $post_id;
}

/**
 * Registers a user from a submission.
 *
 * The role is constrained the same way post types are, and for the same reason
 * -- more sharply, because the failure mode here is a public form that hands out
 * administrator accounts. The allow-list defaults to the site's default role
 * only, and raising it is a deliberate act.
 *
 * @since 0.1.0
 *
 * @param array $settings The action's settings.
 * @param array $context  The merge-tag context.
 * @param array $request  The raw request, where a password still exists.
 * @return int|WP_Error The new user id.
 */
function atf_action_register_user( $settings, $context, $request ) {
	if ( ! get_option( 'users_can_register' ) ) {
		/**
		 * Filters whether a form may register users while registration is off.
		 *
		 * @since 0.1.0
		 *
		 * @param bool  $allow   Whether to allow it.
		 * @param array $context The merge-tag context.
		 */
		if ( ! apply_filters( 'atf_allow_registration', false, $context ) ) {
			return new WP_Error( 'atf_registration_closed', __( 'Registration is closed on this site.', 'allterrain-forms' ) );
		}
	}

	$email = atf_replace_merge_tags( isset( $settings['email'] ) ? $settings['email'] : '', $context );

	if ( ! is_email( $email ) ) {
		return new WP_Error( 'atf_registration_email', __( 'A valid email address is needed to register.', 'allterrain-forms' ) );
	}

	if ( email_exists( $email ) ) {
		return new WP_Error( 'atf_registration_exists', __( 'There is already an account with that email address.', 'allterrain-forms' ) );
	}

	$login = atf_replace_merge_tags( isset( $settings['login'] ) ? $settings['login'] : '', $context );
	$login = sanitize_user( '' !== $login ? $login : $email, true );

	if ( '' === $login || username_exists( $login ) ) {
		// Derived from the address rather than refused, because a collision on a
		// generated username is not something the visitor can act on.
		$login = sanitize_user( current( explode( '@', $email ) ) . wp_rand( 100, 999 ), true );
	}

	$default_role = (string) get_option( 'default_role', 'subscriber' );
	$role         = isset( $settings['role'] ) ? sanitize_key( $settings['role'] ) : $default_role;

	/**
	 * Filters which roles a form may assign to a newly registered user.
	 *
	 * Defaults to the site's own default role and nothing else. Anything added
	 * here is a role a stranger can obtain by filling in a form.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $roles   Role slugs.
	 * @param array    $context The merge-tag context.
	 */
	$allowed_roles = apply_filters( 'atf_assignable_roles', array( $default_role ), $context );

	if ( ! in_array( $role, $allowed_roles, true ) ) {
		$role = $default_role;
	}

	// The password is read from the request, not from the values: password
	// fields are stripped before storage, so by the time an entry exists there
	// is nothing left to read.
	$password = '';

	if ( ! empty( $settings['passwordField'] ) && isset( $request['atf'][ $settings['passwordField'] ] ) ) {
		$password = (string) $request['atf'][ $settings['passwordField'] ];
	}

	if ( '' === $password ) {
		$password = wp_generate_password( 20, true, true );
	}

	$user_id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => $email,
			'user_pass'  => $password,
			'role'       => $role,
			'first_name' => atf_replace_merge_tags( isset( $settings['firstName'] ) ? $settings['firstName'] : '', $context ),
			'last_name'  => atf_replace_merge_tags( isset( $settings['lastName'] ) ? $settings['lastName'] : '', $context ),
		)
	);

	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	if ( ! empty( $settings['notify'] ) ) {
		wp_new_user_notification( $user_id, null, 'both' );
	}

	if ( $context['entry_id'] ) {
		update_post_meta( $context['entry_id'], '_atf_created_user', $user_id );
	}

	/**
	 * Fires after a form registers a user.
	 *
	 * @since 0.1.0
	 *
	 * @param int   $user_id The new user.
	 * @param array $context The merge-tag context.
	 */
	do_action( 'atf_user_registered', $user_id, $context );

	return $user_id;
}

/**
 * Writes submitted values into the current user's meta.
 *
 * Only for a logged-in user, and only into unprotected keys. A form that could
 * write `wp_capabilities` would be a privilege-escalation route open to every
 * subscriber on the site.
 *
 * @since 0.1.0
 *
 * @param array $settings The action's settings.
 * @param array $context  The merge-tag context.
 * @return true|WP_Error
 */
function atf_action_update_user_meta( $settings, $context ) {
	$user_id = get_current_user_id();

	if ( ! $user_id ) {
		return new WP_Error( 'atf_not_logged_in', __( 'Nobody is logged in to update.', 'allterrain-forms' ) );
	}

	$map = isset( $settings['meta'] ) && is_array( $settings['meta'] ) ? $settings['meta'] : array();

	$protected = array( 'wp_capabilities', 'wp_user_level', 'user_pass', 'session_tokens', 'default_password_nag' );

	foreach ( $map as $key => $template ) {
		$key = sanitize_key( $key );

		if ( '' === $key || '_' === $key[0] || in_array( $key, $protected, true ) ) {
			continue;
		}

		if ( is_protected_meta( $key, 'user' ) ) {
			continue;
		}

		update_user_meta( $user_id, $key, atf_replace_merge_tags( (string) $template, $context ) );
	}

	return true;
}

/**
 * Posts a submission to a URL.
 *
 * The body is JSON: the entry, the form, and every value keyed by field id and
 * by label. Both keyings, because the id is stable and the label is what a
 * person configuring the receiving end will recognise.
 *
 * Signed with HMAC-SHA256 when a secret is configured, in an `X-ATF-Signature`
 * header, so the receiver can prove the request came from this site.
 *
 * @since 0.1.0
 *
 * @param array $settings The action's settings.
 * @param array $context  The merge-tag context.
 * @return array|WP_Error The response, or the failure.
 */
function atf_action_webhook( $settings, $context ) {
	$url = isset( $settings['url'] ) ? esc_url_raw( atf_replace_merge_tags( (string) $settings['url'], $context ) ) : '';

	if ( '' === $url || ! wp_http_validate_url( $url ) ) {
		return new WP_Error( 'atf_webhook_url', __( 'The webhook URL is not usable.', 'allterrain-forms' ) );
	}

	$fields = array();

	foreach ( atf_input_fields( $context['schema'] ) as $field ) {
		if ( 'password' === $field['type'] ) {
			continue;
		}

		$value = isset( $context['values'][ $field['id'] ] ) ? $context['values'][ $field['id'] ] : '';

		$fields[ $field['id'] ] = array(
			'label'     => $field['label'],
			'type'      => $field['type'],
			'value'     => $value,
			'formatted' => atf_format_field_value( $value, $field, 'email' ),
		);
	}

	$payload = array(
		'form'   => array(
			'id'    => $context['form_id'],
			'title' => get_the_title( $context['form_id'] ),
		),
		'entry'  => array(
			'id'   => $context['entry_id'],
			'date' => current_time( 'c' ),
		),
		'fields' => $fields,
		'site'   => home_url(),
	);

	/**
	 * Filters a webhook payload before it is sent.
	 *
	 * @since 0.1.0
	 *
	 * @param array $payload The payload.
	 * @param array $context The merge-tag context.
	 */
	$payload = apply_filters( 'atf_webhook_payload', $payload, $context );

	$body    = wp_json_encode( $payload );
	$headers = array( 'Content-Type' => 'application/json' );

	if ( ! empty( $settings['secret'] ) ) {
		$headers['X-ATF-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, (string) $settings['secret'] );
	}

	$response = wp_remote_post(
		$url,
		array(
			// Short, and blocking. A webhook that hangs would hold the visitor
			// on a spinner; five seconds is long enough for a healthy endpoint
			// and short enough that a sick one is only an annoyance.
			'timeout'  => 5,
			'headers'  => $headers,
			'body'     => $body,
			'blocking' => true,
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error(
			'atf_webhook_failed',
			sprintf(
				/* translators: %d: HTTP status code. */
				__( 'The webhook answered with status %d.', 'allterrain-forms' ),
				$code
			)
		);
	}

	return $response;
}

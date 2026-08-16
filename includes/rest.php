<?php
/**
 * The REST API.
 *
 * Two audiences with opposite trust levels, and the split runs through every
 * route here.
 *
 * The **builder** routes -- forms, themes, entries, analytics -- require
 * `atf_edit_forms` or `atf_read_entries` and are called by an authenticated
 * admin with a nonce.
 *
 * The **submit** route is public by definition. It is the only route with
 * `permission_callback` returning true, and everything downstream of it treats
 * its input as hostile. It is also the one route a logged-out visitor can reach,
 * so it does its own rate limiting rather than relying on anything upstream.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'atf_register_rest_routes' );

/**
 * Registers every route.
 *
 * @since 0.1.0
 *
 * @return void
 */
function atf_register_rest_routes() {
	$ns = ATF_REST_NAMESPACE;

	register_rest_route(
		$ns,
		'/submit',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'atf_rest_submit',
			// Public on purpose: this is how a stranger sends a form. Every
			// check that would normally live in a permission callback --
			// whether the form is open, whether the visitor may see it, whether
			// this looks like spam -- happens inside the pipeline, because they
			// all depend on which form was posted.
			'permission_callback' => '__return_true',
			// The parameter is `atf_form_id`, because the bundle posts the
			// form's own `FormData` wholesale and that is the name of the hidden
			// input the renderer emits. Declaring `form_id` here instead makes
			// WordPress reject every real submission with "Missing parameter(s)"
			// *before* the callback runs -- a failure no test that calls
			// `atf_process_submission()` directly can ever see.
			'args'                => array(
				'atf_form_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		)
	);

	register_rest_route(
		$ns,
		'/resume',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'atf_rest_save_partial',
			// Public for the same reason `/submit` is: a half-finished form
			// belongs to a visitor who has no account. The nonce is checked in
			// the handler, and the form must have the feature switched on.
			'permission_callback' => '__return_true',
			// `atf_form_id`, for the same reason as `/submit` above: the bundle
			// posts the form's own FormData.
			'args'                => array(
				'atf_form_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		)
	);

	register_rest_route(
		$ns,
		'/forms',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'atf_rest_list_forms',
				'permission_callback' => 'atf_rest_can_edit',
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'atf_rest_create_form',
				'permission_callback' => 'atf_rest_can_edit',
			),
		)
	);

	register_rest_route(
		$ns,
		'/forms/(?P<id>\d+)',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'atf_rest_get_form',
				'permission_callback' => 'atf_rest_can_edit',
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => 'atf_rest_update_form',
				'permission_callback' => 'atf_rest_can_edit',
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => 'atf_rest_delete_form',
				'permission_callback' => 'atf_rest_can_edit',
			),
		)
	);

	register_rest_route(
		$ns,
		'/forms/(?P<id>\d+)/duplicate',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'atf_rest_duplicate_form',
			'permission_callback' => 'atf_rest_can_edit',
		)
	);

	register_rest_route(
		$ns,
		'/forms/(?P<id>\d+)/preview',
		array(
			// POST, not GET: the preview's whole purpose is to render a schema
			// that has not been saved yet, so the schema travels in the body.
			// Registering this as READABLE while every caller posts produces
			// "No route was found matching the URL and request method" — a 404
			// that reads like a missing route rather than a wrong verb.
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'atf_rest_preview_form',
			'permission_callback' => 'atf_rest_can_edit',
		)
	);

	register_rest_route(
		$ns,
		'/forms/(?P<id>\d+)/merge-tags',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'atf_rest_merge_tags',
			'permission_callback' => 'atf_rest_can_edit',
		)
	);

	register_rest_route(
		$ns,
		'/forms/(?P<id>\d+)/analytics',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'atf_rest_analytics',
			'permission_callback' => 'atf_rest_can_read_entries',
			'args'                => array(
				'dimension' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_key',
				),
			),
		)
	);

	// The demo-data tools. Gated twice over: developer mode says "show me these",
	// `atf_edit_forms` says "you may use them", and the two are not the same
	// question -- see `includes/dev-mode.php`.
	register_rest_route(
		$ns,
		'/demo',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'atf_rest_demo_status',
				'permission_callback' => 'atf_rest_can_use_developer_tools',
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'atf_rest_demo_seed',
				'permission_callback' => 'atf_rest_can_use_developer_tools',
				'args'                => array(
					'count' => array(
						'type'     => 'integer',
						'required' => false,
					),
				),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => 'atf_rest_demo_remove',
				'permission_callback' => 'atf_rest_can_use_developer_tools',
			),
		)
	);

	register_rest_route(
		$ns,
		'/entries',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'atf_rest_list_entries',
			'permission_callback' => 'atf_rest_can_read_entries',
		)
	);

	register_rest_route(
		$ns,
		'/entries/(?P<id>\d+)',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'atf_rest_get_entry',
				'permission_callback' => 'atf_rest_can_read_entries',
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => 'atf_rest_update_entry',
				'permission_callback' => 'atf_rest_can_read_entries',
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => 'atf_rest_delete_entry',
				'permission_callback' => 'atf_rest_can_delete_entries',
			),
		)
	);

	register_rest_route(
		$ns,
		'/entries/export',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'atf_rest_export_entries',
			'permission_callback' => 'atf_rest_can_read_entries',
		)
	);

	register_rest_route(
		$ns,
		'/themes',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'atf_rest_list_themes',
				'permission_callback' => 'atf_rest_can_edit',
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'atf_rest_save_theme',
				'permission_callback' => 'atf_rest_can_edit',
			),
		)
	);

	register_rest_route(
		$ns,
		'/themes/(?P<id>\d+)',
		array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => 'atf_rest_delete_theme',
			'permission_callback' => 'atf_rest_can_edit',
		)
	);

	register_rest_route(
		$ns,
		'/config',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'atf_rest_config',
			'permission_callback' => 'atf_rest_can_edit',
		)
	);

	register_rest_route(
		$ns,
		'/track',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'atf_rest_track',
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * Whether the request may edit forms.
 *
 * @since 0.1.0
 *
 * @return true|WP_Error
 */
function atf_rest_can_edit() {
	if ( atf_can_edit_forms() ) {
		return true;
	}

	return new WP_Error( 'atf_forbidden', __( 'You cannot edit forms.', 'allterrain-forms' ), array( 'status' => rest_authorization_required_code() ) );
}

/**
 * Whether the request may read entries.
 *
 * @since 0.1.0
 *
 * @param WP_REST_Request $request The request.
 * @return true|WP_Error
 */
function atf_rest_can_read_entries( $request ) {
	$form_id = (int) $request->get_param( 'form_id' );

	if ( atf_can_read_entries( $form_id ) ) {
		return true;
	}

	return new WP_Error( 'atf_forbidden', __( 'You cannot read entries.', 'allterrain-forms' ), array( 'status' => rest_authorization_required_code() ) );
}

/**
 * Whether the request may delete entries.
 *
 * @since 0.1.0
 *
 * @return true|WP_Error
 */
function atf_rest_can_delete_entries() {
	if ( current_user_can( 'atf_delete_entries' ) ) {
		return true;
	}

	return new WP_Error( 'atf_forbidden', __( 'You cannot delete entries.', 'allterrain-forms' ), array( 'status' => rest_authorization_required_code() ) );
}

/**
 * Handles a submission.
 *
 * @since 0.1.0
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response
 */
function atf_rest_submit( $request ) {
	$form_id = absint( $request->get_param( 'atf_form_id' ) );

	$body = $request->get_params();

	// The nonce is checked here rather than in a permission callback, because a
	// public form's nonce is not an authorisation check -- it is a replay
	// deterrent, and a failure has to come back as a message the visitor can
	// act on rather than a 401 the bundle would render as a dead form.
	$nonce = isset( $body['atf_nonce'] ) ? sanitize_text_field( (string) $body['atf_nonce'] ) : '';

	if ( ! wp_verify_nonce( $nonce, 'atf_submit_' . $form_id ) ) {
		return rest_ensure_response(
			array(
				'success' => false,
				'errors'  => array(),
				'message' => __( 'This form expired. Please reload the page and try again.', 'allterrain-forms' ),
			)
		);
	}

	$files  = $request->get_file_params();
	$result = atf_process_submission( $form_id, $body, $files );

	return rest_ensure_response( $result );
}

/**
 * Saves a half-finished form and answers with the way back to it.
 *
 * @since 0.1.0
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response
 */
function atf_rest_save_partial( $request ) {
	$form_id = absint( $request->get_param( 'atf_form_id' ) );
	$body    = $request->get_params();

	$nonce = isset( $body['atf_nonce'] ) ? sanitize_text_field( (string) $body['atf_nonce'] ) : '';

	if ( ! wp_verify_nonce( $nonce, 'atf_submit_' . $form_id ) ) {
		return rest_ensure_response(
			array(
				'success' => false,
				'message' => __( 'This form expired. Please reload the page and try again.', 'allterrain-forms' ),
			)
		);
	}

	$saved = atf_save_partial(
		$form_id,
		isset( $body['atf'] ) && is_array( $body['atf'] ) ? $body['atf'] : array(),
		isset( $body[ ATF_RESUME_QUERY ] ) ? (string) $body[ ATF_RESUME_QUERY ] : ''
	);

	if ( is_wp_error( $saved ) ) {
		return rest_ensure_response(
			array(
				'success' => false,
				'message' => $saved->get_error_message(),
			)
		);
	}

	return rest_ensure_response( array_merge( array( 'success' => true ), $saved ) );
}

/**
 * Records a lightweight front-end event.
 *
 * Only `start` is accepted, and only as an increment -- there is nothing here a
 * caller can use to write arbitrary data, which matters because this is the
 * second of the two public routes.
 *
 * @since 0.1.0
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response
 */
function atf_rest_track( $request ) {
	$form_id = absint( $request->get_param( 'form_id' ) );
	$event   = sanitize_key( (string) $request->get_param( 'event' ) );

	if ( $form_id && 'start' === $event ) {
		atf_record_start( $form_id );
	}

	return rest_ensure_response( array( 'ok' => true ) );
}

/**
 * Lists forms.
 *
 * @since 0.1.0
 *
 * @return WP_REST_Response
 */
function atf_rest_list_forms() {
	$posts = get_posts(
		array(
			'post_type'        => ATF_FORM_TYPE,
			'post_status'      => array( 'publish', 'draft' ),
			'numberposts'      => 200,
			'orderby'          => 'modified',
			'order'            => 'DESC',
			'suppress_filters' => false,
		)
	);

	$forms = array();

	foreach ( $posts as $post ) {
		$schema = atf_get_form_schema( $post->ID );
		$stats  = atf_get_stats( $post->ID );

		$forms[] = array(
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'status'      => $post->post_status,
			'modified'    => $post->post_modified_gmt,
			'fields'      => count( atf_input_fields( $schema ) ),
			'theme'       => $schema['settings']['theme'],
			'entries'     => atf_count_entries( $post->ID ),
			'unread'      => atf_count_entries_by_status( $post->ID, ATF_STATUS_UNREAD ),
			'views'       => $stats['views'],
			'submissions' => $stats['submissions'],
			'shortcode'   => sprintf( '[allterrain_form id="%d"]', $post->ID ),
		);
	}

	return rest_ensure_response( $forms );
}

/**
 * Creates a form, optionally from a template.
 *
 * @since 0.1.0
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function atf_rest_create_form( $request ) {
	$template = sanitize_key( (string) $request->get_param( 'template' ) );
	$title    = sanitize_text_field( (string) $request->get_param( 'title' ) );

	$form_id = atf_create_form_from_template( '' !== $template ? $template : 'blank', $title );

	if ( is_wp_error( $form_id ) ) {
		return $form_id;
	}

	// An imported schema overrides the template's, so import and create-from-
	// template are the same route and there is one code path for both.
	$imported = $request->get_param( 'schema' );

	if ( $imported ) {
		atf_save_form_schema( $form_id, $imported );
	}

	return atf_rest_form_response( $form_id );
}

/**
 * Reads one form.
 *
 * @since 0.1.0
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function atf_rest_get_form( $request ) {
	return atf_rest_form_response( absint( $request->get_param( 'id' ) ) );
}

/**
 * Saves a form's title and schema.
 *
 * @since 0.1.0
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function atf_rest_update_form( $request ) {
	$form_id = absint( $request->get_param( 'id' ) );
	$post    = $form_id ? get_post( $form_id ) : null;

	if ( ! $post || ATF_FORM_TYPE !== $post->post_type ) {
		return new WP_Error( 'atf_form_missing', __( 'That form does not exist.', 'allterrain-forms' ), array( 'status' => 404 ) );
	}

	$title = $request->get_param( 'title' );

	if ( null !== $title ) {
		wp_update_post(
			array(
				'ID'         => $form_id,
				'post_title' => sanitize_text_field( (string) $title ),
			)
		);
	}

	$schema = $request->get_param( 'schema' );

	if ( null !== $schema ) {
		atf_save_form_schema( $form_id, $schema );
	}

	return atf_rest_form_response( $form_id );
}

/**
 * Deletes a form.
 *
 * Its entries are left alone and go to the trash with it only if the site says
 * so -- deleting a form is usually a tidying-up action, and taking two years of
 * submissions with it is not what anybody means by it.
 *
 * @since 0.1.0
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function atf_rest_delete_form( $request ) {
	$form_id = absint( $request->get_param( 'id' ) );
	$post    = $form_id ? get_post( $form_id ) : null;

	if ( ! $post || ATF_FORM_TYPE !== $post->post_type ) {
		return new WP_Error( 'atf_form_missing', __( 'That form does not exist.', 'allterrain-forms' ), array( 'status' => 404 ) );
	}

	wp_trash_post( $form_id );

	/**
	 * Fires after a form is trashed.
	 *
	 * @since 0.1.0
	 *
	 * @param int $form_id The form.
	 */
	do_action( 'atf_form_deleted', $form_id );

	return rest_ensure_response( array( 'deleted' => true ) );
}

/**
 * Duplicates a form.
 *
 * @since 0.1.0
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function atf_rest_duplicate_form( $request ) {
	$form_id = absint( $request->get_param( 'id' ) );
	$post    = $form_id ? get_post( $form_id ) : null;

	if ( ! $post || ATF_FORM_TYPE !== $post->post_type ) {
		return new WP_Error( 'atf_form_missing', __( 'That form does not exist.', 'allterrain-forms' ), array( 'status' => 404 ) );
	}

	$copy_id = wp_insert_post(
		array(
			'post_type'   => ATF_FORM_TYPE,
			/* translators: %s: the original form's title. */
			'post_title'  => sprintf( __( '%s (copy)', 'allterrain-forms' ), $post->post_title ),
			'post_status' => 'publish',
			'post_author' => get_current_user_id(),
		),
		true
	);

	if ( is_wp_error( $copy_id ) ) {
		return $copy_id;
	}

	atf_save_form_schema( $copy_id, atf_get_form_schema( $form_id ) );

	return atf_rest_form_response( $copy_id );
}

/**
 * Renders a form for the builder's preview pane.
 *
 * Takes the schema from the request rather than from storage, so the preview
 * shows unsaved edits -- which is the entire point of a preview.
 *
 * @since 0.1.0
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function atf_rest_preview_form( $request ) {
	$form_id = absint( $request->get_param( 'id' ) );
	$post    = $form_id ? get_post( $form_id ) : null;

	if ( ! $post || ATF_FORM_TYPE !== $post->post_type ) {
		return new WP_Error( 'atf_form_missing', __( 'That form does not exist.', 'allterrain-forms' ), array( 'status' => 404 ) );
	}

	$schema = $request->get_param( 'schema' );
	$theme  = sanitize_key( (string) $request->get_param( 'theme' ) );

	// The unsaved schema is applied through a filter for the duration of this
	// one render rather than written to the database, so previewing never
	// changes the stored form.
	$override = null !== $schema ? atf_normalize_schema( $schema ) : null;

	$filter = static function ( $stored, $raw ) use ( $override ) {
		return $override ? $override : $stored;
	};

	if ( $override ) {
		add_filter( 'atf_normalize_schema', $filter, 99, 2 );
	}

	$html = atf_render_form(
		$form_id,
		array(
			'preview' => true,
			'theme'   => $theme,
		)
	);

	if ( $override ) {
		remove_filter( 'atf_normalize_schema', $filter, 99 );
	}

	return rest_ensure_response( array( 'html' => $html ) );
}

/**
 * The merge tags the builder offers, for one form.
 *
 * Served rather than computed in JavaScript because half of what makes the
 * picker useful is knowing what each tag resolves to *on this site* — the
 * administrator's actual address, this form's actual questions — and the browser
 * has none of that.
 *
 * @since 0.1.0
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function atf_rest_merge_tags( $request ) {
	$form_id = absint( $request->get_param( 'id' ) );
	$post    = $form_id ? get_post( $form_id ) : null;

	if ( ! $post || ATF_FORM_TYPE !== $post->post_type ) {
		return new WP_Error( 'atf_form_missing', __( 'That form does not exist.', 'allterrain-forms' ), array( 'status' => 404 ) );
	}

	return rest_ensure_response( array( 'groups' => atf_merge_tag_catalogue( $form_id ) ) );
}

/**
 * A form, as the builder reads it.
 *
 * @since 0.1.0
 *
 * @param int $form_id The form.
 * @return WP_REST_Response|WP_Error
 */
function atf_rest_form_response( $form_id ) {
	$post = $form_id ? get_post( $form_id ) : null;

	if ( ! $post || ATF_FORM_TYPE !== $post->post_type ) {
		return new WP_Error( 'atf_form_missing', __( 'That form does not exist.', 'allterrain-forms' ), array( 'status' => 404 ) );
	}

	return rest_ensure_response(
		array(
			'id'         => $post->ID,
			'title'      => $post->post_title,
			'status'     => $post->post_status,
			'modified'   => $post->post_modified_gmt,
			'schema'     => atf_get_form_schema( $post->ID ),
			'shortcode'  => sprintf( '[allterrain_form id="%d"]', $post->ID ),
			'entries'    => atf_count_entries( $post->ID ),
			// Where the title bar's eye button points. Nonced per response
			// rather than built in the browser, because the browser has no way
			// to mint a nonce and a preview URL without one is a way to read an
			// unpublished form.
			'previewUrl' => atf_form_preview_url( $post->ID ),
		)
	);
}

/**
 * Lists entries.
 *
 * @since 0.1.0
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response
 */
function atf_rest_list_entries( $request ) {
	$status = $request->get_param( 'status' );

	$result = atf_query_entries(
		array(
			'form_id'  => absint( $request->get_param( 'form_id' ) ),
			'status'   => $status ? array_map( 'sanitize_key', (array) $status ) : array( ATF_STATUS_UNREAD, ATF_STATUS_READ ),
			'search'   => (string) $request->get_param( 'search' ),
			'page'     => max( 1, (int) $request->get_param( 'page' ) ),
			'per_page' => (int) $request->get_param( 'per_page' ) ? (int) $request->get_param( 'per_page' ) : 25,
			'after'    => (string) $request->get_param( 'after' ),
			'before'   => (string) $request->get_param( 'before' ),
			'starred'  => (bool) $request->get_param( 'starred' ),
		)
	);

	return rest_ensure_response( $result );
}

/**
 * Reads one entry, and marks it read.
 *
 * Opening an entry is what "read" means, so the status change happens here
 * rather than needing a second call from the UI.
 *
 * @since 0.1.0
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function atf_rest_get_entry( $request ) {
	$entry_id = absint( $request->get_param( 'id' ) );
	$entry    = atf_prepare_entry( $entry_id );

	if ( ! $entry ) {
		return new WP_Error( 'atf_entry_missing', __( 'That entry does not exist.', 'allterrain-forms' ), array( 'status' => 404 ) );
	}

	if ( ATF_STATUS_UNREAD === $entry['status'] ) {
		atf_set_entry_status( $entry_id, ATF_STATUS_READ );
		$entry['status'] = ATF_STATUS_READ;
	}

	return rest_ensure_response( $entry );
}

/**
 * Changes an entry's status or star.
 *
 * @since 0.1.0
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function atf_rest_update_entry( $request ) {
	$entry_id = absint( $request->get_param( 'id' ) );
	$status   = $request->get_param( 'status' );
	$starred  = $request->get_param( 'starred' );

	if ( null !== $status ) {
		$result = atf_set_entry_status( $entry_id, sanitize_key( (string) $status ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	if ( null !== $starred ) {
		$result = atf_star_entry( $entry_id, (bool) $starred );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	return rest_ensure_response( atf_prepare_entry( $entry_id ) );
}

/**
 * Deletes an entry for good.
 *
 * @since 0.1.0
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function atf_rest_delete_entry( $request ) {
	$entry_id = absint( $request->get_param( 'id' ) );
	$post     = $entry_id ? get_post( $entry_id ) : null;

	if ( ! $post || ATF_ENTRY_TYPE !== $post->post_type ) {
		return new WP_Error( 'atf_entry_missing', __( 'That entry does not exist.', 'allterrain-forms' ), array( 'status' => 404 ) );
	}

	atf_delete_entry_completely( $entry_id );

	return rest_ensure_response( array( 'deleted' => true ) );
}

/**
 * Exports entries as CSV.
 *
 * Returns the CSV as a string in a JSON envelope rather than as a file download.
 * The builder is a native window inside a single-page shell, so a navigation to
 * a download URL would take the whole desktop with it; the bundle turns this
 * into a Blob and saves it locally.
 *
 * @since 0.1.0
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function atf_rest_export_entries( $request ) {
	$form_id = absint( $request->get_param( 'form_id' ) );

	$query = array(
		'status'  => array( ATF_STATUS_UNREAD, ATF_STATUS_READ ),
		'search'  => (string) $request->get_param( 'search' ),
		'after'   => (string) $request->get_param( 'after' ),
		'before'  => (string) $request->get_param( 'before' ),
		'starred' => (bool) $request->get_param( 'starred' ),
	);

	// CSV is what people open; JSON is what they migrate with.
	$format = 'json' === $request->get_param( 'format' ) ? 'json' : 'csv';

	$export = 'json'
		=== $format
			? atf_export_entries_json( $form_id, $query )
			: atf_export_entries_csv( $form_id, $query );

	if ( is_wp_error( $export ) ) {
		return $export;
	}

	return rest_ensure_response(
		array(
			'filename' => sanitize_file_name(
				get_the_title( $form_id ) . '-entries-' . gmdate( 'Y-m-d' ) . '.' . $format
			),
			'format'   => $format,
			'csv'      => $export,
		)
	);
}

/**
 * A form's analytics.
 *
 * @since 0.1.0
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response
 */
function atf_rest_analytics( $request ) {
	return rest_ensure_response(
		atf_form_analytics(
			absint( $request->get_param( 'id' ) ),
			(string) $request->get_param( 'dimension' )
		)
	);
}

/**
 * Every theme, with its resolved tokens.
 *
 * @since 0.1.0
 *
 * @return WP_REST_Response
 */
function atf_rest_list_themes() {
	$themes = array();

	foreach ( atf_get_themes() as $slug => $theme ) {
		$themes[] = array(
			'slug'        => $slug,
			'label'       => $theme['label'],
			'description' => $theme['description'],
			'custom'      => $theme['custom'],
			'dark'        => ! empty( $theme['dark'] ),
			'id'          => $theme['id'],
			'tokens'      => $theme['tokens'],
			'resolved'    => atf_resolve_tokens( $slug ),
		);
	}

	return rest_ensure_response( $themes );
}

/**
 * Saves a user-made theme.
 *
 * @since 0.1.0
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function atf_rest_save_theme( $request ) {
	$saved = atf_save_theme(
		array(
			'id'          => absint( $request->get_param( 'id' ) ),
			'label'       => (string) $request->get_param( 'label' ),
			'slug'        => (string) $request->get_param( 'slug' ),
			'description' => (string) $request->get_param( 'description' ),
			'tokens'      => (array) $request->get_param( 'tokens' ),
		)
	);

	if ( is_wp_error( $saved ) ) {
		return $saved;
	}

	$saved['resolved'] = atf_resolve_tokens( $saved['slug'] );

	return rest_ensure_response( $saved );
}

/**
 * Deletes a user-made theme.
 *
 * @since 0.1.0
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function atf_rest_delete_theme( $request ) {
	$result = atf_delete_theme( absint( $request->get_param( 'id' ) ) );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( array( 'deleted' => true ) );
}

/**
 * Everything the builder needs to draw itself.
 *
 * One request rather than five, because the builder cannot render its palette,
 * its theme picker or its inspector until it has all of this -- and five
 * round trips is five chances to paint half a UI.
 *
 * @since 0.1.0
 *
 * @return WP_REST_Response
 */
function atf_rest_config() {
	$types = array();

	foreach ( atf_get_field_types() as $slug => $definition ) {
		$types[] = array(
			'type'        => $slug,
			'label'       => $definition['label'],
			'description' => $definition['description'],
			'group'       => $definition['group'],
			'icon'        => $definition['icon'],
			'input'       => (bool) $definition['input'],
			'value'       => $definition['value'],
			'choices'     => (bool) $definition['choices'],
			'supports'    => array_values( $definition['supports'] ),
			'settings'    => $definition['settings'],
			// The parts a composite field can show. Sent rather than hard-coded in
			// the bundle because both lists are filterable, and a builder offering
			// a fixed five while the renderer draws a filtered seven is a builder
			// that quietly cannot reach two of them.
			'parts'       => atf_field_type_parts( $slug ),
		);
	}

	$tokens = array();

	foreach ( atf_theme_token_defaults() as $name => $default ) {
		$tokens[] = array_merge(
			array(
				'token'   => $name,
				'default' => $default,
			),
			atf_theme_token_control( $name )
		);
	}

	$templates = array();

	foreach ( atf_form_templates() as $slug => $template ) {
		$templates[] = array(
			'slug'        => $slug,
			'label'       => $template['label'],
			'description' => $template['description'],
			'icon'        => $template['icon'],
		);
	}

	return rest_ensure_response(
		array(
			'fieldTypes' => $types,
			'groups'     => atf_field_groups(),
			'tokens'     => $tokens,
			'templates'  => $templates,
			'operators'  => atf_logic_operator_labels(),
			'countries'  => atf_countries(),
			'roles'      => wp_roles()->get_names(),
			'canDelete'  => current_user_can( 'atf_delete_entries' ),
			'adminUrl'   => admin_url(),
		)
	);
}

/**
 * Human labels for the logic operators.
 *
 * Kept beside the REST config rather than in `logic.php`, because the evaluator
 * has no business knowing how an operator is described in a dropdown.
 *
 * @since 0.1.0
 *
 * @return array<string, string>
 */
function atf_logic_operator_labels() {
	return array(
		'is'            => __( 'is', 'allterrain-forms' ),
		'is_not'        => __( 'is not', 'allterrain-forms' ),
		'contains'      => __( 'contains', 'allterrain-forms' ),
		'not_contains'  => __( 'does not contain', 'allterrain-forms' ),
		'starts_with'   => __( 'starts with', 'allterrain-forms' ),
		'ends_with'     => __( 'ends with', 'allterrain-forms' ),
		'greater'       => __( 'is greater than', 'allterrain-forms' ),
		'less'          => __( 'is less than', 'allterrain-forms' ),
		'greater_equal' => __( 'is at least', 'allterrain-forms' ),
		'less_equal'    => __( 'is at most', 'allterrain-forms' ),
		'empty'         => __( 'is empty', 'allterrain-forms' ),
		'not_empty'     => __( 'is not empty', 'allterrain-forms' ),
	);
}

/**
 * Whether the request may use the developer tools.
 *
 * Two questions, not one. Developer mode is a preference and answers "show me
 * these"; `atf_edit_forms` is a capability and answers "you may use them".
 * Checking only the preference would be an authorisation check that any user who
 * can write their own meta could pass.
 *
 * A user without the capability gets the authorisation code, so a client can tell
 * "you are not allowed" from "this is switched off". Developer mode being off is
 * a plain 404: the route is not there for you, and saying so invites nothing.
 *
 * @since 0.1.0
 *
 * @return true|WP_Error
 */
function atf_rest_can_use_developer_tools() {
	if ( ! atf_can_edit_forms() ) {
		return new WP_Error(
			'atf_forbidden',
			__( 'You cannot edit forms.', 'allterrain-forms' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	if ( ! atf_developer_mode() ) {
		return new WP_Error(
			'atf_developer_mode_off',
			__( 'Developer mode is off.', 'allterrain-forms' ),
			array( 'status' => 404 )
		);
	}

	return true;
}

/**
 * What demo data exists.
 *
 * @since 0.1.0
 *
 * @return WP_REST_Response
 */
function atf_rest_demo_status() {
	return rest_ensure_response( atf_demo_status() );
}

/**
 * Generates a chunk of demo submissions.
 *
 * Returns the status rather than a bare success, because the client's whole job
 * is to call this until nothing is left and it needs the count to know.
 *
 * @since 0.1.0
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function atf_rest_demo_seed( $request ) {
	$count = (int) $request->get_param( 'count' );

	return rest_ensure_response( atf_demo_seed( $count > 0 ? $count : ATF_DEMO_CHUNK ) );
}

/**
 * Removes every generated form and entry.
 *
 * @since 0.1.0
 *
 * @return WP_REST_Response|WP_Error
 */
function atf_rest_demo_remove() {
	return rest_ensure_response( atf_demo_remove() );
}

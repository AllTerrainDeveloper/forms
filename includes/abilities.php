<?php
/**
 * Abilities — the plugin's surface for AI agents.
 *
 * WordPress's Abilities API describes what a site can do in a form agents and
 * MCP clients consume: a name, a description worth reading, JSON Schemas both
 * ways, and a permission gate. Everything here is a thin adapter over functions
 * the REST API and the windows already use — an ability never grows logic of
 * its own, because an agent and a human clicking the same button must get the
 * same behaviour.
 *
 * The descriptions are written for the agent, not for us. They are the whole
 * interface: an agent decides *whether* to call an ability by reading them, so
 * each says what it does, what it returns and when to prefer it — the way a
 * good tool definition does.
 *
 * On a WordPress older than the Abilities API the two hooks below simply never
 * fire, and the plugin behaves as if this file did not exist.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_categories_init', 'atf_register_ability_category' );
add_action( 'wp_abilities_api_init', 'atf_register_abilities' );

/**
 * Registers the plugin's ability category.
 *
 * @since 0.3.0
 *
 * @return void
 */
function atf_register_ability_category() {
	wp_register_ability_category(
		'allterrain-forms',
		array(
			'label'       => __( 'AllTerrain Forms', 'allterrain-forms' ),
			'description' => __( 'Build forms, read and submit entries, and report on responses.', 'allterrain-forms' ),
		)
	);
}

/**
 * A form's fields distilled to what an agent needs to reason about them.
 *
 * The raw schema carries layout, theming and builder state; an agent composing
 * a submission or summarising answers needs the questions. Choice values are
 * included because entries store the value, not the label.
 *
 * @since 0.3.0
 *
 * @param array $schema The form schema.
 * @return array[]
 */
function atf_ability_fields( $schema ) {
	$fields = array();

	foreach ( atf_input_fields( $schema ) as $field ) {
		$distilled = array(
			'id'       => $field['id'],
			'type'     => $field['type'],
			'label'    => $field['label'],
			'required' => ! empty( $field['required'] ),
		);

		if ( '' !== $field['hint'] ) {
			$distilled['hint'] = wp_strip_all_tags( $field['hint'] );
		}

		if ( ! empty( $field['choices'] ) ) {
			$distilled['choices'] = array_map(
				static function ( $choice ) {
					return array(
						'value' => $choice['value'],
						'label' => $choice['label'],
					);
				},
				$field['choices']
			);
		}

		$fields[] = $distilled;
	}

	return $fields;
}

/**
 * One form as an ability result.
 *
 * @since 0.3.0
 *
 * @param WP_Post $post The form post.
 * @return array
 */
function atf_ability_form( $post ) {
	$schema = atf_get_form_schema( $post->ID );

	return array(
		'id'        => $post->ID,
		'title'     => $post->post_title,
		'status'    => $post->post_status,
		'theme'     => $schema['settings']['theme'],
		'shortcode' => sprintf( '[allterrain_form id="%d"]', $post->ID ),
		'entries'   => atf_count_entries( $post->ID ),
		'fields'    => atf_ability_fields( $schema ),
	);
}

/**
 * Registers every ability.
 *
 * @since 0.3.0
 *
 * @return void
 */
function atf_register_abilities() {
	$category = 'allterrain-forms';

	$read_entries = static function () {
		return atf_can_read_entries();
	};

	$edit_forms = static function () {
		return atf_can_edit_forms();
	};

	$read_anything = static function () {
		return atf_can_read_entries() || atf_can_edit_forms();
	};

	wp_register_ability(
		'allterrain-forms/list-forms',
		array(
			'label'               => __( 'List forms', 'allterrain-forms' ),
			'description'         => __( 'Lists every form on the site with its id, title, status, theme, shortcode, entry count and full field list (field ids, types, labels, required flags and choices). Call this first: the ids and field ids it returns are what every other forms ability takes as input.', 'allterrain-forms' ),
			'category'            => $category,
			'permission_callback' => $read_anything,
			'execute_callback'    => 'atf_ability_list_forms',
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'meta'                => array(
				'public'      => true,
				'annotations' => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		)
	);

	wp_register_ability(
		'allterrain-forms/get-form',
		array(
			'label'               => __( 'Get a form', 'allterrain-forms' ),
			'description'         => __( 'Reads one form: title, status, theme, embed shortcode, entry count, and every question with its field id, type, label, required flag and choices. Use it before submitting to a form or interpreting its entries — values in entries are keyed by these field ids, and choice answers store the choice value, not its label.', 'allterrain-forms' ),
			'category'            => $category,
			'permission_callback' => $read_anything,
			'execute_callback'    => 'atf_ability_get_form',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'form_id' => array(
						'type'        => 'integer',
						'description' => __( 'The form id, from list-forms.', 'allterrain-forms' ),
					),
				),
				'required'   => array( 'form_id' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'meta'                => array(
				'public'      => true,
				'annotations' => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		)
	);

	wp_register_ability(
		'allterrain-forms/list-field-types',
		array(
			'label'               => __( 'List field types', 'allterrain-forms' ),
			'description'         => __( 'The vocabulary for building forms: every registered field type with its slug, label, description, palette group and the shape of value it stores. Read this before create-form so the fields you compose use types that exist.', 'allterrain-forms' ),
			'category'            => $category,
			'permission_callback' => $edit_forms,
			'execute_callback'    => 'atf_ability_list_field_types',
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'meta'                => array(
				'public'      => true,
				'annotations' => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		)
	);

	wp_register_ability(
		'allterrain-forms/create-form',
		array(
			'label'               => __( 'Create a form', 'allterrain-forms' ),
			'description'         => __( 'Creates a new form and returns it with its shortcode, ready to paste into any page or post. Pass a title and, optionally, a list of fields — each needs a type (from list-field-types) and a label, and may carry required, hint, placeholder and choices. Field ids are issued automatically; anything unspecified gets a sensible default. Without fields you get the named template (default: blank).', 'allterrain-forms' ),
			'category'            => $category,
			'permission_callback' => $edit_forms,
			'execute_callback'    => 'atf_ability_create_form',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'title'    => array(
						'type'        => 'string',
						'description' => __( 'The form title.', 'allterrain-forms' ),
					),
					'template' => array(
						'type'        => 'string',
						'description' => __( 'Optional template slug to start from, e.g. contact. Ignored when fields are given.', 'allterrain-forms' ),
					),
					'fields'   => array(
						'type'        => 'array',
						'description' => __( 'The questions, in order. Each: { type, label, required?, hint?, placeholder?, choices?: [{ label, value? }] }.', 'allterrain-forms' ),
						'items'       => array( 'type' => 'object' ),
					),
				),
				'required'   => array( 'title' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'meta'                => array(
				'public'      => true,
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	wp_register_ability(
		'allterrain-forms/set-form-theme',
		array(
			'label'               => __( 'Set a form’s theme', 'allterrain-forms' ),
			'description'         => __( 'Applies one of the installed themes to a form — ten ship built in (clean, midnight, glass, brutal, paper, neon, terminal, soft, editorial, holo) plus any the site has saved. Returns the form with the theme applied. The change is visible wherever the form is embedded, immediately.', 'allterrain-forms' ),
			'category'            => $category,
			'permission_callback' => $edit_forms,
			'execute_callback'    => 'atf_ability_set_form_theme',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'form_id' => array( 'type' => 'integer' ),
					'theme'   => array(
						'type'        => 'string',
						'description' => __( 'The theme slug.', 'allterrain-forms' ),
					),
				),
				'required'   => array( 'form_id', 'theme' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'meta'                => array(
				'public'      => true,
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'allterrain-forms/list-entries',
		array(
			'label'               => __( 'List entries', 'allterrain-forms' ),
			'description'         => __( 'Queries a form’s submissions. Each entry returns its answers twice: raw values keyed by field id, and a human-readable line per question with the label resolved — use the readable form for summaries and the raw form for computation. Filter by free-text search, date range (after/before, Y-m-d), status (atf-unread, atf-read, atf-spam) and starred; paginate with page/per_page.', 'allterrain-forms' ),
			'category'            => $category,
			'permission_callback' => $read_entries,
			'execute_callback'    => 'atf_ability_list_entries',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'form_id'  => array( 'type' => 'integer' ),
					'search'   => array( 'type' => 'string' ),
					'status'   => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'after'    => array( 'type' => 'string' ),
					'before'   => array( 'type' => 'string' ),
					'starred'  => array( 'type' => 'boolean' ),
					'page'     => array( 'type' => 'integer' ),
					'per_page' => array(
						'type'    => 'integer',
						'maximum' => 100,
					),
				),
				'required'   => array( 'form_id' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'meta'                => array(
				'public'      => true,
				'annotations' => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		)
	);

	wp_register_ability(
		'allterrain-forms/get-entry',
		array(
			'label'               => __( 'Get an entry', 'allterrain-forms' ),
			'description'         => __( 'Reads one submission in full: every question with its label, the raw stored value and a formatted human-readable value, plus the submission date and status.', 'allterrain-forms' ),
			'category'            => $category,
			'permission_callback' => $read_entries,
			'execute_callback'    => 'atf_ability_get_entry',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'entry_id' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'entry_id' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'meta'                => array(
				'public'      => true,
				'annotations' => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		)
	);

	wp_register_ability(
		'allterrain-forms/submit-form',
		array(
			'label'               => __( 'Submit a form', 'allterrain-forms' ),
			'description'         => __( 'Submits answers to a form through the same pipeline a visitor uses: availability, validation, anti-spam, storage, notification emails and confirmations all run. Values are keyed by field id (from get-form); choice fields take the choice value, checkbox groups take an array of them. On validation failure nothing is stored and the per-field errors are returned — fix them and call again.', 'allterrain-forms' ),
			'category'            => $category,
			'permission_callback' => '__return_true',
			'execute_callback'    => 'atf_ability_submit_form',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'form_id' => array( 'type' => 'integer' ),
					'values'  => array(
						'type'        => 'object',
						'description' => __( 'Field id => answer.', 'allterrain-forms' ),
					),
				),
				'required'   => array( 'form_id', 'values' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'meta'                => array(
				'public'      => true,
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	wp_register_ability(
		'allterrain-forms/form-report',
		array(
			'label'               => __( 'Form report', 'allterrain-forms' ),
			'description'         => __( 'The analytics for one form as structured data: views, starts, submissions, conversion and completion rates, a 90-day timeline, per-question answer distributions, numeric summaries, and a Net Promoter Score panel where a 0–10 question exists. Pass group_by (a choice field id) to break every question down by how a grouping question was answered. Prefer this over fetching every entry when the job is summarising.', 'allterrain-forms' ),
			'category'            => $category,
			'permission_callback' => $read_entries,
			'execute_callback'    => 'atf_ability_form_report',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'form_id'  => array( 'type' => 'integer' ),
					'group_by' => array(
						'type'        => 'string',
						'description' => __( 'Optional choice-field id to break answers down by.', 'allterrain-forms' ),
					),
				),
				'required'   => array( 'form_id' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'meta'                => array(
				'public'      => true,
				'annotations' => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		)
	);
}

/**
 * Every form, distilled.
 *
 * @since 0.3.0
 *
 * @return array[]
 */
function atf_ability_list_forms() {
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

	return array_map( 'atf_ability_form', $posts );
}

/**
 * One form, distilled.
 *
 * @since 0.3.0
 *
 * @param array $input { form_id: int }.
 * @return array|WP_Error
 */
function atf_ability_get_form( $input ) {
	$post = get_post( absint( $input['form_id'] ?? 0 ) );

	if ( ! $post || ATF_FORM_TYPE !== $post->post_type ) {
		return new WP_Error( 'atf_form_missing', __( 'That form does not exist.', 'allterrain-forms' ) );
	}

	return atf_ability_form( $post );
}

/**
 * The field-type vocabulary.
 *
 * @since 0.3.0
 *
 * @return array[]
 */
function atf_ability_list_field_types() {
	$types = array();

	foreach ( atf_get_field_types() as $slug => $definition ) {
		$types[] = array(
			'type'        => $slug,
			'label'       => $definition['label'],
			'description' => $definition['description'],
			'group'       => $definition['group'],
			'value'       => $definition['value'],
		);
	}

	return $types;
}

/**
 * Creates a form from an agent's description of it.
 *
 * The loose field shape is handed to the same normaliser every other schema
 * source uses — imports, templates, hand-written JSON — which issues ids,
 * seeds empty choice lists and coerces every setting. An agent gets no more
 * and no less latitude than a JSON import does.
 *
 * @since 0.3.0
 *
 * @param array $input { title: string, template?: string, fields?: array[] }.
 * @return array|WP_Error
 */
function atf_ability_create_form( $input ) {
	$title    = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
	$template = sanitize_key( (string) ( $input['template'] ?? '' ) );
	$fields   = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : array();

	$form_id = atf_create_form_from_template( '' !== $template ? $template : 'blank', $title );

	if ( is_wp_error( $form_id ) ) {
		return $form_id;
	}

	if ( $fields ) {
		$schema           = atf_get_form_schema( $form_id );
		$schema['fields'] = $fields;

		atf_save_form_schema( $form_id, $schema );
	}

	return atf_ability_form( get_post( $form_id ) );
}

/**
 * Applies a theme to a form.
 *
 * @since 0.3.0
 *
 * @param array $input { form_id: int, theme: string }.
 * @return array|WP_Error
 */
function atf_ability_set_form_theme( $input ) {
	$post  = get_post( absint( $input['form_id'] ?? 0 ) );
	$theme = sanitize_key( (string) ( $input['theme'] ?? '' ) );

	if ( ! $post || ATF_FORM_TYPE !== $post->post_type ) {
		return new WP_Error( 'atf_form_missing', __( 'That form does not exist.', 'allterrain-forms' ) );
	}

	// `atf_get_theme()` deliberately never fails — it falls back to Clean so a
	// deleted theme cannot break a rendered form. An agent asking for a theme
	// that does not exist deserves the error, not the fallback.
	$installed = atf_get_themes();

	if ( ! isset( $installed[ $theme ] ) ) {
		return new WP_Error( 'atf_theme_missing', __( 'That theme is not installed. list-forms shows each form’s current theme; the built-ins are clean, midnight, glass, brutal, paper, neon, terminal, soft, editorial and holo.', 'allterrain-forms' ) );
	}

	$schema                      = atf_get_form_schema( $post->ID );
	$schema['settings']['theme'] = $theme;

	atf_save_form_schema( $post->ID, $schema );

	return atf_ability_form( $post );
}

/**
 * Queries entries for an agent.
 *
 * @since 0.3.0
 *
 * @param array $input The query, per the input schema.
 * @return array { entries, total, pages }
 */
function atf_ability_list_entries( $input ) {
	return atf_query_entries(
		array(
			'form_id'  => absint( $input['form_id'] ?? 0 ),
			'search'   => sanitize_text_field( (string) ( $input['search'] ?? '' ) ),
			'status'   => isset( $input['status'] ) && is_array( $input['status'] )
				? array_map( 'sanitize_key', $input['status'] )
				: array( ATF_STATUS_UNREAD, ATF_STATUS_READ ),
			'after'    => sanitize_text_field( (string) ( $input['after'] ?? '' ) ),
			'before'   => sanitize_text_field( (string) ( $input['before'] ?? '' ) ),
			'starred'  => ! empty( $input['starred'] ),
			'page'     => max( 1, absint( $input['page'] ?? 1 ) ),
			'per_page' => min( 100, max( 1, absint( $input['per_page'] ?? 25 ) ) ),
		)
	);
}

/**
 * One entry, in full.
 *
 * @since 0.3.0
 *
 * @param array $input { entry_id: int }.
 * @return array|WP_Error
 */
function atf_ability_get_entry( $input ) {
	$entry = atf_prepare_entry( absint( $input['entry_id'] ?? 0 ) );

	if ( ! $entry ) {
		return new WP_Error( 'atf_entry_missing', __( 'That entry does not exist, or you cannot read it.', 'allterrain-forms' ) );
	}

	return $entry;
}

/**
 * Submits a form on the agent's behalf, honestly.
 *
 * The request is assembled the way a rendered form would have posted it —
 * including a *valid* time-trap signature stamped far enough in the past to
 * pass the minimum-time check. Minting it here is not defeating the trap: the
 * trap exists to catch bots that post faster than a human can read, and an
 * agent invoking a described ability through an authenticated channel is not
 * the traffic it hunts. Every other check — honeypot, rate limit, blocklist,
 * Akismet, validation — runs exactly as it does for a visitor.
 *
 * @since 0.3.0
 *
 * @param array $input { form_id: int, values: array }.
 * @return array|WP_Error { success, message, entry_id, errors }
 */
function atf_ability_submit_form( $input ) {
	$form_id = absint( $input['form_id'] ?? 0 );
	$values  = isset( $input['values'] ) && is_array( $input['values'] ) ? $input['values'] : array();
	$issued  = time() - MINUTE_IN_SECONDS;

	$result = atf_process_submission(
		$form_id,
		array(
			'atf'         => $values,
			'atf_form_id' => $form_id,
			'atf_t'       => $issued,
			'atf_ts'      => atf_sign_timestamp( $form_id, $issued ),
		)
	);

	return array(
		'success'  => ! empty( $result['success'] ),
		'message'  => (string) ( $result['message'] ?? '' ),
		'entry_id' => (int) ( $result['entry_id'] ?? 0 ),
		'errors'   => isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : array(),
	);
}

/**
 * The analytics report for one form.
 *
 * @since 0.3.0
 *
 * @param array $input { form_id: int, group_by?: string }.
 * @return array|WP_Error
 */
function atf_ability_form_report( $input ) {
	$post = get_post( absint( $input['form_id'] ?? 0 ) );

	if ( ! $post || ATF_FORM_TYPE !== $post->post_type ) {
		return new WP_Error( 'atf_form_missing', __( 'That form does not exist.', 'allterrain-forms' ) );
	}

	return atf_form_analytics( $post->ID, sanitize_text_field( (string) ( $input['group_by'] ?? '' ) ) );
}

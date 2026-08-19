<?php
/**
 * WP Explorer — the shell's My WordPress window.
 *
 * The Explorer browses every `show_ui` post type automatically, and this
 * plugin's types are all `show_ui => false` — they have their own windows, so
 * putting them in wp-admin's menu twice would be noise. The side effect was
 * that the site's forms were invisible in the one place somebody browses
 * everything they have. This file adds the Forms section back deliberately:
 * a tile per form, wearing what a form is actually about — how many questions
 * it asks, how many answers it holds, what it looks like — with the builder,
 * the entries window and the report one click away in the preview pane.
 *
 * Entries are deliberately NOT listed here. They are `show_in_rest => false`
 * by privacy design, and the Explorer can only list what REST serves. The
 * asks that would make them safe to show — per-post children, capability-
 * scoped bridged sections — live in `docs/wp-explorer-requests.md`.
 *
 * Every filter here is additive and gated: with no shell installed none of
 * them ever fire.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

add_action( 'plugins_loaded', 'atf_explorer_register' );

/**
 * Hooks into whichever spelling of the shell is installed.
 *
 * @since 0.3.0
 *
 * @return void
 */
function atf_explorer_register() {
	foreach ( atf_shell_hooks( 'my_wordpress_entities' ) as $hook ) {
		add_filter( $hook, 'atf_explorer_entities' );
	}

	foreach ( atf_shell_hooks( 'my_wordpress_preview_actions' ) as $hook ) {
		add_filter( $hook, 'atf_explorer_preview_actions' );
	}

	add_filter( 'rest_prepare_atf_form', 'atf_explorer_form_excerpt', 10, 2 );
}

/**
 * Adds the Forms section to the Explorer's root.
 *
 * @since 0.3.0
 *
 * @param array[] $entities Section descriptors.
 * @return array[]
 */
function atf_explorer_entities( $entities ) {
	if ( ! atf_can_edit_forms() && ! atf_can_read_entries() ) {
		return $entities;
	}

	$entities[] = array(
		'id'         => 'atf-forms',
		'label'      => __( 'Forms', 'allterrain-forms' ),
		'icon'       => 'dashicons-feedback',
		'restPath'   => 'wp/v2/atf-forms',
		'kind'       => 'post',
		'post_type'  => ATF_FORM_TYPE,
		// A form has no featured image, and leaving thumbnails on makes the
		// list request embed media on every tile and get nothing back.
		'thumbnails' => false,
	);

	return $entities;
}

/**
 * The preview-pane buttons a form deserves.
 *
 * One per surface, capability-gated server-side so a viewer who may only read
 * entries is never shown a builder button that would refuse them. The click
 * side lives in `dock.ts` against `os.my-wordpress.preview-actions`, which is
 * what the `script` handle loads.
 *
 * @since 0.3.0
 *
 * @param array[] $actions Registered preview actions.
 * @return array[]
 */
function atf_explorer_preview_actions( $actions ) {
	$actions[] = array(
		'id'         => 'allterrain-forms/open-builder',
		'label'      => __( 'Open in the form builder', 'allterrain-forms' ),
		'icon'       => 'dashicons-feedback',
		'capability' => 'atf_edit_forms',
		'sections'   => array( 'atf-forms' ),
		'script'     => 'allterrain-forms-dock',
	);

	$actions[] = array(
		'id'         => 'allterrain-forms/open-entries',
		'label'      => __( 'View entries', 'allterrain-forms' ),
		'icon'       => 'dashicons-list-view',
		'capability' => 'atf_read_entries',
		'sections'   => array( 'atf-forms' ),
		'script'     => 'allterrain-forms-dock',
	);

	$actions[] = array(
		'id'         => 'allterrain-forms/open-analytics',
		'label'      => __( 'Open the report', 'allterrain-forms' ),
		'icon'       => 'dashicons-chart-bar',
		'capability' => 'atf_read_entries',
		'sections'   => array( 'atf-forms' ),
		'script'     => 'allterrain-forms-dock',
	);

	return $actions;
}

/**
 * Dresses a form's Explorer tile in what the form is about.
 *
 * A tile reading only "Team pulse survey" says the least interesting thing a
 * form has to say. The excerpt becomes its vitals — questions, answers, theme
 * — so the grid reads like an inventory rather than a list of names.
 *
 * On every `wp/v2/atf-forms` response, not only the Explorer's: a REST
 * consumer that asked for an excerpt gets a truthful one either way, and the
 * form has no hand-written excerpt to overwrite.
 *
 * @since 0.3.0
 *
 * @param WP_REST_Response $response The response.
 * @param WP_Post          $post     The form.
 * @return WP_REST_Response
 */
function atf_explorer_form_excerpt( $response, $post ) {
	if ( ! isset( $response->data['excerpt'] ) || ! is_array( $response->data['excerpt'] ) ) {
		return $response;
	}

	$schema  = atf_get_form_schema( $post->ID );
	$themes  = atf_get_themes();
	$slug    = $schema['settings']['theme'];
	$theme   = isset( $themes[ $slug ]['label'] ) ? $themes[ $slug ]['label'] : $slug;
	$fields  = count( atf_input_fields( $schema ) );
	$entries = atf_count_entries( $post->ID );

	$vitals = sprintf(
		/* translators: 1: question count, 2: entry count, 3: theme name. */
		__( '%1$s · %2$s · %3$s', 'allterrain-forms' ),
		/* translators: %d: number of questions. */
		sprintf( _n( '%d question', '%d questions', $fields, 'allterrain-forms' ), $fields ),
		/* translators: %d: number of entries. */
		sprintf( _n( '%d entry', '%d entries', $entries, 'allterrain-forms' ), $entries ),
		$theme
	);

	$response->data['excerpt']['rendered'] = '<p>' . esc_html( $vitals ) . '</p>';

	return $response;
}

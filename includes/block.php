<?php
/**
 * The block.
 *
 * A dynamic block: the editor stores a form id and a theme, and the front end
 * renders through `alltfo_render_form()` at request time. Storing rendered HTML in
 * post content instead would freeze a copy of the form into every page that
 * embeds it, and editing the form would change nothing anywhere.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'alltfo_register_block', 20 );

/**
 * Registers the block and its editor script.
 *
 * @since 0.1.0
 *
 * @return void
 */
function alltfo_register_block() {
	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}

	wp_register_script(
		'allterrain-forms-block',
		ALLTFO_URL . 'assets/js/block.js',
		array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'wp-data', 'wp-api-fetch' ),
		alltfo_asset_version( 'assets/js/block.js' ),
		true
	);

	register_block_type(
		'allterrain-forms/form',
		array(
			'api_version'     => 2,
			'title'           => __( 'Form', 'allterrain-forms' ),
			'description'     => __( 'Place an AllTerrain Form.', 'allterrain-forms' ),
			'category'        => 'widgets',
			'icon'            => 'feedback',
			'keywords'        => array( 'form', 'contact', 'survey', 'allterrain' ),
			'editor_script'   => 'allterrain-forms-block',
			'style'           => 'allterrain-forms',
			'attributes'      => array(
				'formId'    => array(
					'type'    => 'number',
					'default' => 0,
				),
				'theme'     => array(
					'type'    => 'string',
					'default' => '',
				),
				'showTitle' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'supports'        => array(
				'align'  => array( 'wide', 'full' ),
				'anchor' => true,
			),
			'render_callback' => 'alltfo_render_block',
		)
	);
}

/**
 * Renders the block on the front end.
 *
 * @since 0.1.0
 *
 * @param array $attributes The block's attributes.
 * @return string
 */
function alltfo_render_block( $attributes ) {
	$form_id = isset( $attributes['formId'] ) ? absint( $attributes['formId'] ) : 0;

	if ( ! $form_id ) {
		return '';
	}

	alltfo_enqueue_form_assets();

	// The block goes through the shortcode rather than straight to the renderer,
	// so that a failed non-JavaScript submission is rehydrated the same way in
	// both -- one code path for "what happens after a POST", not two.
	return alltfo_shortcode(
		array(
			'id'    => $form_id,
			'theme' => isset( $attributes['theme'] ) ? sanitize_key( $attributes['theme'] ) : '',
			'title' => ! empty( $attributes['showTitle'] ) ? 'show' : 'hide',
		)
	);
}

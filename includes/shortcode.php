<?php
/**
 * The shortcode.
 *
 * `[allterrain_form id="12"]`, plus the attributes that make sense to vary per
 * placement rather than per form: a theme override, whether to show the title.
 *
 * This is also where a non-JavaScript submission finishes its round trip. The
 * result was stashed on `wp` by `atf_handle_post_submission()`; here it is
 * turned back into either a confirmation message or the same form with errors
 * against the right fields and every answer still in place.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'allterrain_form', 'atf_shortcode' );

/**
 * Renders the shortcode.
 *
 * @since 0.1.0
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function atf_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'id'    => 0,
			'theme' => '',
			'title' => 'hide',
		),
		$atts,
		'allterrain_form'
	);

	$form_id = absint( $atts['id'] );

	if ( ! $form_id ) {
		return '';
	}

	// Enqueued here rather than unconditionally, so a site with one form on one
	// page does not ship the bundle and the stylesheet to every other page.
	atf_enqueue_form_assets();

	$args = array(
		'theme' => sanitize_key( $atts['theme'] ),
		'title' => 'show' === $atts['title'] ? 'show' : 'hide',
	);

	$stashed = atf_stash_result( $form_id );

	if ( $stashed ) {
		$result = $stashed['result'];

		if ( $result['success'] ) {
			$confirmation = $result['confirmation'];

			// A redirect confirmation already left the building in
			// `atf_handle_post_submission()`; reaching here means it was a
			// message, or the redirect had nowhere to go.
			$args['message'] = isset( $confirmation['message'] ) && '' !== $confirmation['message']
				? $confirmation['message']
				: __( 'Thank you. Your submission has been received.', 'allterrain-forms' );
		} else {
			$args['errors'] = $result['errors'];
			$args['values'] = atf_rehydrate_values( $form_id, $stashed['request'] );

			if ( '' !== $result['message'] && ! $result['errors'] ) {
				// A form-level failure with no field errors -- a closed form, an
				// expired nonce. Shown as a notice above the form rather than
				// replacing it, so the visitor can try again.
				return sprintf(
					'<div class="atf-notice atf-notice--error" role="alert">%s</div>%s',
					esc_html( $result['message'] ),
					atf_render_form( $form_id, $args )
				);
			}
		}
	}

	return atf_render_form( $form_id, $args );
}

/**
 * Turns a failed submission's raw request back into field values.
 *
 * Sanitised through each field's own type, exactly as a real submission is --
 * because these values are about to be echoed back into the page, and "it was
 * rejected" is not a reason to trust it.
 *
 * @since 0.1.0
 *
 * @param int   $form_id The form.
 * @param array $raw     The `atf` slice of the failed request.
 * @return array Field id => value.
 */
function atf_rehydrate_values( $form_id, $raw ) {
	if ( ! is_array( $raw ) ) {
		return array();
	}

	return atf_sanitize_submission( atf_get_form_schema( $form_id ), $raw );
}

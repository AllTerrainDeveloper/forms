<?php
/**
 * The front-end renderer.
 *
 * Produces the HTML for a form. Two rules govern every line of it.
 *
 * **It works without JavaScript.** The markup is a real `<form>` with a real
 * `action` and a real `method="post"`. Submitted with scripting off it posts,
 * validates on the server, and comes back with errors against the right fields
 * and the visitor's answers still in them. Everything the bundle adds --
 * conditional logic as you type, live totals, step transitions, inline
 * validation, AJAX -- is enhancement over a form that already worked.
 *
 * **It is accessible or it does not ship.** Every control has a real `<label>`
 * bound by `for`. Hints and errors are wired with `aria-describedby`. Invalid
 * fields carry `aria-invalid`. Grouped controls are in a `<fieldset>` with a
 * `<legend>`. The error summary is a focusable region that takes focus on a
 * failed submit, so a screen-reader user is told what went wrong instead of
 * being dropped back at the top of a form that looks unchanged.
 *
 * The theme never changes this markup. Ten themes render the same DOM and differ
 * only in custom properties -- which is what keeps the accessibility work done
 * once rather than ten times.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders a complete form.
 *
 * @since 0.1.0
 *
 * @param int   $form_id The form.
 * @param array $args {
 *     Optional. Render options.
 *
 *     @type string $theme    Override the form's own theme.
 *     @type array  $values   Values to pre-fill, e.g. after a failed submit.
 *     @type array  $errors   Field id => message, from a failed submit.
 *     @type string $message  A confirmation message to show instead of the form.
 *     @type string $title    `show` or `hide` the form's title.
 *     @type bool   $preview  True in the builder's preview, which suppresses
 *                            counting a view and disables submission.
 * }
 * @return string The form's HTML.
 */
function atf_render_form( $form_id, $args = array() ) {
	$form_id = absint( $form_id );
	$form    = $form_id ? get_post( $form_id ) : null;

	if ( ! $form || ATF_FORM_TYPE !== $form->post_type ) {
		// Silent for visitors, loud for the person who can fix it. A broken
		// shortcode id should never print an error into a published page.
		if ( atf_can_edit_forms() ) {
			return sprintf(
				'<p class="atf-notice atf-notice--error">%s</p>',
				esc_html__( 'AllTerrain Forms: that form does not exist.', 'allterrain-forms' )
			);
		}

		return '';
	}

	$args = wp_parse_args(
		$args,
		array(
			'theme'   => '',
			'values'  => array(),
			'errors'  => array(),
			'message' => '',
			'title'   => 'hide',
			'preview' => false,
		)
	);

	$schema   = atf_get_form_schema( $form_id );
	$settings = $schema['settings'];

	$theme_slug = '' !== $args['theme'] ? $args['theme'] : $settings['theme'];
	$tokens     = atf_resolve_tokens( $theme_slug, $settings['themeOverrides'] );

	// A unique instance id, not the form id: the same form can legitimately be
	// on a page twice, and duplicate DOM ids would break every `for` attribute
	// and every `aria-describedby` on the second copy.
	$instance = atf_next_instance_id( $form_id );
	$scope    = '#' . $instance;

	$classes = array(
		'atf-form',
		'atf-theme-' . sanitize_html_class( $theme_slug ),
		'atf-labels-' . sanitize_html_class( '' !== $settings['labelPosition'] ? $settings['labelPosition'] : $tokens['label-position'] ),
		'atf-fields-' . sanitize_html_class( $tokens['field-style'] ),
	);

	if ( ! empty( atf_get_theme( $theme_slug )['dark'] ) ) {
		$classes[] = 'atf-is-dark';
	}

	/**
	 * Filters the classes on a rendered form's wrapper.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $classes The classes.
	 * @param int      $form_id The form.
	 * @param array    $schema  The form schema.
	 */
	$classes = apply_filters( 'atf_form_classes', $classes, $form_id, $schema );

	$out = sprintf(
		'<div class="atf-form-wrap" id="%s">',
		esc_attr( $instance )
	);

	$css = atf_tokens_to_css( $scope . ' .atf-form', $tokens );

	if ( '' !== $css ) {
		// Inline rather than enqueued, because the tokens depend on which form
		// is on the page and a stylesheet cannot know that. `wp_strip_all_tags()`
		// on top of the token sanitiser is belt and braces: nothing containing a
		// `<` survives `atf_sanitize_tokens()`, and a `</style>` inside a style
		// block is the one mistake that would matter.
		$out .= sprintf( '<style>%s</style>', wp_strip_all_tags( $css ) );
	}

	if ( 'show' === $args['title'] ) {
		$out .= sprintf( '<h2 class="atf-form__title">%s</h2>', esc_html( get_the_title( $form ) ) );
	}

	// A confirmation message replaces the form entirely. `role="status"` rather
	// than `alert`, because success is announced politely once the page has
	// settled rather than interrupting.
	if ( '' !== $args['message'] ) {
		$out .= sprintf(
			'<div class="%s"><div class="atf-confirmation" role="status" tabindex="-1">%s</div></div></div>',
			esc_attr( implode( ' ', $classes ) ),
			wp_kses_post( $args['message'] )
		);

		return $out;
	}

	$gate = atf_form_availability( $form_id, $schema );

	if ( ! $gate['open'] ) {
		$out .= sprintf(
			'<div class="%s"><div class="atf-notice atf-notice--closed" role="status">%s</div></div></div>',
			esc_attr( implode( ' ', $classes ) ),
			wp_kses_post( $gate['message'] )
		);

		return $out;
	}

	if ( ! $args['preview'] ) {
		atf_record_view( $form_id );
	}

	// A resume link beats the field defaults but loses to anything the caller
	// passed, which is what lets a failed submit of a resumed form come back
	// with what was just typed rather than with what was saved yesterday.
	$resume_token = '';

	if ( isset( $_GET[ ATF_RESUME_QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The token itself is the credential; it is verified by `atf_resume_values()`.
		$resumed = atf_resume_values( sanitize_text_field( wp_unslash( $_GET[ ATF_RESUME_QUERY ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.

		if ( $resumed && $resumed['form_id'] === $form_id ) {
			$resume_token   = $resumed['token'];
			$args['values'] = array_merge( $resumed['values'], $args['values'] );
		}
	}

	$values = atf_prefill_values( $schema, $args['values'] );

	$out .= sprintf(
		'<form class="%s" method="post" action="%s" novalidate data-atf-form="%d" data-atf-instance="%s"%s%s>',
		esc_attr( implode( ' ', $classes ) ),
		esc_url( atf_form_action_url() ),
		$form_id,
		esc_attr( $instance ),
		$args['preview'] ? ' data-atf-preview="1"' : '',
		atf_has_upload_field( $schema ) ? ' enctype="multipart/form-data"' : ''
	);

	$out .= atf_render_hidden_fields( $form_id, $schema, $args['preview'] );
	$out .= atf_render_client_schema( $schema, $instance );
	$out .= atf_render_error_summary( $args['errors'], $schema, $instance );

	$pages = atf_schema_pages( $schema );
	$multi = count( $pages ) > 1;

	if ( $multi ) {
		$out .= atf_render_progress( $pages, $settings );
	}

	foreach ( $pages as $index => $page ) {
		$out .= atf_render_page( $page, $index, count( $pages ), $schema, $values, $args['errors'], $instance );
	}

	// After the last page, so a multi-page form asks its question at the end
	// rather than making the visitor answer arithmetic before they have seen
	// what the form is for.
	$out .= atf_render_challenge( $schema, $form_id );

	if ( ! $multi ) {
		$out .= atf_render_submit( $settings, true, atf_render_resume_button( $schema, $resume_token ) );
	} elseif ( '' !== atf_render_resume_button( $schema, $resume_token ) ) {
		// On a multi-page form the submit button lives on the last page, but
		// "save for later" has to be reachable from every one of them -- that is
		// the whole point of it. So it sits outside the pages entirely.
		$out .= sprintf(
			'<div class="atf-actions atf-actions--resume">%s</div>',
			atf_render_resume_button( $schema, $resume_token )
		);
	}

	$out .= '</form></div>';

	/**
	 * Filters a form's complete rendered HTML.
	 *
	 * @since 0.1.0
	 *
	 * @param string $out     The HTML.
	 * @param int    $form_id The form.
	 * @param array  $schema  The form schema.
	 */
	return apply_filters( 'atf_rendered_form', $out, $form_id, $schema );
}

/**
 * A DOM id unique to this render, even when a form appears twice on a page.
 *
 * @since 0.1.0
 *
 * @param int $form_id The form.
 * @return string An id safe for an `id` attribute.
 */
function atf_next_instance_id( $form_id ) {
	static $counts = array();

	$counts[ $form_id ] = isset( $counts[ $form_id ] ) ? $counts[ $form_id ] + 1 : 1;

	return 'atf-' . $form_id . '-' . $counts[ $form_id ];
}

/**
 * Where a non-JavaScript submit posts to.
 *
 * The current URL, so a failed validation comes back to the page the form is on
 * with the surrounding content intact. Posting to `admin-post.php` instead would
 * work, but every error state would render on a blank admin page.
 *
 * @since 0.1.0
 *
 * @return string
 */
function atf_form_action_url() {
	$url = '';

	if ( isset( $_SERVER['REQUEST_URI'] ) ) {
		$url = esc_url_raw( home_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) );
	}

	return '' !== $url ? $url : home_url( '/' );
}

/**
 * The hidden inputs every form carries.
 *
 * The honeypot is here rather than in the field loop because it must never be a
 * field: it has no label in the palette, no place in the schema, and no entry
 * column. Its name is deliberately plausible (`atf_website`) because bots fill
 * anything that looks like a real field and skip anything called `honeypot`.
 * `tabindex="-1"` and `aria-hidden` keep it away from keyboard and screen-reader
 * users, and it is hidden in CSS rather than with `type="hidden"` -- a hidden
 * input is not filled in by the bots this catches.
 *
 * @since 0.1.0
 *
 * @param int   $form_id The form.
 * @param array $schema  The form schema.
 * @param bool  $preview Whether this is the builder's preview.
 * @return string
 */
function atf_render_hidden_fields( $form_id, $schema, $preview = false ) {
	$out = sprintf( '<input type="hidden" name="atf_form_id" value="%d">', $form_id );

	// A nonce on a public front-end form is not CSRF protection -- there is no
	// session to protect and the form is meant to be submitted by strangers. It
	// is here because it makes replaying a captured request past the nonce
	// lifetime fail, which raises the cost of the crudest spam scripts.
	$out .= sprintf(
		'<input type="hidden" name="atf_nonce" value="%s">',
		esc_attr( wp_create_nonce( 'atf_submit_' . $form_id ) )
	);

	// Signed rather than raw, so the time trap cannot be defeated by posting an
	// older timestamp than the one that was served.
	$issued = time();
	$out   .= sprintf( '<input type="hidden" name="atf_t" value="%d">', $issued );
	$out   .= sprintf(
		'<input type="hidden" name="atf_ts" value="%s">',
		esc_attr( atf_sign_timestamp( $form_id, $issued ) )
	);

	if ( ! empty( $schema['settings']['spam']['honeypot'] ) ) {
		$out .= '<div class="atf-hp" aria-hidden="true">'
			. '<label for="atf-website-' . esc_attr( $form_id ) . '">'
			. esc_html__( 'Leave this field empty', 'allterrain-forms' )
			. '</label>'
			. '<input type="text" id="atf-website-' . esc_attr( $form_id ) . '" name="atf_website" value="" tabindex="-1" autocomplete="off">'
			. '</div>';
	}

	if ( $preview ) {
		$out .= '<input type="hidden" name="atf_preview" value="1">';
	}

	return $out;
}

/**
 * Signs a form's issue timestamp.
 *
 * @since 0.1.0
 *
 * @param int $form_id The form.
 * @param int $issued  Unix timestamp the form was rendered at.
 * @return string The signature.
 */
function atf_sign_timestamp( $form_id, $issued ) {
	return wp_hash( 'atf|' . $form_id . '|' . $issued );
}

/**
 * The slice of the schema the browser is allowed to see.
 *
 * The bundle needs more than the DOM can carry: conditional logic that chains
 * across fields, the prices attached to choices so a running total can be shown,
 * and the validation bounds it mirrors. Scraping that back out of attributes
 * would mean encoding each of them twice and keeping the two encodings in step.
 *
 * What is deliberately **not** here is everything the visitor has no business
 * reading: notifications and their recipients, the spam blocklist, webhook URLs
 * and secrets, quiz answers, retention settings. A form's schema holds all of
 * those, and shipping it whole to the front end would put a webhook signing
 * secret in the page source of a public contact form.
 *
 * Quiz `correct` answers are stripped for the obvious reason.
 *
 * @since 0.1.0
 *
 * @param array  $schema   The form schema.
 * @param string $instance The render's DOM id prefix.
 * @return string A JSON script tag.
 */
function atf_render_client_schema( $schema, $instance ) {
	$fields = array();

	foreach ( isset( $schema['fields'] ) ? $schema['fields'] : array() as $field ) {
		$client = array(
			'id'       => $field['id'],
			'type'     => $field['type'],
			'label'    => $field['label'],
			'required' => (bool) $field['required'],
			'logic'    => $field['logic'],
			'messages' => $field['messages'],
		);

		foreach ( array( 'min', 'max', 'step', 'minlength', 'maxlength', 'pattern', 'minChoices', 'maxChoices', 'formula', 'decimals', 'currency', 'minRows', 'maxRows' ) as $key ) {
			if ( isset( $field[ $key ] ) && '' !== $field[ $key ] ) {
				$client[ $key ] = $field[ $key ];
			}
		}

		// Only the parts of a choice a calculation or a logic rule needs. The
		// label is already in the DOM beside the control that carries it.
		if ( ! empty( $field['choices'] ) ) {
			$client['choices'] = array();

			foreach ( $field['choices'] as $choice ) {
				$entry = array( 'value' => $choice['value'] );

				if ( isset( $choice['price'] ) ) {
					$entry['price'] = $choice['price'];
				}

				$client['choices'][] = $entry;
			}
		}

		$fields[] = $client;
	}

	$payload = array(
		'fields'   => $fields,
		'settings' => array(
			'ajax'        => (bool) $schema['settings']['ajax'],
			'progressBar' => $schema['settings']['progressBar'],
		),
	);

	/**
	 * Filters the schema slice handed to the front-end bundle.
	 *
	 * Anything added here is readable by every visitor, including in the page
	 * source of a cached page. Treat it as public.
	 *
	 * @since 0.1.0
	 *
	 * @param array $payload The client schema.
	 * @param array $schema  The full schema.
	 */
	$payload = apply_filters( 'atf_client_schema', $payload, $schema );

	return sprintf(
		'<script type="application/json" data-atf-schema id="%s-schema">%s</script>',
		esc_attr( $instance ),
		// `JSON_HEX_TAG` turns `<` into `<`, so a value containing
		// `</script>` cannot close the block it is inside. The other three flags
		// close the same family of escapes for quotes and ampersands.
		wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT )
	);
}

/**
 * The error summary shown above a failed form.
 *
 * `role="alert"` and `tabindex="-1"` together are what make a failed submit
 * usable without sight: the region announces itself, and the bundle moves focus
 * into it so the next Tab lands on the first broken field rather than at the top
 * of the document.
 *
 * @since 0.1.0
 *
 * @param array  $errors   Field id => message.
 * @param array  $schema   The form schema.
 * @param string $instance The render's DOM id prefix.
 * @return string
 */
function atf_render_error_summary( $errors, $schema, $instance ) {
	if ( ! $errors ) {
		// Rendered empty rather than omitted, so the bundle has somewhere to put
		// client-side errors without building the region at the moment focus
		// needs to move into it.
		return sprintf(
			'<div class="atf-errors" id="%s-errors" role="alert" tabindex="-1" hidden></div>',
			esc_attr( $instance )
		);
	}

	$items = '';

	foreach ( $errors as $field_id => $message ) {
		$field = atf_find_field( $schema, $field_id );
		$label = $field && '' !== $field['label'] ? $field['label'] : '';

		$items .= sprintf(
			'<li><a href="#%s">%s</a></li>',
			esc_attr( $instance . '-' . $field_id ),
			esc_html( '' !== $label ? $label . ': ' . $message : $message )
		);
	}

	return sprintf(
		'<div class="atf-errors" id="%s-errors" role="alert" tabindex="-1"><p class="atf-errors__title">%s</p><ul>%s</ul></div>',
		esc_attr( $instance ),
		esc_html(
			sprintf(
				/* translators: %d: number of problems found. */
				_n( 'There is %d thing to fix:', 'There are %d things to fix:', count( $errors ), 'allterrain-forms' ),
				count( $errors )
			)
		),
		$items
	);
}

/**
 * The step indicator on a multi-page form.
 *
 * @since 0.1.0
 *
 * @param array[] $pages    The pages.
 * @param array   $settings The form settings.
 * @return string
 */
function atf_render_progress( $pages, $settings ) {
	$style = isset( $settings['progressBar'] ) ? $settings['progressBar'] : 'steps';

	if ( 'none' === $style ) {
		return '';
	}

	$total = count( $pages );

	if ( 'bar' === $style ) {
		return sprintf(
			'<div class="atf-progress atf-progress--bar" role="progressbar" aria-valuemin="1" aria-valuemax="%1$d" aria-valuenow="1"'
			. ' aria-label="%2$s"><div class="atf-progress__fill" style="width:%3$s%%"></div></div>',
			$total,
			esc_attr__( 'Progress through the form', 'allterrain-forms' ),
			esc_attr( (string) round( 100 / $total, 2 ) )
		);
	}

	$steps = '';

	foreach ( $pages as $index => $page ) {
		$label = $page['break'] && '' !== $page['break']['label']
			? $page['break']['label']
			/* translators: %d: step number. */
			: sprintf( __( 'Step %d', 'allterrain-forms' ), $index + 1 );

		$steps .= sprintf(
			'<li class="atf-progress__step%s" data-atf-step="%d"><span class="atf-progress__dot" aria-hidden="true">%d</span>'
			. '<span class="atf-progress__label">%s</span></li>',
			0 === $index ? ' is-current' : '',
			$index,
			$index + 1,
			esc_html( $label )
		);
	}

	return sprintf(
		'<ol class="atf-progress atf-progress--steps" aria-label="%s">%s</ol>',
		esc_attr__( 'Form steps', 'allterrain-forms' ),
		$steps
	);
}

/**
 * One page of a form.
 *
 * Every page after the first is `hidden`, so a non-JavaScript visitor sees the
 * whole form as one long page and can still complete it. The bundle takes the
 * attribute off and manages the steps; without it, nothing is lost but the
 * pagination.
 *
 * @since 0.1.0
 *
 * @param array  $page     The page.
 * @param int    $index    Its zero-based index.
 * @param int    $total    How many pages there are.
 * @param array  $schema   The form schema.
 * @param array  $values   Current values.
 * @param array  $errors   Field id => message.
 * @param string $instance The render's DOM id prefix.
 * @return string
 */
function atf_render_page( $page, $index, $total, $schema, $values, $errors, $instance ) {
	$multi = $total > 1;

	$out = sprintf(
		'<div class="atf-page" data-atf-page="%d"%s>',
		$index,
		// `hidden` is not applied on the server for the first page, and the
		// bundle hides later ones itself only once it has booted -- so a slow
		// bundle shows a usable long form rather than a form with one page and
		// no way forward.
		$multi && $index > 0 ? ' data-atf-page-hidden="1"' : ''
	);

	$out .= '<div class="atf-fields">';

	foreach ( $page['fields'] as $field ) {
		$out .= atf_render_field( $field, $schema, $values, $errors, $instance );
	}

	$out .= '</div>';

	if ( $multi ) {
		$out .= '<div class="atf-nav">';

		if ( $index > 0 ) {
			$previous = $page['break'] && ! empty( $page['break']['prevLabel'] )
				? $page['break']['prevLabel']
				: __( 'Back', 'allterrain-forms' );

			$out .= sprintf(
				'<button type="button" class="atf-button atf-button--secondary" data-atf-prev>%s</button>',
				esc_html( $previous )
			);
		}

		if ( $index < $total - 1 ) {
			$next = $page['break'] && ! empty( $page['break']['nextLabel'] )
				? $page['break']['nextLabel']
				: __( 'Next', 'allterrain-forms' );

			$out .= sprintf(
				'<button type="button" class="atf-button" data-atf-next>%s</button>',
				esc_html( $next )
			);
		} else {
			$out .= atf_render_submit( $schema['settings'], false );
		}

		$out .= '</div>';
	}

	return $out . '</div>';
}

/**
 * The submit button, and the spinner and live region that go with it.
 *
 * @since 0.1.0
 *
 * @param array $settings The form settings.
 * @param bool  $wrap     Whether to wrap it in its own actions row.
 * @return string
 */
function atf_render_submit( $settings, $wrap = true, $extra = '' ) {
	$label = '' !== $settings['submitLabel'] ? $settings['submitLabel'] : __( 'Send', 'allterrain-forms' );

	$button = sprintf(
		'<button type="submit" class="atf-button atf-button--submit" data-atf-submit>'
		. '<span class="atf-button__label">%s</span>'
		. '<span class="atf-button__spinner" aria-hidden="true"></span>'
		. '</button>',
		esc_html( $label )
	);

	// A polite live region beside the button, so the bundle can say "Sending…"
	// and then "Sent" without the visitor having to go looking for the result.
	$status = '<span class="atf-status" role="status" aria-live="polite"></span>';

	return $wrap
		? '<div class="atf-actions">' . $button . $extra . $status . '</div>'
		: $button . $extra . $status;
}

/**
 * Renders one field, chrome and all.
 *
 * The wrapper carries the field's logic as data attributes so the bundle can
 * evaluate it without a second copy of the schema, and carries `hidden` from the
 * server when the logic already says the field is not shown -- which is what
 * stops a conditional field flashing into view before the bundle boots.
 *
 * @since 0.1.0
 *
 * @param array  $field    The field.
 * @param array  $schema   The form schema.
 * @param array  $values   Current values.
 * @param array  $errors   Field id => message.
 * @param string $instance The render's DOM id prefix.
 * @return string
 */
function atf_render_field( $field, $schema, $values, $errors, $instance ) {
	$definition = atf_get_field_type( $field['type'] );

	/**
	 * Short-circuits the rendering of one field.
	 *
	 * Returning a string here replaces the field's markup entirely.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $html   Null to render normally.
	 * @param array       $field  The field.
	 * @param array       $schema The form schema.
	 * @param array       $values Current values.
	 */
	$short_circuit = apply_filters( 'atf_pre_render_field', null, $field, $schema, $values );

	if ( null !== $short_circuit ) {
		return (string) $short_circuit;
	}

	$id      = $instance . '-' . $field['id'];
	$value   = isset( $values[ $field['id'] ] ) ? $values[ $field['id'] ] : $field['default'];
	$error   = isset( $errors[ $field['id'] ] ) ? $errors[ $field['id'] ] : '';
	$visible = atf_field_is_visible( $schema, $field['id'], $values );

	$classes = array(
		'atf-field',
		'atf-field--' . sanitize_html_class( $field['type'] ),
		'atf-field--' . sanitize_html_class( $field['width'] ),
	);

	if ( $field['required'] ) {
		$classes[] = 'is-required';
	}

	if ( '' !== $error ) {
		$classes[] = 'has-error';
	}

	if ( '' !== $field['cssClass'] ) {
		$classes[] = $field['cssClass'];
	}

	$attributes = sprintf(
		' class="%s" data-atf-field="%s" data-atf-type="%s"',
		esc_attr( implode( ' ', $classes ) ),
		esc_attr( $field['id'] ),
		esc_attr( $field['type'] )
	);

	if ( ! empty( $field['logic']['enabled'] ) && ! empty( $field['logic']['rules'] ) ) {
		$attributes .= sprintf( ' data-atf-logic="%s"', esc_attr( wp_json_encode( $field['logic'] ) ) );
	}

	if ( ! $visible ) {
		$attributes .= ' hidden';
	}

	// The describedby list is assembled before the control is rendered, because
	// the control needs the ids and the elements they name come after it.
	$described = array();

	if ( '' !== $field['hint'] ) {
		$described[] = $id . '-hint';
	}

	if ( '' !== $error ) {
		$described[] = $id . '-error';
	}

	$context = array(
		'id'          => $id,
		'instance'    => $instance,
		'schema'      => $schema,
		'values'      => $values,
		'error'       => $error,
		'describedby' => implode( ' ', $described ),
	);

	$out = '<div' . $attributes . '>';

	if ( $definition && is_callable( $definition['render'] ) ) {
		$out .= (string) call_user_func( $definition['render'], $field, $value, $context );
	} else {
		$out .= atf_render_field_control( $field, $value, $context );
	}

	if ( '' !== $field['hint'] ) {
		$out .= sprintf(
			'<p class="atf-hint" id="%s-hint">%s</p>',
			esc_attr( $id ),
			wp_kses_post( $field['hint'] )
		);
	}

	$out .= sprintf(
		'<p class="atf-error" id="%s-error"%s>%s</p>',
		esc_attr( $id ),
		'' === $error ? ' hidden' : '',
		esc_html( $error )
	);

	$out .= '</div>';

	/**
	 * Filters one field's rendered HTML.
	 *
	 * @since 0.1.0
	 *
	 * @param string $out    The HTML.
	 * @param array  $field  The field.
	 * @param mixed  $value  Its current value.
	 * @param array  $schema The form schema.
	 */
	return apply_filters( 'atf_rendered_field', $out, $field, $value, $schema );
}

/**
 * The label element for a field.
 *
 * A required field is marked twice: visually with an asterisk that is
 * `aria-hidden`, and for assistive technology with `required` on the control
 * itself. Announcing the asterisk as "asterisk" tells a screen-reader user
 * nothing; `required` tells them everything.
 *
 * @since 0.1.0
 *
 * @param array  $field The field.
 * @param string $id    The control's DOM id.
 * @param string $tag   `label` for a real control, `legend` inside a fieldset.
 * @return string
 */
function atf_render_label( $field, $id, $tag = 'label' ) {
	if ( '' === $field['label'] ) {
		return '';
	}

	$mark = $field['required']
		? '<span class="atf-required" aria-hidden="true">*</span>'
		: '';

	if ( 'legend' === $tag ) {
		return sprintf( '<legend class="atf-label">%s%s</legend>', esc_html( $field['label'] ), $mark );
	}

	return sprintf(
		'<label class="atf-label" for="%s">%s%s</label>',
		esc_attr( $id ),
		esc_html( $field['label'] ),
		$mark
	);
}

/**
 * The shared attribute string for a text-like control.
 *
 * @since 0.1.0
 *
 * @param array $field   The field.
 * @param array $context The render context.
 * @return string
 */
function atf_control_attributes( $field, $context ) {
	$attributes = sprintf(
		' id="%s" name="%s"',
		esc_attr( $context['id'] ),
		esc_attr( 'atf[' . $field['id'] . ']' )
	);

	if ( $field['required'] ) {
		$attributes .= ' required aria-required="true"';
	}

	if ( '' !== $context['error'] ) {
		$attributes .= ' aria-invalid="true"';
	}

	if ( '' !== $context['describedby'] ) {
		$attributes .= sprintf( ' aria-describedby="%s"', esc_attr( $context['describedby'] ) );
	}

	if ( '' !== $field['placeholder'] ) {
		$attributes .= sprintf( ' placeholder="%s"', esc_attr( $field['placeholder'] ) );
	}

	foreach ( array( 'min', 'max', 'step', 'pattern' ) as $key ) {
		if ( isset( $field[ $key ] ) && '' !== $field[ $key ] ) {
			$attributes .= sprintf( ' %s="%s"', $key, esc_attr( $field[ $key ] ) );
		}
	}

	if ( isset( $field['minlength'] ) && '' !== $field['minlength'] ) {
		$attributes .= sprintf( ' minlength="%s"', esc_attr( $field['minlength'] ) );
	}

	if ( isset( $field['maxlength'] ) && '' !== $field['maxlength'] ) {
		$attributes .= sprintf( ' maxlength="%s"', esc_attr( $field['maxlength'] ) );
	}

	// An autocomplete token turns a form into one the browser can fill, which is
	// both a usability win and a WCAG 1.3.5 requirement.
	$autocomplete = atf_autocomplete_token( $field );

	if ( '' !== $autocomplete ) {
		$attributes .= sprintf( ' autocomplete="%s"', esc_attr( $autocomplete ) );
	}

	return $attributes;
}

/**
 * The `autocomplete` token for a field, where one is knowable.
 *
 * @since 0.1.0
 *
 * @param array $field The field.
 * @return string A token, or an empty string.
 */
function atf_autocomplete_token( $field ) {
	$by_type = array(
		'email'    => 'email',
		'tel'      => 'tel',
		'url'      => 'url',
		'country'  => 'country-name',
		'password' => 'new-password',
	);

	if ( isset( $by_type[ $field['type'] ] ) ) {
		return $by_type[ $field['type'] ];
	}

	/**
	 * Filters the autocomplete token for a field.
	 *
	 * @since 0.1.0
	 *
	 * @param string $token The token, or an empty string.
	 * @param array  $field The field.
	 */
	return (string) apply_filters( 'atf_autocomplete_token', '', $field );
}

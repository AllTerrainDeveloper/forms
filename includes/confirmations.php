<?php
/**
 * What the visitor sees after they submit.
 *
 * A form carries a list of confirmations, each with its own conditional logic,
 * and the first one whose conditions are met wins. That ordering is what makes
 * "if they picked Support, send them to the support page; otherwise say thank
 * you" expressible without any branching in the form itself.
 *
 * Three kinds: an inline message that replaces the form, a redirect to a URL, or
 * a redirect to a page on this site.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * The success screen styles a message confirmation can wear.
 *
 * Each is a different answer to "what should the moment of success feel
 * like?" — from a bare paragraph to a fireworks display. The keys are what a
 * confirmation's `success.style` stores; the labels and descriptions are what
 * the builder shows. The client keeps a renderer per key, so a style added
 * here without one there falls back to `simple` on the front end.
 *
 * @since 0.2.0
 *
 * @return array<string, array{label: string, description: string, icon: string}>
 */
function alltfo_success_styles() {
	$styles = array(
		'plain'      => array(
			'label'       => __( 'Plain', 'allterrain-forms' ),
			'description' => __( 'Just the message, exactly where the form was.', 'allterrain-forms' ),
			'icon'        => '',
		),
		'simple'     => array(
			'label'       => __( 'Simple', 'allterrain-forms' ),
			'description' => __( 'A check mark, a heading and the message, fading gently in.', 'allterrain-forms' ),
			'icon'        => '✓',
		),
		'minimal'    => array(
			'label'       => __( 'Minimalistic', 'allterrain-forms' ),
			'description' => __( 'Quiet type, generous space, a slow fade. Nothing else.', 'allterrain-forms' ),
			'icon'        => '·',
		),
		'card'       => array(
			'label'       => __( 'Card', 'allterrain-forms' ),
			'description' => __( 'An elevated card with an accent bar that pops into place.', 'allterrain-forms' ),
			'icon'        => '🎫',
		),
		'check'      => array(
			'label'       => __( 'Check mark', 'allterrain-forms' ),
			'description' => __( 'A big check mark draws itself, then the message follows.', 'allterrain-forms' ),
			'icon'        => '✔',
		),
		'confetti'   => array(
			'label'       => __( 'Confetti', 'allterrain-forms' ),
			'description' => __( 'Paper rains over the whole page while the message lands.', 'allterrain-forms' ),
			'icon'        => '🎉',
		),
		'fireworks'  => array(
			'label'       => __( 'Fireworks', 'allterrain-forms' ),
			'description' => __( 'Rockets and bursts over a darkened stage. The full show.', 'allterrain-forms' ),
			'icon'        => '🎆',
		),
		'sparkles'   => array(
			'label'       => __( 'Sparkles', 'allterrain-forms' ),
			'description' => __( 'Your chosen emoji floats up around the message.', 'allterrain-forms' ),
			'icon'        => '✨',
		),
		'typewriter' => array(
			'label'       => __( 'Typewriter', 'allterrain-forms' ),
			'description' => __( 'The message types itself out, letter by letter.', 'allterrain-forms' ),
			'icon'        => '⌨',
		),
	);

	/**
	 * Filters the available success screen styles.
	 *
	 * A style registered here needs a client-side renderer to animate; without
	 * one it renders as the `simple` screen.
	 *
	 * @since 0.2.0
	 *
	 * @param array $styles Style key => { label, description, icon }.
	 */
	return apply_filters( 'alltfo_success_styles', $styles );
}

/**
 * Picks the confirmation for a submission and resolves it.
 *
 * @since 0.1.0
 *
 * @param array $schema   The form schema.
 * @param array $values   The accepted values.
 * @param int   $entry_id The stored entry, or 0.
 * @param int   $form_id  The form.
 * @return array { type: string, message: string, url: string, success: array }
 */
function alltfo_resolve_confirmation( $schema, $values, $entry_id, $form_id ) {
	$context = array(
		'schema'   => $schema,
		'values'   => $values,
		'form_id'  => $form_id,
		'entry_id' => $entry_id,
		'entry'    => $entry_id ? alltfo_prepare_entry( $entry_id ) : array(),
		'format'   => 'html',
	);

	$chosen = null;

	foreach ( $schema['confirmations'] as $confirmation ) {
		if ( empty( $confirmation['enabled'] ) ) {
			continue;
		}

		if ( alltfo_logic_conditions_met( $confirmation['logic'], $values, $schema ) ) {
			$chosen = $confirmation;
			break;
		}
	}

	if ( ! $chosen ) {
		$chosen = alltfo_default_confirmation();
	}

	$resolved = array(
		'type'    => $chosen['type'],
		'message' => '',
		'url'     => '',
		'success' => alltfo_resolve_success_screen( $chosen, $context ),
	);

	if ( 'message' === $chosen['type'] ) {
		$message = '' !== trim( (string) $chosen['message'] )
			? $chosen['message']
			: __( 'Thank you. Your submission has been received.', 'allterrain-forms' );

		// Kses'd here, at resolution, so both render paths carry the same
		// armour: the no-JS path escapes again in `alltfo_success_screen_html()`,
		// but the AJAX path hands this string straight to the bundle, which
		// injects it as HTML. Field values inside it are already tag-free —
		// every field type's sanitiser strips tags — so this is the second
		// layer, not the first.
		$resolved['message'] = wp_kses_post( alltfo_replace_merge_tags( $message, $context ) );
	} else {
		$resolved['url'] = alltfo_confirmation_url( $chosen, $context );

		// A redirect with nowhere to go would leave the visitor looking at a
		// form that appears to have done nothing. Falling back to a message is
		// the only outcome that still tells them it worked.
		if ( '' === $resolved['url'] ) {
			$resolved['type']    = 'message';
			$resolved['message'] = __( 'Thank you. Your submission has been received.', 'allterrain-forms' );
		}
	}

	/**
	 * Filters the resolved confirmation for a submission.
	 *
	 * @since 0.1.0
	 *
	 * @param array $resolved { type, message, url, success }.
	 * @param array $schema   The form schema.
	 * @param array $values   The accepted values.
	 * @param int   $entry_id The stored entry.
	 */
	return apply_filters( 'alltfo_confirmation', $resolved, $schema, $values, $entry_id );
}

/**
 * The confirmation a form gets when it configures none.
 *
 * @since 0.1.0
 *
 * @return array
 */
function alltfo_default_confirmation() {
	/**
	 * Filters the confirmation used when a form configures none.
	 *
	 * @since 0.1.0
	 *
	 * @param array $confirmation The default confirmation.
	 */
	return apply_filters(
		'alltfo_default_confirmation',
		array(
			'id'      => 'default',
			'enabled' => true,
			'name'    => __( 'Default', 'allterrain-forms' ),
			'type'    => 'message',
			'message' => __( 'Thank you. Your submission has been received.', 'allterrain-forms' ),
			'url'     => '',
			'pageId'  => 0,
			'query'   => '',
			'success' => alltfo_normalize_success_screen( array() ),
			'logic'   => array(
				'enabled' => false,
				'action'  => 'show',
				'match'   => 'all',
				'rules'   => array(),
			),
		)
	);
}

/**
 * Resolves a confirmation's success screen for the visitor in front of it.
 *
 * Normalised again on the way out -- the confirmation may have arrived from
 * `alltfo_default_confirmation()` or a filter rather than from a stored schema --
 * and the two visitor-facing strings get their merge tags replaced, so a title
 * can greet somebody by name just like the message can.
 *
 * @since 0.2.0
 *
 * @param array $confirmation The chosen confirmation.
 * @param array $context      The merge-tag context.
 * @return array The success screen config.
 */
function alltfo_resolve_success_screen( $confirmation, $context ) {
	$success = alltfo_normalize_success_screen(
		isset( $confirmation['success'] ) && is_array( $confirmation['success'] ) ? $confirmation['success'] : array()
	);

	$text_context = array_merge( $context, array( 'format' => 'text' ) );

	$success['title']       = alltfo_replace_merge_tags( $success['title'], $text_context );
	$success['buttonLabel'] = alltfo_replace_merge_tags( $success['buttonLabel'], $text_context );

	return $success;
}

/**
 * The success screen as static markup.
 *
 * This is what the no-JavaScript fallback shows: the same structure the form
 * bundle builds after an AJAX submit, minus the canvas animations -- a page
 * that has just reloaded is no place for a confetti cannon, and without the
 * bundle there is nothing to fire one. The classes match, so the stylesheet's
 * entrance animations still play where CSS alone can carry them.
 *
 * @since 0.2.0
 *
 * @param string $message The resolved confirmation message (safe HTML).
 * @param array  $success The resolved success screen config.
 * @return string
 */
function alltfo_success_screen_html( $message, $success ) {
	$success = alltfo_normalize_success_screen( is_array( $success ) ? $success : array() );

	if ( 'plain' === $success['style'] ) {
		return sprintf(
			'<div class="atf-confirmation" role="status" tabindex="-1">%s</div>',
			wp_kses_post( $message )
		);
	}

	$styles = alltfo_success_styles();
	$icon   = '' !== $success['icon']
		? $success['icon']
		: ( isset( $styles[ $success['style'] ]['icon'] ) ? $styles[ $success['style'] ]['icon'] : '' );

	// The accent rides as the theme's own accent token, scoped to the screen,
	// so everything inside that reads the accent recolours together -- the same
	// contract the bundle's renderer keeps.
	$accent = '' !== $success['accent']
		? sprintf( ' style="--atf-accent: %s"', esc_attr( $success['accent'] ) )
		: '';

	$out = sprintf(
		'<div class="atf-confirmation atf-success atf-success--%s" role="status" tabindex="-1"%s>',
		esc_attr( $success['style'] ),
		$accent
	);

	$out .= '<div class="atf-success__inner">';

	if ( 'check' === $success['style'] ) {
		$out .= '<svg class="atf-success__check" viewBox="0 0 52 52" aria-hidden="true">'
			. '<circle class="atf-success__check-ring" cx="26" cy="26" r="24" fill="none" />'
			. '<path class="atf-success__check-mark" fill="none" d="M14 27l8 8 16-17" /></svg>';
	} elseif ( '' !== $icon ) {
		$out .= sprintf( '<span class="atf-success__icon" aria-hidden="true">%s</span>', esc_html( $icon ) );
	}

	if ( '' !== $success['title'] ) {
		$out .= sprintf( '<h2 class="atf-success__title">%s</h2>', esc_html( $success['title'] ) );
	}

	$out .= sprintf( '<div class="atf-success__message">%s</div>', wp_kses_post( $message ) );

	if ( $success['showButton'] ) {
		// A link back to the page it was on, not a button: without the bundle
		// there is no form left in the DOM to bring back, and a reload is the
		// one reset that always works.
		$label = '' !== $success['buttonLabel'] ? $success['buttonLabel'] : __( 'Fill it in again', 'allterrain-forms' );
		$out  .= sprintf(
			'<a class="atf-button atf-button--ghost atf-success__again" href="%s">%s</a>',
			esc_url( remove_query_arg( 'atf' ) ),
			esc_html( $label )
		);
	}

	return $out . '</div></div>';
}

/**
 * Builds the URL a redirect confirmation goes to.
 *
 * The query string may carry merge tags, so a confirmation page can greet the
 * visitor by name. Each value is URL-encoded after merging, because a merge tag
 * resolves to whatever somebody typed into a form -- including ampersands, which
 * would otherwise inject extra parameters.
 *
 * @since 0.1.0
 *
 * @param array $confirmation The chosen confirmation.
 * @param array $context      The merge-tag context.
 * @return string An absolute URL, or an empty string.
 */
function alltfo_confirmation_url( $confirmation, $context ) {
	$url = '';

	if ( 'page' === $confirmation['type'] && $confirmation['pageId'] ) {
		$url = (string) get_permalink( $confirmation['pageId'] );
	}

	if ( 'redirect' === $confirmation['type'] ) {
		$url = alltfo_replace_merge_tags( $confirmation['url'], array_merge( $context, array( 'format' => 'text' ) ) );
	}

	if ( '' === $url ) {
		return '';
	}

	if ( '' !== $confirmation['query'] ) {
		$query = alltfo_replace_merge_tags( $confirmation['query'], array_merge( $context, array( 'format' => 'text' ) ) );
		$pairs = array();

		parse_str( $query, $pairs );

		// Scalars only. `parse_str()` builds nested arrays from bracketed
		// keys, and a merge tag resolves to whatever the visitor typed --
		// including brackets -- so an array here is visitor-reachable, and
		// `rawurlencode()` on an array is a fatal on PHP 8. A nested value
		// has no single defensible flattening, so it is dropped instead.
		$encoded = array();

		foreach ( wp_unslash( $pairs ) as $pair_key => $pair_value ) {
			if ( is_scalar( $pair_value ) ) {
				$encoded[ $pair_key ] = rawurlencode( (string) $pair_value );
			}
		}

		if ( $encoded ) {
			$url = add_query_arg( $encoded, $url );
		}
	}

	// Control characters out; the host check happens at redirect time in
	// `alltfo_redirect_to_confirmation()`.
	return wp_sanitize_redirect( $url );
}

/**
 * Sends the visitor to a confirmation URL.
 *
 * `wp_safe_redirect()` refuses any host but this one, which is the correct
 * default and the wrong answer here: sending a visitor to a payment provider or
 * an external thank-you page is a legitimate thing for a form to do, and it is
 * configured by somebody who already holds `alltfo_edit_forms`.
 *
 * So the target's host is allowed explicitly, for this one redirect, and the
 * safe redirect still runs. That keeps the protection that matters -- a URL
 * arriving in the *request* can never redirect anywhere, because only a stored
 * confirmation reaches this function -- while letting a form author point
 * somewhere real. The filter is removed immediately afterwards so the allowance
 * cannot leak into another redirect later in the request.
 *
 * @since 0.1.0
 *
 * @param string $url The confirmation URL.
 * @return void
 */
function alltfo_redirect_to_confirmation( $url ) {
	$host = wp_parse_url( $url, PHP_URL_HOST );

	$allow = static function ( $hosts ) use ( $host ) {
		if ( $host ) {
			$hosts[] = $host;
		}

		return $hosts;
	};

	add_filter( 'allowed_redirect_hosts', $allow );

	$redirected = wp_safe_redirect( $url );

	remove_filter( 'allowed_redirect_hosts', $allow );

	if ( $redirected ) {
		exit;
	}
}

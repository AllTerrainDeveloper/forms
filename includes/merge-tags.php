<?php
/**
 * Merge tags.
 *
 * `{field:f3}` in a subject line, `{all_fields}` in a message body, `{user:email}`
 * in a To address. One resolver, used by notifications, confirmations, webhooks
 * and post-submit actions, so a tag that works in one works in all of them.
 *
 * Everything here returns **plain text**. Escaping belongs to the destination:
 * an HTML e-mail escapes what it interpolates, a redirect URL encodes it, a
 * webhook JSON-encodes it. A resolver that returned HTML would be wrong for two
 * of those three and would put an escaping decision in the wrong file.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Replaces every merge tag in a string.
 *
 * @since 0.1.0
 *
 * @param string $text    The text to process.
 * @param array  $context {
 *     What the tags may read.
 *
 *     @type array  $schema   The form schema.
 *     @type array  $values   Field id => value.
 *     @type int    $form_id  The form.
 *     @type int    $entry_id The entry, when there is one.
 *     @type array  $entry    The prepared entry record, when there is one.
 *     @type string $format   `text` or `html`. Decides how `{all_fields}` renders.
 * }
 * @return string The text with tags resolved.
 */
function atf_replace_merge_tags( $text, $context = array() ) {
	$text = (string) $text;

	if ( '' === $text || false === strpos( $text, '{' ) ) {
		return $text;
	}

	$context = wp_parse_args(
		$context,
		array(
			'schema'   => array(),
			'values'   => array(),
			'form_id'  => 0,
			'entry_id' => 0,
			'entry'    => array(),
			'format'   => 'text',
		)
	);

	$text = preg_replace_callback(
		'/\{([a-z_]+)(?::([^}]*))?\}/i',
		static function ( $matches ) use ( $context ) {
			return atf_resolve_merge_tag( strtolower( $matches[1] ), isset( $matches[2] ) ? $matches[2] : '', $context );
		},
		$text
	);

	/**
	 * Filters text after merge tags have been resolved.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text    The resolved text.
	 * @param array  $context The resolution context.
	 */
	return apply_filters( 'atf_merge_tags_replaced', $text, $context );
}

/**
 * Resolves one tag.
 *
 * An unrecognised tag returns itself. Returning an empty string instead would
 * quietly eat `{"json": "here"}` in a webhook body and any other brace-shaped
 * text that was never meant as a tag at all.
 *
 * @since 0.1.0
 *
 * @param string $tag      The tag name, lowercased.
 * @param string $argument Everything after the first colon.
 * @param array  $context  The resolution context.
 * @return string
 */
function atf_resolve_merge_tag( $tag, $argument, $context ) {
	$schema = $context['schema'];
	$values = $context['values'];

	switch ( $tag ) {
		case 'field':
			return atf_resolve_field_tag( $argument, $context );

		case 'all_fields':
			return atf_render_all_fields( $schema, $values, $context );

		case 'form':
			if ( 'id' === $argument ) {
				return (string) $context['form_id'];
			}

			return $context['form_id'] ? get_the_title( $context['form_id'] ) : '';

		case 'entry':
			return atf_resolve_entry_tag( $argument, $context );

		case 'user':
			return atf_resolve_user_tag( $argument );

		case 'site':
			if ( 'url' === $argument ) {
				return home_url();
			}

			if ( 'admin_email' === $argument ) {
				return (string) get_option( 'admin_email' );
			}

			return wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );

		case 'admin_email':
			return (string) get_option( 'admin_email' );

		case 'date':
			return wp_date( '' !== $argument ? $argument : (string) get_option( 'date_format' ) );

		case 'time':
			return wp_date( '' !== $argument ? $argument : (string) get_option( 'time_format' ) );

		case 'ip':
			return isset( $context['entry']['ip'] ) ? (string) $context['entry']['ip'] : atf_client_ip();

		case 'referrer':
			return isset( $context['entry']['referrer'] ) ? (string) $context['entry']['referrer'] : '';

		case 'quiz':
			return atf_resolve_quiz_tag( $argument, $context );

		case 'resume_link':
			return isset( $context['entry']['resumeUrl'] ) ? (string) $context['entry']['resumeUrl'] : '';
	}

	/**
	 * Resolves a merge tag this plugin does not know.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $value    Null until something resolves it.
	 * @param string      $tag      The tag name.
	 * @param string      $argument Everything after the first colon.
	 * @param array       $context  The resolution context.
	 */
	$resolved = apply_filters( 'atf_resolve_merge_tag', null, $tag, $argument, $context );

	if ( null !== $resolved ) {
		return (string) $resolved;
	}

	return '' === $argument ? '{' . $tag . '}' : '{' . $tag . ':' . $argument . '}';
}

/**
 * Resolves `{field:id}` and its modifiers.
 *
 * `{field:f3}` is the value, `{field:f3:label}` the field's label, and
 * `{field:f3:value}` the raw stored value rather than the formatted one -- which
 * matters for a webhook that wants the choice's value, not its label.
 *
 * @since 0.1.0
 *
 * @param string $argument Everything after `field:`.
 * @param array  $context  The resolution context.
 * @return string
 */
function atf_resolve_field_tag( $argument, $context ) {
	$parts    = explode( ':', $argument );
	$field_id = trim( $parts[0] );
	$modifier = isset( $parts[1] ) ? strtolower( trim( $parts[1] ) ) : '';

	if ( '' === $field_id ) {
		return '';
	}

	$field = atf_find_field( $context['schema'], $field_id );

	if ( ! $field ) {
		return '';
	}

	if ( 'label' === $modifier ) {
		return (string) $field['label'];
	}

	$value = isset( $context['values'][ $field_id ] ) ? $context['values'][ $field_id ] : '';

	if ( 'value' === $modifier ) {
		return is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
	}

	return atf_format_field_value( $value, $field, 'email' );
}

/**
 * Resolves `{entry:…}`.
 *
 * @since 0.1.0
 *
 * @param string $argument The modifier.
 * @param array  $context  The resolution context.
 * @return string
 */
function atf_resolve_entry_tag( $argument, $context ) {
	$entry_id = (int) $context['entry_id'];

	switch ( $argument ) {
		case 'id':
			return (string) $entry_id;

		case 'date':
			return $entry_id ? get_the_date( '', $entry_id ) : wp_date( (string) get_option( 'date_format' ) );

		case 'url':
			// Deep-links the entries window straight to this entry, so the
			// notification e-mail is one click from the submission it is about.
			return $entry_id ? add_query_arg(
				array(
					'page'  => 'allterrain-forms-entries',
					'entry' => $entry_id,
				),
				admin_url( 'admin.php' )
			) : '';

		default:
			return (string) $entry_id;
	}
}

/**
 * Resolves `{user:…}` for whoever submitted the form.
 *
 * Empty for a logged-out visitor rather than "Guest", because these tags mostly
 * land in e-mail addresses and salutations where an empty string is silent and a
 * placeholder is embarrassing.
 *
 * @since 0.1.0
 *
 * @param string $argument The modifier.
 * @return string
 */
function atf_resolve_user_tag( $argument ) {
	$user = wp_get_current_user();

	if ( ! $user || ! $user->exists() ) {
		return '';
	}

	switch ( $argument ) {
		case 'email':
			return $user->user_email;

		case 'id':
			return (string) $user->ID;

		case 'login':
			return $user->user_login;

		case 'first_name':
			return $user->first_name;

		case 'last_name':
			return $user->last_name;

		default:
			return $user->display_name;
	}
}

/**
 * Resolves `{quiz:…}`.
 *
 * @since 0.1.0
 *
 * @param string $argument The modifier.
 * @param array  $context  The resolution context.
 * @return string
 */
function atf_resolve_quiz_tag( $argument, $context ) {
	$score = atf_score_quiz( $context['schema'], $context['values'] );

	switch ( $argument ) {
		case 'total':
			return (string) $score['total'];

		case 'percent':
			return (string) $score['percent'];

		case 'passed':
			return $score['passed'] ? __( 'Passed', 'allterrain-forms' ) : __( 'Not passed', 'allterrain-forms' );

		default:
			return (string) $score['score'];
	}
}

/**
 * Renders every answered field as a readable block.
 *
 * The default body of a notification, and the reason most people never have to
 * write one. Hidden fields are skipped -- a notification listing questions the
 * visitor never saw is confusing to read and impossible to act on.
 *
 * @since 0.1.0
 *
 * @param array $schema  The form schema.
 * @param array $values  Field id => value.
 * @param array $context The resolution context, for `format`.
 * @return string Plain text, or an HTML table when the format is `html`.
 */
function atf_render_all_fields( $schema, $values, $context = array() ) {
	$html    = isset( $context['format'] ) && 'html' === $context['format'];
	$visible = atf_visible_fields( $schema, $values );
	$rows    = array();

	foreach ( atf_input_fields( $schema ) as $field ) {
		if ( empty( $visible[ $field['id'] ] ) ) {
			continue;
		}

		// A password is never in an entry and never in an e-mail. Its formatter
		// already returns an empty string; skipping the row too means the
		// notification does not carry an empty "Password:" line advertising
		// that there was one.
		if ( 'password' === $field['type'] ) {
			continue;
		}

		$value = isset( $values[ $field['id'] ] ) ? $values[ $field['id'] ] : '';
		$text  = atf_format_field_value( $value, $field, 'email' );

		if ( '' === trim( $text ) ) {
			continue;
		}

		$label  = '' !== $field['label'] ? $field['label'] : $field['id'];
		$rows[] = array( $label, $text );
	}

	if ( ! $rows ) {
		return $html ? '' : __( '(no answers)', 'allterrain-forms' );
	}

	if ( ! $html ) {
		$lines = array();

		foreach ( $rows as $row ) {
			$lines[] = $row[0] . ': ' . $row[1];
		}

		return implode( "\n\n", $lines );
	}

	$out = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse">';

	foreach ( $rows as $row ) {
		$out .= sprintf(
			'<tr><th align="left" valign="top" style="padding:8px 12px 8px 0;border-bottom:1px solid #e0e0e0;font-weight:600">%s</th>'
			. '<td valign="top" style="padding:8px 0;border-bottom:1px solid #e0e0e0">%s</td></tr>',
			esc_html( $row[0] ),
			nl2br( esc_html( $row[1] ) )
		);
	}

	return $out . '</table>';
}

/**
 * Scores a quiz.
 *
 * Points come from the choice the visitor picked when that choice carries a
 * `points` value, and otherwise from the field's own `points` when the answer
 * matches `correct`. Both shapes exist because per-choice points express partial
 * credit and a single `correct` answer is what most quizzes actually are.
 *
 * @since 0.1.0
 *
 * @param array $schema The form schema.
 * @param array $values Field id => value.
 * @return array { score: float, total: float, percent: int, passed: bool }
 */
function atf_score_quiz( $schema, $values ) {
	$score = 0.0;
	$total = 0.0;

	foreach ( atf_input_fields( $schema ) as $field ) {
		if ( 'quiz' !== $field['type'] ) {
			continue;
		}

		$points = isset( $field['points'] ) ? (float) $field['points'] : 1.0;
		$best   = $points;

		// With per-choice points the maximum is the best choice available, not
		// the field's own `points`, or a question offering 0/2/5 would be
		// scored out of 1.
		foreach ( $field['choices'] as $choice ) {
			if ( isset( $choice['points'] ) ) {
				$best = max( $best, (float) $choice['points'] );
			}
		}

		$total += $best;
		$answer = isset( $values[ $field['id'] ] ) ? $values[ $field['id'] ] : '';

		if ( '' === $answer ) {
			continue;
		}

		$scored = false;

		foreach ( $field['choices'] as $choice ) {
			if ( isset( $choice['value'] ) && (string) $choice['value'] === (string) $answer && isset( $choice['points'] ) ) {
				$score += (float) $choice['points'];
				$scored = true;
				break;
			}
		}

		if ( ! $scored && isset( $field['correct'] ) && (string) $field['correct'] === (string) $answer ) {
			$score += $points;
		}
	}

	$percent  = $total > 0 ? (int) floor( ( $score / $total ) * 100 ) : 0;
	$settings = isset( $schema['settings']['quiz'] ) ? $schema['settings']['quiz'] : array();
	$pass     = isset( $settings['passMark'] ) ? (float) $settings['passMark'] : 0.0;

	return array(
		'score'   => $score,
		'total'   => $total,
		'percent' => $percent,
		'passed'  => $percent >= $pass,
	);
}

/**
 * The visitor's IP address, as far as it can be known.
 *
 * `REMOTE_ADDR` only. The forwarded-for headers are trivially spoofed by the
 * client, so trusting them would let a spammer defeat the rate limiter by
 * sending a different one each time -- which is worse than being wrong behind a
 * proxy, because it is wrong in the direction of letting abuse through. Sites
 * behind a real proxy set the header they trust through the filter.
 *
 * @since 0.1.0
 *
 * @return string The IP, or an empty string.
 */
function atf_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$ip = filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';

	/**
	 * Filters the client IP address.
	 *
	 * Where a site behind Cloudflare or a load balancer names the header it
	 * actually trusts. Only do this if the proxy is guaranteed to overwrite that
	 * header on the way in -- otherwise the client sets it themselves.
	 *
	 * @since 0.1.0
	 *
	 * @param string $ip The address from `REMOTE_ADDR`.
	 */
	return (string) apply_filters( 'atf_client_ip', $ip );
}

/**
 * Every merge tag, described in words a person can act on.
 *
 * The reason this lives on the server rather than in the builder's JavaScript:
 * `atf_resolve_merge_tag()` above is the only thing that decides what a tag
 * actually does, and a catalogue written anywhere else is a second list that
 * drifts from it. A tag that appears in the picker and resolves to nothing is
 * worse than a tag nobody could find, because the person who used it has no way
 * to tell whether they made a typo or the feature is broken.
 *
 * Every entry carries three things, and all three matter:
 *
 * - `label` — what the value *is*, in the vocabulary of somebody filling in an
 *   email: "The email address they gave you", not "field:f2".
 * - `tag` — the syntax, shown small and secondary. Nobody has to type it, but
 *   seeing it next to the label is how people learn it, and it is what they will
 *   recognise later when they read the text back.
 * - `sample` — what it will look like once resolved, using this site's real
 *   values where they exist. The single biggest reason merge tags feel opaque is
 *   that you cannot see what you are going to get until after you have sent a
 *   real email to a real person.
 *
 * @since 0.1.0
 *
 * @param int $form_id The form being edited. 0 for a catalogue with no form.
 * @return array[] Groups of `array( 'id', 'label', 'items' )`.
 */
function atf_merge_tag_catalogue( $form_id = 0 ) {
	$form_id = absint( $form_id );
	$schema  = $form_id ? atf_get_form_schema( $form_id ) : array( 'fields' => array() );

	$groups = array(
		atf_merge_tag_answer_group( $schema ),
		array(
			'id'    => 'person',
			'label' => __( 'The person who submitted it', 'allterrain-forms' ),
			'items' => array(
				array(
					'tag'    => '{user:email}',
					'label'  => __( 'Their account email', 'allterrain-forms' ),
					'hint'   => __( 'Only if they were logged in. Empty for a visitor.', 'allterrain-forms' ),
					'sample' => atf_merge_tag_sample( '{user:email}' ),
				),
				array(
					'tag'    => '{user:display_name}',
					'label'  => __( 'Their account name', 'allterrain-forms' ),
					'hint'   => __( 'Only if they were logged in.', 'allterrain-forms' ),
					'sample' => atf_merge_tag_sample( '{user:display_name}' ),
				),
				array(
					'tag'    => '{ip}',
					'label'  => __( 'Their IP address', 'allterrain-forms' ),
					'hint'   => __( 'Useful for spam reports. Personal data — keep it out of emails you forward.', 'allterrain-forms' ),
					'sample' => '203.0.113.42',
				),
				array(
					'tag'    => '{referrer}',
					'label'  => __( 'The page they came from', 'allterrain-forms' ),
					'hint'   => '',
					'sample' => home_url( '/' ),
				),
			),
		),
		array(
			'id'    => 'submission',
			'label' => __( 'This submission', 'allterrain-forms' ),
			'items' => array(
				array(
					'tag'    => '{all_fields}',
					'label'  => __( 'Every answer, as a table', 'allterrain-forms' ),
					'hint'   => __( 'The whole submission in one go. Most emails need nothing else.', 'allterrain-forms' ),
					'sample' => __( 'Your name: Ada Lovelace / Email: ada@example.com / …', 'allterrain-forms' ),
				),
				array(
					'tag'    => '{entry:id}',
					'label'  => __( 'The reference number', 'allterrain-forms' ),
					'hint'   => __( 'Worth putting in a subject line so replies can be matched up.', 'allterrain-forms' ),
					'sample' => '1043',
				),
				array(
					'tag'    => '{entry:url}',
					'label'  => __( 'A link to it in the admin', 'allterrain-forms' ),
					'hint'   => __( 'Only useful to people who can log in here.', 'allterrain-forms' ),
					'sample' => admin_url( 'admin.php?page=allterrain-forms-entries' ),
				),
				array(
					'tag'    => '{date}',
					'label'  => __( 'The date it arrived', 'allterrain-forms' ),
					'hint'   => __( 'In the site’s date format.', 'allterrain-forms' ),
					'sample' => wp_date( (string) get_option( 'date_format' ) ),
				),
				array(
					'tag'    => '{time}',
					'label'  => __( 'The time it arrived', 'allterrain-forms' ),
					'hint'   => '',
					'sample' => wp_date( (string) get_option( 'time_format' ) ),
				),
			),
		),
		array(
			'id'    => 'form',
			'label' => __( 'This form', 'allterrain-forms' ),
			'items' => array(
				array(
					'tag'    => '{form}',
					'label'  => __( 'The form’s name', 'allterrain-forms' ),
					'hint'   => __( 'Handy when several forms send to the same inbox.', 'allterrain-forms' ),
					'sample' => $form_id ? get_the_title( $form_id ) : __( 'Contact us', 'allterrain-forms' ),
				),
				array(
					'tag'    => '{form:id}',
					'label'  => __( 'The form’s ID', 'allterrain-forms' ),
					'hint'   => '',
					'sample' => (string) ( $form_id ? $form_id : 12 ),
				),
			),
		),
		array(
			'id'    => 'site',
			'label' => __( 'Your site', 'allterrain-forms' ),
			'items' => array(
				array(
					'tag'    => '{admin_email}',
					'label'  => __( 'The site administrator’s email', 'allterrain-forms' ),
					'hint'   => __( 'Set in Settings → General. Changes here if you change it there.', 'allterrain-forms' ),
					'sample' => atf_merge_tag_sample( '{admin_email}' ),
				),
				array(
					'tag'    => '{site}',
					'label'  => __( 'The site’s name', 'allterrain-forms' ),
					'hint'   => '',
					'sample' => atf_merge_tag_sample( '{site}' ),
				),
				array(
					'tag'    => '{site:url}',
					'label'  => __( 'The site’s address', 'allterrain-forms' ),
					'hint'   => '',
					'sample' => atf_merge_tag_sample( '{site:url}' ),
				),
			),
		),
	);

	/**
	 * Filters the merge tags offered in the builder.
	 *
	 * A plugin that adds a tag through `atf_resolve_merge_tag` should advertise
	 * it here too — otherwise the tag works but nobody can find it.
	 *
	 * @since 0.1.0
	 *
	 * @param array[] $groups  Groups of tags.
	 * @param int     $form_id The form being edited.
	 */
	return apply_filters( 'atf_merge_tag_catalogue', $groups, $form_id );
}

/**
 * The group of tags built from this form's own questions.
 *
 * Named after the label the person wrote, because `{field:f2}` is meaningless
 * to everybody including the person who built the form a fortnight ago. The tag
 * is still shown, small, so the correspondence is learnable.
 *
 * @since 0.1.0
 *
 * @param array $schema The form schema.
 * @return array One catalogue group.
 */
function atf_merge_tag_answer_group( $schema ) {
	$items = array();

	foreach ( atf_input_fields( $schema ) as $field ) {
		$label = isset( $field['label'] ) ? trim( (string) $field['label'] ) : '';

		$items[] = array(
			'tag'    => '{field:' . $field['id'] . '}',
			'label'  => '' !== $label ? $label : __( 'Untitled question', 'allterrain-forms' ),
			/* translators: %s: field type, e.g. "email". */
			'hint'   => sprintf( __( 'Their answer to this %s question.', 'allterrain-forms' ), $field['type'] ),
			'sample' => atf_merge_tag_placeholder_for( $field ),
			'type'   => $field['type'],
		);
	}

	return array(
		'id'    => 'answers',
		'label' => __( 'Their answers', 'allterrain-forms' ),
		'items' => $items,
		'empty' => __( 'This form has no questions yet. Add some on the Build tab and they will appear here.', 'allterrain-forms' ),
	);
}

/**
 * A plausible answer for one field, for the sample column.
 *
 * Chosen from the field's own choices where it has them, so the sample for a
 * dropdown is one of that dropdown's actual options rather than a generic
 * "Answer" that teaches nothing about what will arrive.
 *
 * @since 0.1.0
 *
 * @param array $field The field.
 * @return string
 */
function atf_merge_tag_placeholder_for( $field ) {
	if ( ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
		$first = reset( $field['choices'] );

		if ( isset( $first['label'] ) && '' !== $first['label'] ) {
			return (string) $first['label'];
		}
	}

	switch ( $field['type'] ) {
		case 'email':
			return 'ada@example.com';

		case 'tel':
			return '+34 600 123 456';

		case 'url':
			return 'https://example.com';

		case 'number':
			return '3';

		case 'date':
			return wp_date( (string) get_option( 'date_format' ) );

		case 'time':
			return wp_date( (string) get_option( 'time_format' ) );

		case 'name':
			return 'Ada Lovelace';

		case 'textarea':
			return __( 'A longer answer, as they typed it.', 'allterrain-forms' );

		case 'switch':
		case 'consent':
			return __( 'Yes', 'allterrain-forms' );

		default:
			return __( 'Their answer', 'allterrain-forms' );
	}
}

/**
 * What a tag resolves to on this site right now, for the sample column.
 *
 * Runs the tag through the real resolver rather than reimplementing it, so a
 * sample cannot claim something the resolver would not produce.
 *
 * @since 0.1.0
 *
 * @param string $tag The tag, braces included.
 * @return string
 */
function atf_merge_tag_sample( $tag ) {
	return atf_replace_merge_tags( $tag, array( 'format' => 'text' ) );
}

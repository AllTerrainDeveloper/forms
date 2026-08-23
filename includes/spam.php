<?php
/**
 * Spam screening without a captcha.
 *
 * Five checks, none of which asks the visitor to do anything: a honeypot, a time
 * trap, a per-IP rate limit, a word blocklist, and Akismet when the site already
 * has it. An optional arithmetic challenge exists for forms under sustained
 * attack, and is off by default.
 *
 * No reCAPTCHA, no hCaptcha, no "select all the traffic lights". Those work, but
 * they charge the visitor for the site's spam problem, they are a WCAG failure
 * for a good number of people, and they hand a third party a record of everyone
 * who filled in your form. The checks here catch the overwhelming majority of
 * automated submissions and cost the visitor nothing.
 *
 * A submission judged spam is **stored**, in the spam status, not discarded. The
 * false positive that vanishes silently is the one nobody can recover from, and
 * a form that quietly eats a real enquiry is worse than one that lets a few
 * through.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Screens a submission.
 *
 * @since 0.1.0
 *
 * @param array $schema  The form schema.
 * @param array $values  The sanitised values.
 * @param array $request The raw request, for the honeypot and timing fields.
 * @return array { spam: bool, reason: string }
 */
function alltfo_screen_for_spam( $schema, $values, $request ) {
	$settings = $schema['settings']['spam'];
	$verdict  = array(
		'spam'   => false,
		'reason' => '',
	);

	if ( ! empty( $settings['honeypot'] ) && ! empty( $request['alltfo_website'] ) ) {
		$verdict = array(
			'spam'   => true,
			'reason' => 'honeypot',
		);
	}

	if ( ! $verdict['spam'] && $settings['timeTrap'] > 0 ) {
		$elapsed = alltfo_submission_elapsed( $request );

		// A negative or absent elapsed time means the timestamp was missing or
		// its signature did not match -- which is what a script that posts a
		// bare field list produces, since it never fetched the form.
		if ( null === $elapsed || $elapsed < (int) $settings['timeTrap'] ) {
			$verdict = array(
				'spam'   => true,
				'reason' => 'too_fast',
			);
		}
	}

	if ( ! $verdict['spam'] && $settings['rateLimit'] > 0 && alltfo_rate_limit_exceeded( $schema, $settings['rateLimit'] ) ) {
		$verdict = array(
			'spam'   => true,
			'reason' => 'rate_limit',
		);
	}

	if ( ! $verdict['spam'] && '' !== trim( (string) $settings['blocklist'] ) ) {
		$hit = alltfo_blocklist_hit( $settings['blocklist'], $values );

		if ( $hit ) {
			$verdict = array(
				'spam'   => true,
				'reason' => 'blocklist',
			);
		}
	}

	if ( ! $verdict['spam'] && ! empty( $settings['challenge'] ) && ! alltfo_challenge_answered( $request ) ) {
		$verdict = array(
			'spam'   => true,
			'reason' => 'challenge',
		);
	}

	if ( ! $verdict['spam'] && ! empty( $settings['akismet'] ) && alltfo_akismet_available() ) {
		if ( alltfo_akismet_says_spam( $schema, $values ) ) {
			$verdict = array(
				'spam'   => true,
				'reason' => 'akismet',
			);
		}
	}

	/**
	 * Filters the spam verdict for a submission.
	 *
	 * The seam for a site's own screening. Returning `spam => true` files the
	 * entry under spam rather than rejecting it, so a wrong answer here is
	 * always recoverable from the Entries window.
	 *
	 * @since 0.1.0
	 *
	 * @param array $verdict { spam: bool, reason: string }.
	 * @param array $schema  The form schema.
	 * @param array $values  The submitted values.
	 * @param array $request The raw request.
	 */
	return apply_filters( 'alltfo_spam_verdict', $verdict, $schema, $values, $request );
}

/**
 * The arithmetic challenge, for a form under sustained attack.
 *
 * Off by default, and deliberately the last resort rather than the first: it is
 * the only check in this file that asks the visitor to do something. But it is
 * still a great deal kinder than an image captcha -- "what is six plus three"
 * is answerable by a screen-reader user, by somebody who cannot tell a bus from
 * a lorry in a low-resolution photograph, and by somebody on a phone in the
 * dark, and it hands no data to a third party.
 *
 * The sum is generated server-side and its answer is **signed**, never sent. A
 * challenge whose expected answer travels in the page alongside the question is
 * decoration.
 *
 * @since 0.1.0
 *
 * @param int $form_id The form, so a signature cannot be replayed on another.
 * @return array { question: string, answer: int, signature: string }
 */
function alltfo_make_challenge( $form_id ) {
	// Single digits, and addition only. The question is a spam check, not an
	// arithmetic exam -- anything harder starts excluding people, which is the
	// exact failure captchas are guilty of.
	$left  = wp_rand( 1, 9 );
	$right = wp_rand( 1, 9 );

	$answer = $left + $right;

	return array(
		'question'  => sprintf(
			/* translators: 1: first number, 2: second number. */
			__( 'What is %1$d plus %2$d?', 'allterrain-forms' ),
			$left,
			$right
		),
		'answer'    => $answer,
		'signature' => alltfo_sign_challenge( $form_id, $answer ),
	);
}

/**
 * Signs a challenge's answer.
 *
 * The hour is part of the signed material, so a captured (answer, signature)
 * pair stops replaying when its bucket ages out. The verify side accepts the
 * current and the previous hour, which gives a rendered form between one and
 * two hours to be submitted -- and a harvested pair at most that long to live,
 * where without the bucket it was valid forever.
 *
 * @since 0.1.0
 *
 * @param int    $form_id The form.
 * @param int    $answer  The expected answer.
 * @param string $bucket  Optional. The hour to sign for, as `gmdate( 'YmdH' )`
 *                        produces it. Defaults to the current hour.
 * @return string
 */
function alltfo_sign_challenge( $form_id, $answer, $bucket = '' ) {
	if ( '' === $bucket ) {
		$bucket = gmdate( 'YmdH' );
	}

	return wp_hash( 'atf-challenge|' . absint( $form_id ) . '|' . (int) $answer . '|' . $bucket );
}

/**
 * Whether the visitor answered the challenge correctly.
 *
 * @since 0.1.0
 *
 * @param array $request The raw request.
 * @return bool
 */
function alltfo_challenge_answered( $request ) {
	$given     = isset( $request['alltfo_challenge'] ) ? trim( (string) $request['alltfo_challenge'] ) : '';
	$signature = isset( $request['alltfo_challenge_sig'] ) ? (string) $request['alltfo_challenge_sig'] : '';
	$form_id   = isset( $request['alltfo_form_id'] ) ? absint( $request['alltfo_form_id'] ) : 0;

	if ( '' === $given || '' === $signature || ! is_numeric( $given ) ) {
		return false;
	}

	// The answer is checked by re-signing it rather than by comparing numbers,
	// so the expected value never has to be stored in a session or sent to the
	// browser. `hash_equals()` because this is a signature comparison. The
	// previous hour's bucket is accepted alongside the current one, so a form
	// rendered at five to the hour is not refused at five past it.
	$buckets = array( gmdate( 'YmdH' ), gmdate( 'YmdH', time() - HOUR_IN_SECONDS ) );

	foreach ( $buckets as $bucket ) {
		if ( hash_equals( alltfo_sign_challenge( $form_id, (int) $given, $bucket ), $signature ) ) {
			return true;
		}
	}

	return false;
}

/**
 * The challenge's markup.
 *
 * A real labelled field, not a hidden trick -- the visitor is being asked a
 * question and has to be able to read it.
 *
 * @since 0.1.0
 *
 * @param array $schema  The form schema.
 * @param int   $form_id The form.
 * @return string
 */
function alltfo_render_challenge( $schema, $form_id ) {
	if ( empty( $schema['settings']['spam']['challenge'] ) ) {
		return '';
	}

	$challenge = alltfo_make_challenge( $form_id );
	$id        = 'atf-challenge-' . $form_id;

	return sprintf(
		'<div class="atf-field atf-field--full atf-challenge">'
		. '<label class="atf-label" for="%1$s">%2$s<span class="atf-required" aria-hidden="true">*</span></label>'
		. '<input type="text" class="atf-input" id="%1$s" name="alltfo_challenge" inputmode="numeric"'
		. ' autocomplete="off" required aria-required="true">'
		. '<input type="hidden" name="alltfo_challenge_sig" value="%3$s">'
		. '</div>',
		esc_attr( $id ),
		esc_html( $challenge['question'] ),
		esc_attr( $challenge['signature'] )
	);
}

/**
 * How long the visitor had the form open, in seconds.
 *
 * The timestamp is only believed when its signature matches, so the trap cannot
 * be defeated by posting an older time than the one that was served.
 *
 * @since 0.1.0
 *
 * @param array $request The raw request.
 * @return int|null Seconds, or null when the timestamp is missing or forged.
 */
function alltfo_submission_elapsed( $request ) {
	$issued    = isset( $request['alltfo_t'] ) ? absint( $request['alltfo_t'] ) : 0;
	$signature = isset( $request['alltfo_ts'] ) ? (string) $request['alltfo_ts'] : '';
	$form_id   = isset( $request['alltfo_form_id'] ) ? absint( $request['alltfo_form_id'] ) : 0;

	if ( ! $issued || '' === $signature ) {
		return null;
	}

	if ( ! hash_equals( alltfo_sign_timestamp( $form_id, $issued ), $signature ) ) {
		return null;
	}

	return max( 0, time() - $issued );
}

/**
 * Whether this IP has submitted too often.
 *
 * A transient per IP per hour, incremented on every accepted submission. Chosen
 * over an entries query because it also counts submissions that were rejected,
 * which is exactly the traffic a rate limit exists to notice.
 *
 * @since 0.1.0
 *
 * @param array $schema The form schema.
 * @param int   $limit  Submissions allowed per hour.
 * @return bool
 */
function alltfo_rate_limit_exceeded( $schema, $limit ) {
	return alltfo_hit_rate_limit( 'submit', $limit );
}

/**
 * Counts a hit against an hourly per-IP limit.
 *
 * The transient's key carries the current hour, so every hour starts a fresh
 * counter and a stale one simply expires. The counter must not be a single
 * rolling transient whose expiry is pushed back on every increment -- that
 * version never decays under steady traffic, so nine legitimate submissions an
 * hour eventually looked identical to ninety in one and tripped the limit.
 *
 * @since 0.1.0
 *
 * @param string $bucket What is being limited, e.g. `submit` or `partial`, so
 *                       two different limits on one IP never share a counter.
 * @param int    $limit  Hits allowed per hour.
 * @return bool Whether the limit was already reached. Counting the hit and
 *              answering in one step, so a caller cannot forget to increment.
 */
function alltfo_hit_rate_limit( $bucket, $limit ) {
	$ip = alltfo_client_ip();

	if ( '' === $ip ) {
		return false;
	}

	// A logged-in user is not rate limited by IP. An office behind one address
	// would otherwise lock out everyone after the tenth person, and a real
	// account is already accountable in a way an anonymous visitor is not.
	if ( is_user_logged_in() ) {
		return false;
	}

	$key   = 'alltfo_rl_' . md5( $bucket . '|' . $ip . '|' . gmdate( 'YmdH' ) );
	$count = (int) get_transient( $key );

	if ( $count >= $limit ) {
		return true;
	}

	set_transient( $key, $count + 1, HOUR_IN_SECONDS );

	return false;
}

/**
 * Whether any submitted value contains a blocked word.
 *
 * One term per line, matched case-insensitively anywhere in any value. Not a
 * word-boundary match: the spam this catches routinely pads terms with
 * punctuation and zero-width characters to defeat exactly that.
 *
 * @since 0.1.0
 *
 * @param string $blocklist Newline-separated terms.
 * @param array  $values    The submitted values.
 * @return bool
 */
function alltfo_blocklist_hit( $blocklist, $values ) {
	$terms = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $blocklist ) ) );

	if ( ! $terms ) {
		return false;
	}

	$haystack = strtolower( alltfo_flatten_values( $values ) );

	foreach ( $terms as $term ) {
		if ( '' !== $term && false !== strpos( $haystack, strtolower( $term ) ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Every submitted value as one searchable string.
 *
 * @since 0.1.0
 *
 * @param mixed $values The values.
 * @return string
 */
function alltfo_flatten_values( $values ) {
	if ( is_scalar( $values ) ) {
		return (string) $values;
	}

	if ( ! is_array( $values ) ) {
		return '';
	}

	$parts = array();

	foreach ( $values as $value ) {
		$parts[] = alltfo_flatten_values( $value );
	}

	return implode( ' ', $parts );
}

/**
 * Whether Akismet is installed, configured and usable.
 *
 * @since 0.1.0
 *
 * @return bool
 */
function alltfo_akismet_available() {
	return class_exists( 'Akismet' ) && method_exists( 'Akismet', 'get_api_key' ) && Akismet::get_api_key();
}

/**
 * Asks Akismet about a submission.
 *
 * Best-effort: any failure to reach the service returns "not spam". A form that
 * refuses submissions because a third-party API is having a bad afternoon is a
 * worse outcome than a few pieces of spam.
 *
 * @since 0.1.0
 *
 * @param array $schema The form schema.
 * @param array $values The submitted values.
 * @return bool
 */
function alltfo_akismet_says_spam( $schema, $values ) {
	if ( ! alltfo_akismet_available() ) {
		return false;
	}

	$author = '';
	$email  = '';

	// Akismet's accuracy depends heavily on being given the author and their
	// e-mail, so the first name-ish and the first e-mail field are found rather
	// than requiring the form's builder to nominate them.
	foreach ( alltfo_input_fields( $schema ) as $field ) {
		$value = isset( $values[ $field['id'] ] ) ? $values[ $field['id'] ] : '';

		if ( '' === $email && 'email' === $field['type'] && is_string( $value ) ) {
			$email = $value;
		}

		if ( '' === $author && 'name' === $field['type'] && is_array( $value ) ) {
			$author = trim( implode( ' ', array_filter( $value ) ) );
		}

		if ( '' === $author && 'text' === $field['type'] && is_string( $value ) && '' !== $value ) {
			$label = strtolower( $field['label'] );

			if ( false !== strpos( $label, 'name' ) ) {
				$author = $value;
			}
		}
	}

	$request = array(
		'blog'                 => get_option( 'home' ),
		'user_ip'              => alltfo_client_ip(),
		'user_agent'           => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
		'referrer'             => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
		'comment_type'         => 'contact-form',
		'comment_author'       => $author,
		'comment_author_email' => $email,
		'comment_content'      => alltfo_flatten_values( $values ),
		'blog_lang'            => get_locale(),
		'blog_charset'         => get_option( 'blog_charset' ),
	);

	$response = Akismet::http_post( build_query( $request ), 'comment-check' );

	return isset( $response[1] ) && 'true' === trim( $response[1] );
}

/**
 * Tells Akismet it got one wrong.
 *
 * Called when somebody marks an entry as spam or not-spam in the Entries window,
 * which is what keeps the service learning about this particular site rather
 * than only about the internet in general.
 *
 * @since 0.1.0
 *
 * @param int    $entry_id The entry.
 * @param string $verdict  `spam` or `ham`.
 * @return void
 */
function alltfo_akismet_submit_correction( $entry_id, $verdict ) {
	if ( ! alltfo_akismet_available() ) {
		return;
	}

	$context = json_decode( (string) get_post_meta( $entry_id, ALLTFO_META_CONTEXT, true ), true );
	$values  = json_decode( (string) get_post_meta( $entry_id, ALLTFO_META_VALUES, true ), true );

	if ( ! is_array( $context ) || ! is_array( $values ) ) {
		return;
	}

	$request = array(
		'blog'            => get_option( 'home' ),
		'user_ip'         => isset( $context['ip'] ) ? $context['ip'] : '',
		'user_agent'      => isset( $context['userAgent'] ) ? $context['userAgent'] : '',
		'comment_type'    => 'contact-form',
		'comment_content' => alltfo_flatten_values( $values ),
	);

	Akismet::http_post( build_query( $request ), 'spam' === $verdict ? 'submit-spam' : 'submit-ham' );
}

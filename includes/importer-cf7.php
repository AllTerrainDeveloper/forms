<?php
/**
 * Importer — Contact Form 7.
 *
 * CF7 stores each form as a `wpcf7_contact_form` post whose meta holds a text
 * template (`_form`), the mail settings (`_mail`, `_mail_2`) and the messages
 * (`_messages`). All of that survives the plugin being deactivated, so this
 * importer reads the posts directly and never needs CF7's classes — the moment
 * somebody most wants to import is right after switching the old plugin off.
 *
 * The template is a run of form-tags like `[text* your-name]`, usually wrapped
 * in `<label>` markup carrying the human question. Both halves matter: the tag
 * becomes the field, the label text becomes the field's label, and the mail
 * template's `[your-name]` references become `{field:…}` merge tags on the new
 * form's notification.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the importer.
 *
 * @since 0.2.0
 *
 * @param array[] $importers Importer id => definition.
 * @return array[]
 */
function atf_register_cf7_importer( $importers ) {
	$importers['contact-form-7'] = array(
		'label'     => __( 'Contact Form 7', 'allterrain-forms' ),
		'available' => 'atf_cf7_available',
		'forms'     => 'atf_cf7_forms',
		'import'    => 'atf_cf7_import',
	);

	return $importers;
}
add_filter( 'atf_importers', 'atf_register_cf7_importer' );

/**
 * Whether any Contact Form 7 forms exist on this site.
 *
 * Counted from the posts table rather than asking the plugin, so the data is
 * found whether CF7 is active, deactivated or already deleted.
 *
 * @since 0.2.0
 *
 * @return bool
 */
function atf_cf7_available() {
	return (bool) atf_cf7_forms();
}

/**
 * The CF7 forms on this site, id => title.
 *
 * @since 0.2.0
 *
 * @return array
 */
function atf_cf7_forms() {
	$posts = get_posts(
		array(
			'post_type'      => 'wpcf7_contact_form',
			'post_status'    => 'any',
			// A hard bound, not a page size: a site with more CF7 forms than
			// this has other problems, and an unbounded query on a page load
			// is how an importer takes the admin down with it.
			'posts_per_page' => 200,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);

	$forms = array();

	foreach ( $posts as $post ) {
		$forms[ (string) $post->ID ] = '' !== $post->post_title ? $post->post_title : __( '(untitled)', 'allterrain-forms' );
	}

	return $forms;
}

/**
 * Imports one CF7 form.
 *
 * @since 0.2.0
 *
 * @param string $source_id The CF7 post id.
 * @return int|WP_Error The new form's id.
 */
function atf_cf7_import( $source_id ) {
	$post = get_post( absint( $source_id ) );

	if ( ! $post || 'wpcf7_contact_form' !== $post->post_type ) {
		return new WP_Error( 'atf_import_missing', __( 'That form no longer exists.', 'allterrain-forms' ) );
	}

	$schema = atf_cf7_convert(
		(string) get_post_meta( $post->ID, '_form', true ),
		(array) get_post_meta( $post->ID, '_mail', true ),
		(array) get_post_meta( $post->ID, '_mail_2', true ),
		(array) get_post_meta( $post->ID, '_messages', true )
	);

	return atf_create_imported_form( $post->post_title, $schema, 'contact-form-7', (string) $post->ID );
}

/**
 * Converts one CF7 form's stored pieces into a schema.
 *
 * Pure — no database, no capability checks — so the conversion rules can be
 * tested against fixture templates without inserting posts.
 *
 * @since 0.2.0
 *
 * @param string $template The `_form` template.
 * @param array  $mail     The `_mail` settings.
 * @param array  $mail_2   The `_mail_2` settings.
 * @param array  $messages The `_messages` strings.
 * @return array A raw schema, ready for `atf_normalize_schema()`.
 */
function atf_cf7_convert( $template, $mail, $mail_2, $messages ) {
	$parsed = atf_cf7_parse_template( $template );

	$schema = array(
		'fields'        => $parsed['fields'],
		'notifications' => array(),
		'confirmations' => array(),
	);

	if ( '' !== $parsed['submit_label'] ) {
		$schema['settings'] = array( 'submitLabel' => $parsed['submit_label'] );
	}

	$notification = atf_cf7_convert_mail( $mail, $parsed['map'], __( 'Notification', 'allterrain-forms' ) );

	if ( $notification ) {
		$schema['notifications'][] = $notification;
	}

	// Mail (2) only counts when its own switch is on — CF7 stores the settings
	// either way, and importing a disabled autoresponder as a live one would
	// start e-mailing visitors nobody meant to e-mail.
	if ( ! empty( $mail_2['active'] ) ) {
		$second = atf_cf7_convert_mail( $mail_2, $parsed['map'], __( 'Mail (2)', 'allterrain-forms' ) );

		if ( $second ) {
			$schema['notifications'][] = $second;
		}
	}

	if ( ! empty( $messages['mail_sent_ok'] ) ) {
		$schema['confirmations'][] = array(
			'name'    => __( 'Thank you', 'allterrain-forms' ),
			'type'    => 'message',
			'message' => (string) $messages['mail_sent_ok'],
		);
	}

	return $schema;
}

/**
 * Parses a CF7 template into fields.
 *
 * @since 0.2.0
 *
 * @param string $template The `_form` template.
 * @return array {
 *     @type array[] $fields       The converted fields, in template order.
 *     @type array   $map          CF7 field name => new field id.
 *     @type string  $submit_label The `[submit]` tag's label, or ''.
 * }
 */
function atf_cf7_parse_template( $template ) {
	$fields       = array();
	$map          = array();
	$submit_label = '';
	$next         = 1;

	if ( ! preg_match_all( '/\[([a-zA-Z_][a-zA-Z0-9_]*)(\*)?((?:[^\]"]|"[^"]*")*)\]/', (string) $template, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
		return array(
			'fields'       => $fields,
			'map'          => $map,
			'submit_label' => $submit_label,
		);
	}

	foreach ( $matches as $match ) {
		$type     = strtolower( $match[1][0] );
		$required = '' !== $match[2][0];
		$rest     = atf_cf7_parse_tag_body( isset( $match[3][0] ) ? $match[3][0] : '' );
		$offset   = $match[0][1];

		if ( 'submit' === $type ) {
			$submit_label = isset( $rest['values'][0] ) ? $rest['values'][0] : '';
			continue;
		}

		// A closing tag ([/acceptance]) or a tag with no name is not a field.
		if ( '' === $rest['name'] || '/' === $type[0] ) {
			continue;
		}

		$field = atf_cf7_tag_to_field( $type, $required, $rest, "f{$next}" );

		if ( ! $field ) {
			continue;
		}

		$field['label'] = atf_cf7_label_for( (string) $template, $offset, $rest['name'] );

		// Acceptance carries its condition as tag *content* —
		// `[acceptance name] I agree… [/acceptance]` — which reads better as
		// the consent line than as the label.
		if ( 'consent' === $field['type'] ) {
			$content = atf_cf7_tag_content( (string) $template, $offset + strlen( $match[0][0] ), 'acceptance' );

			if ( '' !== $content ) {
				$field['consentText'] = $content;
			}
		}

		$fields[]             = $field;
		$map[ $rest['name'] ] = $field['id'];
		++$next;
	}

	return array(
		'fields'       => $fields,
		'map'          => $map,
		'submit_label' => $submit_label,
	);
}

/**
 * Splits a tag's body into its name, options and quoted values.
 *
 * CF7's grammar inside the brackets: the first bare word is the field name,
 * further bare words are options (`min:1`, `placeholder`, `multiple`), and
 * every double-quoted string is a value — a choice, a default, a label.
 *
 * @since 0.2.0
 *
 * @param string $body Everything between the tag's type and its `]`.
 * @return array { name: string, options: string[], values: string[] }
 */
function atf_cf7_parse_tag_body( $body ) {
	$name    = '';
	$options = array();
	$values  = array();

	preg_match_all( '/"([^"]*)"|(\S+)/', (string) $body, $parts, PREG_SET_ORDER );

	foreach ( $parts as $part ) {
		if ( isset( $part[1] ) && '' !== $part[0] && '"' === $part[0][0] ) {
			$values[] = $part[1];
			continue;
		}

		if ( '' === $name ) {
			$name = $part[2];
			continue;
		}

		$options[] = $part[2];
	}

	return array(
		'name'    => $name,
		'options' => $options,
		'values'  => $values,
	);
}

/**
 * One CF7 option's parameter, e.g. `min:3` => `3`.
 *
 * @since 0.2.0
 *
 * @param string[] $options The tag's bare options.
 * @param string   $key     The option name.
 * @return string The part after the colon, or '' when absent.
 */
function atf_cf7_option( $options, $key ) {
	foreach ( $options as $option ) {
		if ( 0 === strpos( $option, $key . ':' ) ) {
			return substr( $option, strlen( $key ) + 1 );
		}
	}

	return '';
}

/**
 * Converts one parsed tag into a field.
 *
 * @since 0.2.0
 *
 * @param string $type     The CF7 tag type, without the `*`.
 * @param bool   $required Whether the tag carried a `*`.
 * @param array  $rest     The parsed body: name, options, values.
 * @param string $id       The id the new field will take.
 * @return array|null The field, or null when the tag has no equivalent.
 */
function atf_cf7_tag_to_field( $type, $required, $rest, $id ) {
	$simple = array(
		'text'     => 'text',
		'email'    => 'email',
		'url'      => 'url',
		'tel'      => 'tel',
		'textarea' => 'textarea',
		'number'   => 'number',
		'range'    => 'range',
		'date'     => 'date',
		'hidden'   => 'hidden',
		'file'     => 'file',
	);

	$field = array(
		'id'       => $id,
		'required' => $required,
	);

	$options = $rest['options'];
	$values  = $rest['values'];

	if ( isset( $simple[ $type ] ) ) {
		$field['type'] = $simple[ $type ];

		// The first quoted value is the default — unless the `placeholder`
		// option (or its older alias `watermark`) says it is the grey hint
		// text instead. CF7 overloads the slot; the option is the tiebreak.
		if ( isset( $values[0] ) && '' !== $values[0] ) {
			if ( in_array( 'placeholder', $options, true ) || in_array( 'watermark', $options, true ) ) {
				$field['placeholder'] = $values[0];
			} else {
				$field['default'] = $values[0];
			}
		}

		foreach ( array( 'min', 'max', 'minlength', 'maxlength', 'step' ) as $bound ) {
			$value = atf_cf7_option( $options, $bound );

			if ( '' !== $value ) {
				$field[ $bound ] = $value;
			}
		}

		if ( 'file' === $field['type'] ) {
			unset( $field['default'], $field['placeholder'] );

			$filetypes = atf_cf7_option( $options, 'filetypes' );

			if ( '' !== $filetypes ) {
				$field['filetypes'] = array_values( array_filter( array_map( 'trim', explode( '|', strtolower( $filetypes ) ) ) ) );
			}
		}

		return $field;
	}

	switch ( $type ) {
		case 'select':
			$field['type']    = in_array( 'multiple', $options, true ) ? 'multiselect' : 'select';
			$field['choices'] = atf_cf7_choices( $values );

			return $field;

		case 'checkbox':
			// An `exclusive` checkbox group allows exactly one answer, which
			// is a radio group wearing checkbox clothes.
			$field['type']    = in_array( 'exclusive', $options, true ) ? 'radio' : 'checkboxes';
			$field['choices'] = atf_cf7_choices( $values );

			return $field;

		case 'radio':
			$field['type']    = 'radio';
			$field['choices'] = atf_cf7_choices( $values );

			return $field;

		case 'acceptance':
			$field['type'] = 'consent';
			// CF7 acceptance is required by default; the `optional` option is
			// how a form relaxes it.
			$field['required'] = ! in_array( 'optional', $options, true );

			return $field;

		case 'quiz':
			// CF7's quiz is an anti-spam question, and this plugin already
			// screens for spam without charging the visitor for it.
		case 'captchac':
		case 'captchar':
		case 'count':
		case 'response':
			return null;
	}

	// An unrecognised tag — a CF7 add-on's type — becomes a plain text field
	// rather than disappearing: a visible field with the right label is
	// reviewable in the builder, a silently dropped one is data loss.
	$field['type'] = 'text';

	return $field;
}

/**
 * Turns CF7's quoted choice values into choices.
 *
 * CF7 supports a `label|value` pipe syntax inside each quoted string; without
 * a pipe the label is the value, as ordinary CF7 forms use.
 *
 * @since 0.2.0
 *
 * @param string[] $values The tag's quoted strings.
 * @return array[]
 */
function atf_cf7_choices( $values ) {
	$choices = array();

	foreach ( $values as $value ) {
		$parts = explode( '|', $value, 2 );

		$choices[] = array(
			'label' => trim( $parts[0] ),
			'value' => trim( isset( $parts[1] ) ? $parts[1] : $parts[0] ),
		);
	}

	return $choices;
}

/**
 * The human label for a tag, read from the markup around it.
 *
 * CF7's default template wraps each tag as `<label> Your name [text* your-name]
 * </label>`, so the text between the opening `<label>` and the tag is the
 * question as the author wrote it. When the markup does not cooperate, the
 * field name is humanised instead — `your-name` reads as `Your name`.
 *
 * @since 0.2.0
 *
 * @param string $template The whole template.
 * @param int    $offset   Byte offset of the tag's `[`.
 * @param string $name     The CF7 field name, the fallback.
 * @return string
 */
function atf_cf7_label_for( $template, $offset, $name ) {
	$before = substr( $template, 0, $offset );

	if ( preg_match( '/<label[^>]*>\s*([^<>\[\]]*?)\s*$/s', $before, $found ) && '' !== trim( $found[1] ) ) {
		return trim( preg_replace( '/\s+/', ' ', $found[1] ) );
	}

	return ucfirst( str_replace( array( '-', '_' ), ' ', $name ) );
}

/**
 * The content between a tag and its closing pair, for content-carrying tags.
 *
 * @since 0.2.0
 *
 * @param string $template The whole template.
 * @param int    $offset   Byte offset just past the opening tag's `]`.
 * @param string $type     The tag type, e.g. `acceptance`.
 * @return string The trimmed content, or '' when there is no closing tag.
 */
function atf_cf7_tag_content( $template, $offset, $type ) {
	$closing = strpos( $template, '[/' . $type . ']', $offset );

	if ( false === $closing ) {
		return '';
	}

	return trim( preg_replace( '/\s+/', ' ', substr( $template, $offset, $closing - $offset ) ) );
}

/**
 * Converts one CF7 mail block into a notification.
 *
 * @since 0.2.0
 *
 * @param array  $mail The `_mail` or `_mail_2` settings.
 * @param array  $map  CF7 field name => new field id.
 * @param string $name The notification's display name.
 * @return array|null The notification, or null when the block is empty.
 */
function atf_cf7_convert_mail( $mail, $map, $name ) {
	$recipient = isset( $mail['recipient'] ) ? (string) $mail['recipient'] : '';
	$body      = isset( $mail['body'] ) ? (string) $mail['body'] : '';

	if ( '' === $recipient && '' === $body ) {
		return null;
	}

	// A plain-text CF7 body routinely reads `[your-name] <[your-email]>`, and
	// the message field is kses-filtered on save — which reads `<…>` as a bogus
	// tag and deletes the visitor's address outright. Encoding the brackets
	// keeps them visible. An HTML body is left alone: there the brackets *are*
	// markup, and kses is the right judge of it.
	if ( empty( $mail['use_html'] ) ) {
		$body = str_replace( array( '<', '>' ), array( '&lt;', '&gt;' ), $body );
	}

	$notification = array(
		'name'    => $name,
		'to'      => atf_cf7_replace_mail_tags( $recipient, $map ),
		'subject' => atf_cf7_replace_mail_tags( isset( $mail['subject'] ) ? (string) $mail['subject'] : '', $map ),
		'message' => atf_cf7_replace_mail_tags( $body, $map ),
	);

	// CF7's sender is one string — `Name <address>` — where this plugin keeps
	// the two halves apart.
	$sender = isset( $mail['sender'] ) ? (string) $mail['sender'] : '';

	if ( preg_match( '/^\s*(.*?)\s*<([^<>]+)>\s*$/', $sender, $parts ) ) {
		$notification['fromName']  = atf_cf7_replace_mail_tags( $parts[1], $map );
		$notification['fromEmail'] = atf_cf7_replace_mail_tags( $parts[2], $map );
	} elseif ( '' !== trim( $sender ) ) {
		$notification['fromEmail'] = atf_cf7_replace_mail_tags( trim( $sender ), $map );
	}

	$headers = isset( $mail['additional_headers'] ) ? (string) $mail['additional_headers'] : '';

	if ( preg_match( '/^\s*Reply-To:\s*(.+)$/mi', $headers, $reply ) ) {
		$notification['replyTo'] = atf_cf7_replace_mail_tags( trim( $reply[1] ), $map );
	}

	if ( ! empty( $mail['attachments'] ) ) {
		$notification['attachFiles'] = true;
	}

	return $notification;
}

/**
 * Rewrites CF7 mail-tags onto this plugin's merge tags.
 *
 * `[your-name]` becomes `{field:f1}`; the `[_site_*]` specials become their
 * equivalents. A tag with no equivalent is left as it stands rather than
 * silently deleted — visible in the notification editor, where the author can
 * see what did not translate and decide what it should say.
 *
 * @since 0.2.0
 *
 * @param string $text The mail template text.
 * @param array  $map  CF7 field name => new field id.
 * @return string
 */
function atf_cf7_replace_mail_tags( $text, $map ) {
	$specials = array(
		'[_site_title]'       => '{site}',
		'[_site_url]'         => '{site:url}',
		'[_site_admin_email]' => '{admin_email}',
		'[_date]'             => '{date}',
		'[_time]'             => '{time}',
		'[_remote_ip]'        => '{ip}',
		'[_url]'              => '{referrer}',
	);

	$text = strtr( (string) $text, $specials );

	return preg_replace_callback(
		'/\[([a-zA-Z][a-zA-Z0-9_-]*)\]/',
		static function ( $found ) use ( $map ) {
			return isset( $map[ $found[1] ] ) ? '{field:' . $map[ $found[1] ] . '}' : $found[0];
		},
		$text
	);
}

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
function alltfo_register_cf7_importer( $importers ) {
	$importers['contact-form-7'] = array(
		'label'          => __( 'Contact Form 7', 'allterrain-forms' ),
		'available'      => 'alltfo_cf7_available',
		'forms'          => 'alltfo_cf7_forms',
		'import'         => 'alltfo_cf7_import',
		'entries'        => 'alltfo_cf7_entry_count',
		'import_entries' => 'alltfo_cf7_import_entries',
	);

	return $importers;
}
add_filter( 'alltfo_importers', 'alltfo_register_cf7_importer' );

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
function alltfo_cf7_available() {
	return (bool) alltfo_cf7_forms();
}

/**
 * The CF7 forms on this site, id => title.
 *
 * @since 0.2.0
 *
 * @return array
 */
function alltfo_cf7_forms() {
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
function alltfo_cf7_import( $source_id ) {
	$post = get_post( absint( $source_id ) );

	if ( ! $post || 'wpcf7_contact_form' !== $post->post_type ) {
		return new WP_Error( 'alltfo_import_missing', __( 'That form no longer exists.', 'allterrain-forms' ) );
	}

	$template = (string) get_post_meta( $post->ID, '_form', true );

	$schema = alltfo_cf7_convert(
		$template,
		(array) get_post_meta( $post->ID, '_mail', true ),
		(array) get_post_meta( $post->ID, '_mail_2', true ),
		(array) get_post_meta( $post->ID, '_messages', true )
	);

	// Parsed a second time for its name => id map alone. The template is a few
	// hundred bytes and this happens once per form ever imported; thread the map
	// out of alltfo_cf7_convert() and every caller of it has to care about it.
	$parsed = alltfo_cf7_parse_template( $template );

	return alltfo_create_imported_form(
		$post->post_title,
		$schema,
		'contact-form-7',
		(string) $post->ID,
		$parsed['map']
	);
}

/**
 * The Flamingo channel term id a CF7 form's messages are filed under.
 *
 * CF7 caches it in the form's own `_flamingo` meta on the first submission; when
 * that is absent the term is looked up by slug, which is what CF7 named it after.
 *
 * @param WP_Post $form The CF7 form post.
 * @return int Term id, or 0 when the form has no channel.
 */
function alltfo_cf7_channel_id( $form ) {
	$meta = get_post_meta( $form->ID, '_flamingo', true );

	if ( is_array( $meta ) && ! empty( $meta['channel'] ) ) {
		return (int) $meta['channel'];
	}

	$term = get_term_by( 'slug', $form->post_name, 'flamingo_inbound_channel' );

	return $term && ! is_wp_error( $term ) ? (int) $term->term_id : 0;
}

/**
 * The post statuses a stored message worth importing can be in.
 *
 * Trash is excluded: a trashed message was thrown away on purpose, and a
 * migration that resurrected it would undo a decision somebody made.
 *
 * Note that `'any'` cannot be used against these records. Flamingo registers its
 * spam status with `exclude_from_search`, and `'any'` means "every status not
 * excluded from search" — so `'any'` silently omits every spam message.
 *
 * @return string[]
 */
function alltfo_cf7_message_statuses() {
	return array( 'publish', 'flamingo-spam' );
}

/**
 * How many stored submissions a CF7 form has that have not been imported yet.
 *
 * Read straight from the posts table rather than through Flamingo, for the same
 * reason the forms are: the moment somebody migrates is the moment the old
 * plugins are being switched off, and a deactivated plugin registers no post
 * type for `WP_Query` to find.
 *
 * @param string $source_id The CF7 post id.
 * @param int    $form_id   The AllTerrain form the messages would land on.
 * @return int
 */
function alltfo_cf7_entry_count( $source_id, $form_id = 0 ) {
	global $wpdb;

	$form = get_post( absint( $source_id ) );

	if ( ! $form || 'wpcf7_contact_form' !== $form->post_type ) {
		return 0;
	}

	$channel_id = alltfo_cf7_channel_id( $form );

	if ( ! $channel_id ) {
		return 0;
	}

	$statuses     = alltfo_cf7_message_statuses();
	$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated list of %s.
	$total = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				WHERE p.post_type = 'flamingo_inbound'
				AND p.post_status IN ( {$placeholders} )
				AND tt.taxonomy = 'flamingo_inbound_channel'
				AND tt.term_id = %d",
			array_merge( $statuses, array( $channel_id ) )
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( ! $form_id ) {
		return $total;
	}

	return max( 0, $total - count( alltfo_imported_entry_keys( (int) $form_id ) ) );
}

/**
 * Brings a slice of a CF7 form's stored submissions across as entries.
 *
 * Oldest first, so an interrupted migration leaves a contiguous history rather
 * than a scatter, and so a second pass resumes where the first stopped.
 *
 * @param string $source_id The CF7 post id.
 * @param int    $form_id   The AllTerrain form to import onto.
 * @param int    $limit     How many to attempt in this pass.
 * @return array|WP_Error { imported, skipped, done, remaining }.
 */
function alltfo_cf7_import_entries( $source_id, $form_id, $limit = 100 ) {
	global $wpdb;

	$form = get_post( absint( $source_id ) );

	if ( ! $form || 'wpcf7_contact_form' !== $form->post_type ) {
		return new WP_Error( 'alltfo_import_missing', __( 'That form no longer exists.', 'allterrain-forms' ) );
	}

	$channel_id = alltfo_cf7_channel_id( $form );

	if ( ! $channel_id ) {
		return array(
			'imported'  => 0,
			'skipped'   => 0,
			'done'      => true,
			'remaining' => 0,
		);
	}

	$seen         = alltfo_imported_entry_keys( (int) $form_id );
	$statuses     = alltfo_cf7_message_statuses();
	$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

	// Fetched a page at a time, and the page is larger than the limit because
	// records already imported are skipped without costing a slot -- otherwise a
	// second pass would spend its whole budget rediscovering the first pass.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated list of %s.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, p.post_status, p.post_date_gmt FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				WHERE p.post_type = 'flamingo_inbound'
				AND p.post_status IN ( {$placeholders} )
				AND tt.taxonomy = 'flamingo_inbound_channel'
				AND tt.term_id = %d
				ORDER BY p.post_date_gmt ASC, p.ID ASC
				LIMIT %d",
			array_merge( $statuses, array( $channel_id, count( $seen ) + (int) $limit ) )
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$imported = 0;
	$skipped  = 0;

	foreach ( (array) $rows as $row ) {
		if ( $imported >= (int) $limit ) {
			break;
		}

		if ( isset( $seen[ alltfo_entry_source_key( 'contact-form-7', $row->ID ) ] ) ) {
			++$skipped;
			continue;
		}

		$result = alltfo_import_entry(
			(int) $form_id,
			array(
				'values'       => alltfo_cf7_message_values( (int) $row->ID ),
				'importer'     => 'contact-form-7',
				'record'       => (string) $row->ID,
				'submitted_at' => (int) strtotime( $row->post_date_gmt . ' UTC' ),
				'spam'         => 'flamingo-spam' === $row->post_status,
				'ip'           => alltfo_cf7_message_meta( (int) $row->ID, 'remote_ip' ),
				'user_agent'   => alltfo_cf7_message_meta( (int) $row->ID, 'user_agent' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			// A single unreadable record must not strand the rest of the
			// migration, but an error that would repeat on every one of them --
			// a missing schema or field map -- is worth stopping for.
			if ( in_array( $result->get_error_code(), array( 'alltfo_no_schema', 'alltfo_no_import_map' ), true ) ) {
				return $result;
			}

			++$skipped;
			continue;
		}

		++$imported;
	}

	$remaining = alltfo_cf7_entry_count( $source_id, (int) $form_id );

	return array(
		'imported'  => $imported,
		'skipped'   => $skipped,
		'done'      => 0 === $remaining,
		'remaining' => $remaining,
	);
}

/**
 * The submitted values of one stored message, keyed by CF7 field name.
 *
 * Read from the per-field `_field_<name>` rows rather than the `_fields` array,
 * because Flamingo nulls every value inside `_fields` as it writes the per-field
 * rows — the array survives as a list of names with nothing in it.
 *
 * @param int $message_id The `flamingo_inbound` post id.
 * @return array Field name => value.
 */
function alltfo_cf7_message_values( $message_id ) {
	$values = array();

	foreach ( get_post_meta( $message_id ) as $key => $raw ) {
		if ( 0 !== strpos( $key, '_field_' ) ) {
			continue;
		}

		$values[ substr( $key, 7 ) ] = maybe_unserialize( $raw[0] );
	}

	return $values;
}

/**
 * One of the special mail tags CF7 stores alongside a message.
 *
 * @param int    $message_id The `flamingo_inbound` post id.
 * @param string $key        The tag name, e.g. `remote_ip`.
 * @return string
 */
function alltfo_cf7_message_meta( $message_id, $key ) {
	$meta = get_post_meta( $message_id, '_meta', true );

	return is_array( $meta ) && isset( $meta[ $key ] ) ? (string) $meta[ $key ] : '';
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
 * @return array A raw schema, ready for `alltfo_normalize_schema()`.
 */
function alltfo_cf7_convert( $template, $mail, $mail_2, $messages ) {
	$parsed = alltfo_cf7_parse_template( $template );

	$schema = array(
		'fields'        => $parsed['fields'],
		'notifications' => array(),
		'confirmations' => array(),
	);

	if ( '' !== $parsed['submit_label'] ) {
		$schema['settings'] = array( 'submitLabel' => $parsed['submit_label'] );
	}

	$notification = alltfo_cf7_convert_mail( $mail, $parsed['map'], __( 'Notification', 'allterrain-forms' ) );

	if ( $notification ) {
		$schema['notifications'][] = $notification;
	}

	// Mail (2) only counts when its own switch is on — CF7 stores the settings
	// either way, and importing a disabled autoresponder as a live one would
	// start e-mailing visitors nobody meant to e-mail.
	if ( ! empty( $mail_2['active'] ) ) {
		$second = alltfo_cf7_convert_mail( $mail_2, $parsed['map'], __( 'Mail (2)', 'allterrain-forms' ) );

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
function alltfo_cf7_parse_template( $template ) {
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
		$rest     = alltfo_cf7_parse_tag_body( isset( $match[3][0] ) ? $match[3][0] : '' );
		$offset   = $match[0][1];

		if ( 'submit' === $type ) {
			$submit_label = isset( $rest['values'][0] ) ? $rest['values'][0] : '';
			continue;
		}

		// A closing tag ([/acceptance]) or a tag with no name is not a field.
		if ( '' === $rest['name'] || '/' === $type[0] ) {
			continue;
		}

		$field = alltfo_cf7_tag_to_field( $type, $required, $rest, "f{$next}" );

		if ( ! $field ) {
			continue;
		}

		$field['label'] = alltfo_cf7_label_for( (string) $template, $offset, $rest['name'] );

		// Acceptance carries its condition as tag *content* —
		// `[acceptance name] I agree… [/acceptance]` — which reads better as
		// the consent line than as the label.
		if ( 'consent' === $field['type'] ) {
			$content = alltfo_cf7_tag_content( (string) $template, $offset + strlen( $match[0][0] ), 'acceptance' );

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
function alltfo_cf7_parse_tag_body( $body ) {
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
function alltfo_cf7_option( $options, $key ) {
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
function alltfo_cf7_tag_to_field( $type, $required, $rest, $id ) {
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
			$value = alltfo_cf7_option( $options, $bound );

			if ( '' !== $value ) {
				$field[ $bound ] = $value;
			}
		}

		if ( 'file' === $field['type'] ) {
			unset( $field['default'], $field['placeholder'] );

			$filetypes = alltfo_cf7_option( $options, 'filetypes' );

			if ( '' !== $filetypes ) {
				$field['filetypes'] = array_values( array_filter( array_map( 'trim', explode( '|', strtolower( $filetypes ) ) ) ) );
			}
		}

		return $field;
	}

	switch ( $type ) {
		case 'select':
			$field['type']    = in_array( 'multiple', $options, true ) ? 'multiselect' : 'select';
			$field['choices'] = alltfo_cf7_choices( $values );

			return $field;

		case 'checkbox':
			// An `exclusive` checkbox group allows exactly one answer, which
			// is a radio group wearing checkbox clothes.
			$field['type']    = in_array( 'exclusive', $options, true ) ? 'radio' : 'checkboxes';
			$field['choices'] = alltfo_cf7_choices( $values );

			return $field;

		case 'radio':
			$field['type']    = 'radio';
			$field['choices'] = alltfo_cf7_choices( $values );

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
function alltfo_cf7_choices( $values ) {
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
function alltfo_cf7_label_for( $template, $offset, $name ) {
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
function alltfo_cf7_tag_content( $template, $offset, $type ) {
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
function alltfo_cf7_convert_mail( $mail, $map, $name ) {
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
		'to'      => alltfo_cf7_replace_mail_tags( $recipient, $map ),
		'subject' => alltfo_cf7_replace_mail_tags( isset( $mail['subject'] ) ? (string) $mail['subject'] : '', $map ),
		'message' => alltfo_cf7_replace_mail_tags( $body, $map ),
	);

	// CF7's sender is one string — `Name <address>` — where this plugin keeps
	// the two halves apart.
	$sender = isset( $mail['sender'] ) ? (string) $mail['sender'] : '';

	if ( preg_match( '/^\s*(.*?)\s*<([^<>]+)>\s*$/', $sender, $parts ) ) {
		$notification['fromName']  = alltfo_cf7_replace_mail_tags( $parts[1], $map );
		$notification['fromEmail'] = alltfo_cf7_replace_mail_tags( $parts[2], $map );
	} elseif ( '' !== trim( $sender ) ) {
		$notification['fromEmail'] = alltfo_cf7_replace_mail_tags( trim( $sender ), $map );
	}

	$headers = isset( $mail['additional_headers'] ) ? (string) $mail['additional_headers'] : '';

	if ( preg_match( '/^\s*Reply-To:\s*(.+)$/mi', $headers, $reply ) ) {
		$notification['replyTo'] = alltfo_cf7_replace_mail_tags( trim( $reply[1] ), $map );
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
function alltfo_cf7_replace_mail_tags( $text, $map ) {
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

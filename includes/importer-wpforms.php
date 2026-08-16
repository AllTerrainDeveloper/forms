<?php
/**
 * Importer — WPForms.
 *
 * WPForms stores each form as a `wpforms` post whose `post_content` is one
 * JSON document: the fields keyed by numeric id, and the settings holding the
 * notifications and confirmations. All of it survives the plugin being
 * deactivated, so this importer decodes the post directly and never needs
 * WPForms' classes.
 *
 * The Lite/Pro line does not exist here: a Pro form's fields are in the same
 * JSON, so phone, address, date-time, file uploads, signatures, Likert grids,
 * NPS and the payment fields all convert — the payment choices keep their
 * prices, and a payment total becomes a real calculated total over them.
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
function atf_register_wpforms_importer( $importers ) {
	$importers['wpforms'] = array(
		'label'     => __( 'WPForms', 'allterrain-forms' ),
		'available' => 'atf_wpforms_available',
		'forms'     => 'atf_wpforms_forms',
		'import'    => 'atf_wpforms_import',
	);

	return $importers;
}
add_filter( 'atf_importers', 'atf_register_wpforms_importer' );

/**
 * Whether any WPForms forms exist on this site.
 *
 * @since 0.2.0
 *
 * @return bool
 */
function atf_wpforms_available() {
	return (bool) atf_wpforms_forms();
}

/**
 * The WPForms forms on this site, id => title.
 *
 * @since 0.2.0
 *
 * @return array
 */
function atf_wpforms_forms() {
	$posts = get_posts(
		array(
			'post_type'      => 'wpforms',
			'post_status'    => 'any',
			// A hard bound, not a page size — see the CF7 importer for why.
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
 * Imports one WPForms form.
 *
 * @since 0.2.0
 *
 * @param string $source_id The WPForms post id.
 * @return int|WP_Error The new form's id.
 */
function atf_wpforms_import( $source_id ) {
	$post = get_post( absint( $source_id ) );

	if ( ! $post || 'wpforms' !== $post->post_type ) {
		return new WP_Error( 'atf_import_missing', __( 'That form no longer exists.', 'allterrain-forms' ) );
	}

	$data = json_decode( (string) $post->post_content, true );

	if ( ! is_array( $data ) ) {
		return new WP_Error( 'atf_import_unreadable', __( 'That form could not be read.', 'allterrain-forms' ) );
	}

	$schema = atf_wpforms_convert( $data );
	$title  = '' !== $post->post_title
		? $post->post_title
		: ( isset( $data['settings']['form_title'] ) ? (string) $data['settings']['form_title'] : '' );

	return atf_create_imported_form( $title, $schema, 'wpforms', (string) $post->ID );
}

/**
 * Converts one decoded WPForms document into a schema.
 *
 * Pure — no database, no capability checks — so the conversion rules can be
 * tested against fixture JSON without inserting posts.
 *
 * @since 0.2.0
 *
 * @param array $data The decoded `post_content`.
 * @return array A raw schema, ready for `atf_normalize_schema()`.
 */
function atf_wpforms_convert( $data ) {
	$source_fields = isset( $data['fields'] ) && is_array( $data['fields'] ) ? $data['fields'] : array();
	$settings      = isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array();

	$fields   = array();
	$map      = array();
	$payments = array();
	$next     = 1;

	// First pass mints ids, so a field's conditional logic can reference a
	// field that appears later in the form.
	foreach ( $source_fields as $source ) {
		if ( is_array( $source ) && isset( $source['id'] ) ) {
			$map[ (string) $source['id'] ] = 'f' . ( $next + count( $map ) );
		}
	}

	foreach ( $source_fields as $source ) {
		if ( ! is_array( $source ) || ! isset( $source['id'] ) ) {
			continue;
		}

		$field = atf_wpforms_field( $source, $map[ (string) $source['id'] ], $map );

		if ( ! $field ) {
			unset( $map[ (string) $source['id'] ] );
			continue;
		}

		if ( 0 === strpos( (string) atf_wpforms_type( $source ), 'payment-' ) && 'total' !== $field['type'] ) {
			$payments[] = $field['id'];
		}

		// A payment total sums every payment field before it — that is what
		// the running total on the source form showed.
		if ( 'total' === $field['type'] && $payments ) {
			$field['formula'] = '{' . implode( '} + {', $payments ) . '}';
		}

		$fields[] = $field;
	}

	$schema = array(
		'fields'        => $fields,
		'settings'      => array(),
		'notifications' => array(),
		'confirmations' => array(),
	);

	if ( ! empty( $settings['submit_text'] ) ) {
		$schema['settings']['submitLabel'] = (string) $settings['submit_text'];
	}

	if ( ! empty( $settings['notification_enable'] ) && ! empty( $settings['notifications'] ) && is_array( $settings['notifications'] ) ) {
		foreach ( $settings['notifications'] as $notification ) {
			if ( ! is_array( $notification ) ) {
				continue;
			}

			$schema['notifications'][] = array(
				'name'      => isset( $notification['notification_name'] ) && '' !== $notification['notification_name']
					? (string) $notification['notification_name']
					: __( 'Notification', 'allterrain-forms' ),
				'to'        => atf_wpforms_replace_tags( isset( $notification['email'] ) ? (string) $notification['email'] : '', $map ),
				'subject'   => atf_wpforms_replace_tags( isset( $notification['subject'] ) ? (string) $notification['subject'] : '', $map ),
				'message'   => atf_wpforms_replace_tags( isset( $notification['message'] ) ? (string) $notification['message'] : '', $map ),
				'fromName'  => atf_wpforms_replace_tags( isset( $notification['sender_name'] ) ? (string) $notification['sender_name'] : '', $map ),
				'fromEmail' => atf_wpforms_replace_tags( isset( $notification['sender_address'] ) ? (string) $notification['sender_address'] : '', $map ),
				'replyTo'   => atf_wpforms_replace_tags( isset( $notification['replyto'] ) ? (string) $notification['replyto'] : '', $map ),
				'logic'     => atf_wpforms_logic( $notification, $map ),
			);
		}
	}

	if ( ! empty( $settings['confirmations'] ) && is_array( $settings['confirmations'] ) ) {
		foreach ( $settings['confirmations'] as $confirmation ) {
			if ( ! is_array( $confirmation ) ) {
				continue;
			}

			$type = isset( $confirmation['type'] ) ? (string) $confirmation['type'] : 'message';

			$schema['confirmations'][] = array(
				'name'    => isset( $confirmation['name'] ) && '' !== $confirmation['name']
					? (string) $confirmation['name']
					: __( 'Confirmation', 'allterrain-forms' ),
				'type'    => in_array( $type, array( 'message', 'redirect', 'page' ), true ) ? $type : 'message',
				'message' => atf_wpforms_replace_tags( isset( $confirmation['message'] ) ? (string) $confirmation['message'] : '', $map ),
				'url'     => isset( $confirmation['redirect'] ) ? (string) $confirmation['redirect'] : '',
				'pageId'  => isset( $confirmation['page'] ) ? absint( $confirmation['page'] ) : 0,
				'logic'   => atf_wpforms_logic( $confirmation, $map ),
			);
		}
	}

	return $schema;
}

/**
 * A WPForms field's type slug.
 *
 * @since 0.2.0
 *
 * @param array $source The source field.
 * @return string
 */
function atf_wpforms_type( $source ) {
	return isset( $source['type'] ) ? strtolower( (string) $source['type'] ) : '';
}

/**
 * Converts one WPForms field.
 *
 * @since 0.2.0
 *
 * @param array  $source The source field.
 * @param string $id     The id the new field will take.
 * @param array  $map    WPForms field id => new field id, for logic rules.
 * @return array|null The field, or null when the source has no equivalent.
 */
function atf_wpforms_field( $source, $id, $map ) {
	$type = atf_wpforms_type( $source );

	$simple = array(
		'text'               => 'text',
		'textarea'           => 'textarea',
		'email'              => 'email',
		'url'                => 'url',
		'phone'              => 'tel',
		'number'             => 'number',
		'number-slider'      => 'range',
		'hidden'             => 'hidden',
		'password'           => 'password',
		'rating'             => 'rating',
		'file-upload'        => 'file',
		'signature'          => 'signature',
		'pagebreak'          => 'page_break',
		'net_promoter_score' => 'scale',
		'payment-total'      => 'total',
		'gdpr-checkbox'      => 'consent',
		'payment-single'     => 'number',
		'color'              => 'color',
	);

	$choicey = array(
		'select'           => 'select',
		'radio'            => 'radio',
		'checkbox'         => 'checkboxes',
		'payment-select'   => 'select',
		'payment-multiple' => 'radio',
		'payment-checkbox' => 'checkboxes',
		'likert_scale'     => 'likert',
	);

	$field = array(
		'id'       => $id,
		'label'    => isset( $source['label'] ) ? (string) $source['label'] : '',
		'required' => ! empty( $source['required'] ),
		'logic'    => atf_wpforms_logic( $source, $map ),
	);

	if ( isset( $source['description'] ) && '' !== $source['description'] ) {
		$field['hint'] = (string) $source['description'];
	}

	if ( isset( $source['placeholder'] ) && '' !== $source['placeholder'] ) {
		$field['placeholder'] = (string) $source['placeholder'];
	}

	if ( isset( $source['default_value'] ) && is_scalar( $source['default_value'] ) && '' !== $source['default_value'] ) {
		$field['default'] = (string) $source['default_value'];
	}

	if ( isset( $simple[ $type ] ) ) {
		$field['type'] = $simple[ $type ];

		if ( 'number-slider' === $type ) {
			foreach ( array( 'min', 'max', 'step' ) as $bound ) {
				if ( isset( $source[ $bound ] ) && '' !== $source[ $bound ] ) {
					$field[ $bound ] = (string) $source[ $bound ];
				}
			}
		}

		if ( 'gdpr-checkbox' === $type ) {
			// The consent line is the single choice's label.
			$choice = atf_wpforms_choices( $source );

			if ( isset( $choice[0]['label'] ) && '' !== $choice[0]['label'] ) {
				$field['consentText'] = $choice[0]['label'];
			}

			// GDPR consent cannot be optional in WPForms, and must not become
			// optional by import.
			$field['required'] = true;
		}

		if ( 'file-upload' === $type && ! empty( $source['extensions'] ) ) {
			$field['filetypes'] = array_values(
				array_filter( array_map( 'trim', explode( ',', strtolower( (string) $source['extensions'] ) ) ) )
			);
		}

		if ( 'net_promoter_score' === $type ) {
			$field['min'] = '0';
			$field['max'] = '10';

			if ( ! empty( $source['lowest_label'] ) || ! empty( $source['highest_label'] ) ) {
				$field['endlabels'] = array(
					'low'  => isset( $source['lowest_label'] ) ? (string) $source['lowest_label'] : '',
					'high' => isset( $source['highest_label'] ) ? (string) $source['highest_label'] : '',
				);
			}
		}

		if ( 'rating' === $type && ! empty( $source['scale'] ) ) {
			$field['max'] = (string) absint( $source['scale'] );
		}

		return $field;
	}

	if ( isset( $choicey[ $type ] ) ) {
		$field['type']    = $choicey[ $type ];
		$field['choices'] = atf_wpforms_choices( $source );

		if ( 'select' === $type && ! empty( $source['multiple'] ) ) {
			$field['type'] = 'multiselect';
		}

		if ( 'likert_scale' === $type ) {
			$field['rows'] = atf_wpforms_likert_rows( $source );
		}

		return $field;
	}

	switch ( $type ) {
		case 'name':
			$format = isset( $source['format'] ) ? (string) $source['format'] : 'first-last';

			// A "simple" name is one box, which is a text field wearing a
			// name label — a composite with one part would render a sublabel
			// nobody wrote.
			if ( 'simple' === $format ) {
				$field['type'] = 'text';

				return $field;
			}

			$field['type']  = 'name';
			$field['parts'] = 'first-middle-last' === $format
				? array( 'first', 'middle', 'last' )
				: array( 'first', 'last' );

			return $field;

		case 'address':
			$field['type'] = 'address';

			return $field;

		case 'date-time':
			$format        = isset( $source['format'] ) ? (string) $source['format'] : 'date-time';
			$types         = array(
				'date' => 'date',
				'time' => 'time',
			);
			$field['type'] = isset( $types[ $format ] ) ? $types[ $format ] : 'datetime';

			return $field;

		case 'divider':
			// A WPForms section divider is a heading with an optional
			// description, not a horizontal rule.
			$field['type'] = 'heading';

			return $field;

		case 'html':
		case 'content':
			$field['type']    = 'html';
			$field['content'] = isset( $source['code'] ) ? (string) $source['code'] : ( isset( $source['content'] ) ? (string) $source['content'] : '' );

			return $field;

		case 'captcha':
		case 'entry-preview':
			return null;
	}

	// An unrecognised add-on field becomes a visible text field rather than
	// vanishing — reviewable in the builder, where dropped data would not be.
	$field['type'] = 'text';

	return $field;
}

/**
 * Converts a WPForms choices map.
 *
 * WPForms keys choices by number and, for payment fields, keeps the price in
 * `value`. The price survives as the choice's `price`, which is what lets an
 * imported payment total keep calculating.
 *
 * @since 0.2.0
 *
 * @param array $source The source field.
 * @return array[]
 */
function atf_wpforms_choices( $source ) {
	$raw     = isset( $source['choices'] ) && is_array( $source['choices'] ) ? $source['choices'] : array();
	$payment = 0 === strpos( atf_wpforms_type( $source ), 'payment-' );
	$choices = array();

	foreach ( $raw as $choice ) {
		if ( ! is_array( $choice ) ) {
			continue;
		}

		$label = isset( $choice['label'] ) ? (string) $choice['label'] : '';
		$value = isset( $choice['value'] ) ? (string) $choice['value'] : '';

		if ( '' === $label && '' === $value ) {
			continue;
		}

		$converted = array(
			'label' => '' !== $label ? $label : $value,
			// WPForms mostly submits the label itself; a non-payment `value`
			// is the rare "show different value" option.
			'value' => $payment || '' === $value ? ( '' !== $label ? $label : $value ) : $value,
		);

		if ( $payment && '' !== $value ) {
			$converted['price'] = (float) preg_replace( '/[^0-9.\-]/', '', $value );
		}

		if ( ! empty( $choice['default'] ) ) {
			$converted['selected'] = true;
		}

		$choices[] = $converted;
	}

	return $choices;
}

/**
 * Converts a Likert grid's rows.
 *
 * @since 0.2.0
 *
 * @param array $source The source field.
 * @return string[]
 */
function atf_wpforms_likert_rows( $source ) {
	$rows = array();

	if ( isset( $source['rows'] ) && is_array( $source['rows'] ) ) {
		foreach ( $source['rows'] as $row ) {
			if ( is_scalar( $row ) && '' !== (string) $row ) {
				$rows[] = (string) $row;
			}
		}
	}

	return $rows;
}

/**
 * Converts WPForms conditional logic.
 *
 * WPForms nests rules as groups-of-rules — groups OR together, rules inside a
 * group AND together. This plugin's logic is one flat group with a match mode,
 * so the two shapes that translate exactly are translated: one group becomes
 * `match: all`, and several single-rule groups become `match: any`. Anything
 * else keeps its first group, which narrows the condition rather than widening
 * it — a field that shows too rarely is visible in testing, a field that shows
 * to everybody is a leak.
 *
 * @since 0.2.0
 *
 * @param array $source The source field or setting block.
 * @param array $map    WPForms field id => new field id.
 * @return array The logic block, disabled when there was none.
 */
function atf_wpforms_logic( $source, $map ) {
	if ( empty( $source['conditional_logic'] ) || empty( $source['conditionals'] ) || ! is_array( $source['conditionals'] ) ) {
		return array( 'enabled' => false );
	}

	$operators = array(
		'==' => 'is',
		'!=' => 'is_not',
		'c'  => 'contains',
		'!c' => 'not_contains',
		'^'  => 'starts_with',
		'~'  => 'ends_with',
		'e'  => 'empty',
		'!e' => 'not_empty',
		'>'  => 'greater',
		'<'  => 'less',
	);

	$groups = array();

	foreach ( $source['conditionals'] as $group ) {
		if ( ! is_array( $group ) ) {
			continue;
		}

		$rules = array();

		foreach ( $group as $rule ) {
			if ( ! is_array( $rule ) || ! isset( $rule['field'] ) || ! isset( $map[ (string) $rule['field'] ] ) ) {
				continue;
			}

			$operator = isset( $rule['operator'] ) ? (string) $rule['operator'] : '==';

			$rules[] = array(
				'field'    => $map[ (string) $rule['field'] ],
				'operator' => isset( $operators[ $operator ] ) ? $operators[ $operator ] : 'is',
				'value'    => isset( $rule['value'] ) && is_scalar( $rule['value'] ) ? (string) $rule['value'] : '',
			);
		}

		if ( $rules ) {
			$groups[] = $rules;
		}
	}

	if ( ! $groups ) {
		return array( 'enabled' => false );
	}

	$action = isset( $source['conditional_type'] ) && 'hide' === $source['conditional_type'] ? 'hide' : 'show';

	if ( 1 === count( $groups ) ) {
		return array(
			'enabled' => true,
			'action'  => $action,
			'match'   => 'all',
			'rules'   => $groups[0],
		);
	}

	$singles = array_filter(
		$groups,
		static function ( $rules ) {
			return 1 === count( $rules );
		}
	);

	if ( count( $singles ) === count( $groups ) ) {
		return array(
			'enabled' => true,
			'action'  => $action,
			'match'   => 'any',
			'rules'   => array_merge( ...$groups ),
		);
	}

	return array(
		'enabled' => true,
		'action'  => $action,
		'match'   => 'all',
		'rules'   => $groups[0],
	);
}

/**
 * Rewrites WPForms smart tags onto this plugin's merge tags.
 *
 * `{field_id="3"}` becomes `{field:…}`; the specials that have equivalents are
 * translated; a tag with no equivalent is left as it stands rather than
 * silently deleted — visible in the editor, where the author can decide what
 * it should say.
 *
 * @since 0.2.0
 *
 * @param string $text The template text.
 * @param array  $map  WPForms field id => new field id.
 * @return string
 */
function atf_wpforms_replace_tags( $text, $map ) {
	$specials = array(
		'{all_fields}'                => '{all_fields}',
		'{admin_email}'               => '{admin_email}',
		'{site_name}'                 => '{site}',
		'{site_url}'                  => '{site:url}',
		'{page_url}'                  => '{referrer}',
		'{user_ip}'                   => '{ip}',
		'{date format="m/d/Y"}'       => '{date}',
		'{entry_date format="m/d/Y"}' => '{date}',
	);

	$text = strtr( (string) $text, $specials );

	return preg_replace_callback(
		'/\{field_id="(\d+)"\}|\{field_value_id="(\d+)"\}|\{field_html_id="(\d+)"\}/',
		static function ( $found ) use ( $map ) {
			$source_id = '';

			for ( $i = 1; $i <= 3; $i++ ) {
				if ( isset( $found[ $i ] ) && '' !== $found[ $i ] ) {
					$source_id = $found[ $i ];
					break;
				}
			}

			return isset( $map[ $source_id ] ) ? '{field:' . $map[ $source_id ] . '}' : $found[0];
		},
		$text
	);
}

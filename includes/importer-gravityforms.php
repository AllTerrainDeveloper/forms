<?php
/**
 * Importer — Gravity Forms.
 *
 * Gravity Forms keeps its forms in tables of its own: `{prefix}gf_form` for
 * the list, `{prefix}gf_form_meta` for the body — `display_meta` holds the
 * fields and settings as JSON, and the notifications and confirmations sit in
 * their own columns beside it. The tables outlive the plugin's deactivation,
 * so this importer reads them directly and never needs `GFAPI`.
 *
 * Two conversions carry more weight than the rest. Conditional logic maps rule
 * for rule — Gravity's flat rule list with an all/any mode is the same shape
 * this plugin uses. And a List field becomes a repeater: Gravity's columns are
 * the repeater's sub-fields, so "add another row" keeps meaning exactly that.
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
function atf_register_gravityforms_importer( $importers ) {
	$importers['gravityforms'] = array(
		'label'     => __( 'Gravity Forms', 'allterrain-forms' ),
		'available' => 'atf_gf_available',
		'forms'     => 'atf_gf_forms',
		'import'    => 'atf_gf_import',
	);

	return $importers;
}
add_filter( 'atf_importers', 'atf_register_gravityforms_importer' );

/**
 * Whether Gravity Forms' tables exist on this site.
 *
 * @since 0.2.0
 *
 * @return bool
 */
function atf_gf_available() {
	global $wpdb;

	$table = $wpdb->prefix . 'gf_form';

	// The table's existence is the availability test -- an empty table means
	// the section renders with "No forms found", which is more honest than
	// hiding the source somebody expected to see.
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- There is no API for another plugin's table, and a SHOW TABLES result is not worth caching.
}

/**
 * The Gravity Forms forms on this site, id => title.
 *
 * @since 0.2.0
 *
 * @return array
 */
function atf_gf_forms() {
	global $wpdb;

	if ( ! atf_gf_available() ) {
		return array();
	}

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Another plugin's table; read once per Import page view.
		"SELECT id, title FROM {$wpdb->prefix}gf_form WHERE is_trash = 0 ORDER BY title ASC LIMIT 200" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only the prefix is interpolated.
	);

	$forms = array();

	foreach ( (array) $rows as $row ) {
		$forms[ (string) $row->id ] = '' !== $row->title ? $row->title : __( '(untitled)', 'allterrain-forms' );
	}

	return $forms;
}

/**
 * Imports one Gravity Forms form.
 *
 * @since 0.2.0
 *
 * @param string $source_id The Gravity Forms form id.
 * @return int|WP_Error The new form's id.
 */
function atf_gf_import( $source_id ) {
	global $wpdb;

	$source_id = absint( $source_id );

	if ( ! $source_id || ! atf_gf_available() ) {
		return new WP_Error( 'atf_import_missing', __( 'That form no longer exists.', 'allterrain-forms' ) );
	}

	$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Another plugin's table; read once per import.
		$wpdb->prepare(
			"SELECT f.title, m.display_meta, m.notifications, m.confirmations
			 FROM {$wpdb->prefix}gf_form f
			 LEFT JOIN {$wpdb->prefix}gf_form_meta m ON m.form_id = f.id
			 WHERE f.id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only the prefix is interpolated.
			$source_id
		)
	);

	if ( ! $row ) {
		return new WP_Error( 'atf_import_missing', __( 'That form no longer exists.', 'allterrain-forms' ) );
	}

	$display = json_decode( (string) $row->display_meta, true );

	if ( ! is_array( $display ) ) {
		return new WP_Error( 'atf_import_unreadable', __( 'That form could not be read.', 'allterrain-forms' ) );
	}

	$schema = atf_gf_convert(
		$display,
		json_decode( (string) $row->notifications, true ),
		json_decode( (string) $row->confirmations, true )
	);

	$title = '' !== (string) $row->title ? (string) $row->title : ( isset( $display['title'] ) ? (string) $display['title'] : '' );

	return atf_create_imported_form( $title, $schema, 'gravityforms', (string) $source_id );
}

/**
 * Converts one Gravity Forms form into a schema.
 *
 * Pure — no database, no capability checks — so the conversion rules can be
 * tested against fixture arrays without creating tables.
 *
 * @since 0.2.0
 *
 * @param array      $display       The decoded `display_meta`.
 * @param array|null $notifications The decoded notifications column.
 * @param array|null $confirmations The decoded confirmations column.
 * @return array A raw schema, ready for `atf_normalize_schema()`.
 */
function atf_gf_convert( $display, $notifications = null, $confirmations = null ) {
	$source_fields = isset( $display['fields'] ) && is_array( $display['fields'] ) ? $display['fields'] : array();

	$fields   = array();
	$map      = array();
	$products = array();
	$next     = 1;

	// First pass mints ids, so conditional logic can reference a field that
	// appears later in the form.
	foreach ( $source_fields as $source ) {
		if ( is_array( $source ) && isset( $source['id'] ) ) {
			$map[ (string) $source['id'] ] = 'f' . $next;
			++$next;
		}
	}

	foreach ( $source_fields as $source ) {
		if ( ! is_array( $source ) || ! isset( $source['id'] ) ) {
			continue;
		}

		$field = atf_gf_field( $source, $map[ (string) $source['id'] ], $map );

		if ( ! $field ) {
			unset( $map[ (string) $source['id'] ] );
			continue;
		}

		$type = isset( $source['type'] ) ? strtolower( (string) $source['type'] ) : '';

		if ( in_array( $type, array( 'product', 'option' ), true ) && 'total' !== $field['type'] ) {
			$products[] = $field['id'];
		}

		// The total sums every product and option before it — the same number
		// Gravity's own running total showed.
		if ( 'total' === $field['type'] && $products ) {
			$field['formula'] = '{' . implode( '} + {', $products ) . '}';
		}

		$fields[] = $field;
	}

	$schema = array(
		'fields'        => $fields,
		'settings'      => array(),
		'notifications' => array(),
		'confirmations' => array(),
	);

	if ( isset( $display['button']['text'] ) && '' !== $display['button']['text'] ) {
		$schema['settings']['submitLabel'] = (string) $display['button']['text'];
	}

	foreach ( (array) $notifications as $notification ) {
		if ( ! is_array( $notification ) ) {
			continue;
		}

		// Admin notifications fire on submission. Anything wired to another
		// event — payment completed, entry approved — has no equivalent here
		// and importing it as a submission mail would send it at the wrong
		// moment entirely.
		if ( isset( $notification['event'] ) && 'form_submission' !== $notification['event'] ) {
			continue;
		}

		$to = isset( $notification['to'] ) ? (string) $notification['to'] : '';

		// `toType: field` stores a bare field id in `to`; as text it would
		// address the mail to the digit three.
		if ( isset( $notification['toType'] ) && 'field' === $notification['toType'] && isset( $map[ $to ] ) ) {
			$to = '{field:' . $map[ $to ] . '}';
		} else {
			$to = atf_gf_replace_tags( $to, $map );
		}

		$schema['notifications'][] = array(
			'name'     => isset( $notification['name'] ) && '' !== $notification['name'] ? (string) $notification['name'] : __( 'Notification', 'allterrain-forms' ),
			'enabled'  => empty( $notification['isActive'] ) && isset( $notification['isActive'] ) ? false : true,
			'to'       => $to,
			'subject'  => atf_gf_replace_tags( isset( $notification['subject'] ) ? (string) $notification['subject'] : '', $map ),
			'message'  => atf_gf_replace_tags( isset( $notification['message'] ) ? (string) $notification['message'] : '', $map ),
			'fromName' => atf_gf_replace_tags( isset( $notification['fromName'] ) ? (string) $notification['fromName'] : '', $map ),
			'replyTo'  => atf_gf_replace_tags( isset( $notification['replyTo'] ) ? (string) $notification['replyTo'] : '', $map ),
			'logic'    => atf_gf_logic( isset( $notification['conditionalLogic'] ) ? $notification['conditionalLogic'] : null, $map ),
		);
	}

	foreach ( (array) $confirmations as $confirmation ) {
		if ( ! is_array( $confirmation ) ) {
			continue;
		}

		$type = isset( $confirmation['type'] ) ? (string) $confirmation['type'] : 'message';

		$schema['confirmations'][] = array(
			'name'    => isset( $confirmation['name'] ) && '' !== $confirmation['name'] ? (string) $confirmation['name'] : __( 'Confirmation', 'allterrain-forms' ),
			'type'    => in_array( $type, array( 'message', 'redirect', 'page' ), true ) ? $type : 'message',
			'message' => atf_gf_replace_tags( isset( $confirmation['message'] ) ? (string) $confirmation['message'] : '', $map ),
			'url'     => isset( $confirmation['url'] ) ? (string) $confirmation['url'] : '',
			'pageId'  => isset( $confirmation['pageId'] ) ? absint( $confirmation['pageId'] ) : 0,
			'query'   => atf_gf_replace_tags( isset( $confirmation['queryString'] ) ? (string) $confirmation['queryString'] : '', $map ),
			'logic'   => atf_gf_logic( isset( $confirmation['conditionalLogic'] ) ? $confirmation['conditionalLogic'] : null, $map ),
		);
	}

	return $schema;
}

/**
 * Converts one Gravity Forms field.
 *
 * @since 0.2.0
 *
 * @param array  $source The source field.
 * @param string $id     The id the new field will take.
 * @param array  $map    Gravity field id => new field id, for logic rules.
 * @return array|null The field, or null when the source has no equivalent.
 */
function atf_gf_field( $source, $id, $map ) {
	$type = isset( $source['type'] ) ? strtolower( (string) $source['type'] ) : '';

	// A post field is its input type wearing a publishing hat; the answer
	// shape is what matters here.
	$post_types = array(
		'post_title'   => 'text',
		'post_content' => 'textarea',
		'post_excerpt' => 'textarea',
	);

	if ( isset( $post_types[ $type ] ) ) {
		$type = $post_types[ $type ];
	}

	$simple = array(
		'text'       => 'text',
		'textarea'   => 'textarea',
		'email'      => 'email',
		'phone'      => 'tel',
		'website'    => 'url',
		'number'     => 'number',
		'hidden'     => 'hidden',
		'date'       => 'date',
		'time'       => 'time',
		'fileupload' => 'file',
		'signature'  => 'signature',
		'page'       => 'page_break',
		'section'    => 'heading',
		'html'       => 'html',
		'total'      => 'total',
		'quantity'   => 'number',
	);

	$field = array(
		'id'       => $id,
		'label'    => isset( $source['label'] ) ? (string) $source['label'] : '',
		'required' => ! empty( $source['isRequired'] ),
		'logic'    => atf_gf_logic( isset( $source['conditionalLogic'] ) ? $source['conditionalLogic'] : null, $map ),
	);

	if ( isset( $source['description'] ) && '' !== $source['description'] ) {
		$field['hint'] = (string) $source['description'];
	}

	if ( isset( $source['placeholder'] ) && '' !== $source['placeholder'] ) {
		$field['placeholder'] = (string) $source['placeholder'];
	}

	if ( isset( $source['defaultValue'] ) && is_scalar( $source['defaultValue'] ) && '' !== $source['defaultValue'] ) {
		$field['default'] = (string) $source['defaultValue'];
	}

	if ( isset( $simple[ $type ] ) ) {
		$field['type'] = $simple[ $type ];

		if ( 'number' === $type ) {
			if ( isset( $source['rangeMin'] ) && '' !== $source['rangeMin'] ) {
				$field['min'] = (string) $source['rangeMin'];
			}

			if ( isset( $source['rangeMax'] ) && '' !== $source['rangeMax'] ) {
				$field['max'] = (string) $source['rangeMax'];
			}
		}

		if ( 'html' === $type ) {
			$field['content'] = isset( $source['content'] ) ? (string) $source['content'] : '';
		}

		if ( 'fileupload' === $type && ! empty( $source['allowedExtensions'] ) ) {
			$field['filetypes'] = array_values(
				array_filter( array_map( 'trim', explode( ',', strtolower( (string) $source['allowedExtensions'] ) ) ) )
			);
		}

		return $field;
	}

	switch ( $type ) {
		case 'select':
		case 'multiselect':
		case 'radio':
		case 'checkbox':
			$types         = array(
				'select'      => 'select',
				'multiselect' => 'multiselect',
				'radio'       => 'radio',
				'checkbox'    => 'checkboxes',
			);
			$field['type'] = $types[ $type ];

			$converted = atf_gf_choices( $source );

			$field['choices'] = $converted['choices'];

			if ( $converted['other'] ) {
				$field['other'] = true;
			}

			return $field;

		case 'name':
			$field['type']  = 'name';
			$field['parts'] = atf_gf_name_parts( $source );

			return $field;

		case 'address':
			$field['type'] = 'address';

			return $field;

		case 'consent':
			$field['type'] = 'consent';

			if ( isset( $source['checkboxLabel'] ) && '' !== $source['checkboxLabel'] ) {
				$field['consentText'] = (string) $source['checkboxLabel'];
			}

			return $field;

		case 'list':
			// Gravity's List is rows the visitor adds — a repeater. Its
			// columns are the sub-fields; a single-column list becomes one
			// sub-field wearing the list's own label.
			$field['type'] = 'repeater';

			$columns = array();

			if ( ! empty( $source['enableColumns'] ) && isset( $source['choices'] ) && is_array( $source['choices'] ) ) {
				foreach ( $source['choices'] as $column ) {
					if ( is_array( $column ) && isset( $column['text'] ) && '' !== $column['text'] ) {
						$columns[] = (string) $column['text'];
					}
				}
			}

			if ( ! $columns ) {
				$columns = array( '' !== $field['label'] ? $field['label'] : __( 'Item', 'allterrain-forms' ) );
			}

			$subs = array();

			foreach ( $columns as $index => $column ) {
				$subs[] = array(
					'id'    => 's' . ( $index + 1 ),
					'type'  => 'text',
					'label' => $column,
				);
			}

			$field['fields'] = $subs;

			return $field;

		case 'product':
			$input_type = isset( $source['inputType'] ) ? strtolower( (string) $source['inputType'] ) : 'singleproduct';

			if ( in_array( $input_type, array( 'select', 'radio' ), true ) ) {
				$field['type']    = 'select' === $input_type ? 'select' : 'radio';
				$field['choices'] = atf_gf_choices( $source )['choices'];

				return $field;
			}

			// A fixed-price product is a number holding its price, so the
			// imported total can still sum it. The visitor-facing behaviour
			// differs from Gravity's quantity pairing — that is the honest
			// limit of the mapping, reviewable in the builder.
			$field['type'] = 'number';

			if ( isset( $source['basePrice'] ) && '' !== $source['basePrice'] ) {
				$field['default'] = (string) atf_gf_price( $source['basePrice'] );
			}

			return $field;

		case 'option':
			$field['type']    = 'checkboxes';
			$field['choices'] = atf_gf_choices( $source )['choices'];

			return $field;

		case 'captcha':
		case 'honeypot':
			return null;
	}

	// An unrecognised add-on field becomes a visible text field rather than
	// vanishing — reviewable in the builder, where dropped data would not be.
	$field['type'] = 'text';

	return $field;
}

/**
 * Converts a Gravity choices list.
 *
 * @since 0.2.0
 *
 * @param array $source The source field.
 * @return array { choices: array[], other: bool }
 */
function atf_gf_choices( $source ) {
	$raw     = isset( $source['choices'] ) && is_array( $source['choices'] ) ? $source['choices'] : array();
	$priced  = in_array( strtolower( isset( $source['type'] ) ? (string) $source['type'] : '' ), array( 'product', 'option' ), true )
		|| ! empty( $source['enablePrice'] );
	$choices = array();

	foreach ( $raw as $choice ) {
		if ( ! is_array( $choice ) ) {
			continue;
		}

		$label = isset( $choice['text'] ) ? (string) $choice['text'] : '';
		$value = isset( $choice['value'] ) ? (string) $choice['value'] : '';

		if ( '' === $label && '' === $value ) {
			continue;
		}

		$converted = array(
			'label' => '' !== $label ? $label : $value,
			'value' => '' !== $value ? $value : $label,
		);

		if ( $priced && isset( $choice['price'] ) && '' !== $choice['price'] ) {
			$converted['price'] = atf_gf_price( $choice['price'] );
		}

		if ( ! empty( $choice['isSelected'] ) ) {
			$converted['selected'] = true;
		}

		$choices[] = $converted;
	}

	return array(
		'choices' => $choices,
		'other'   => ! empty( $source['enableOtherChoice'] ),
	);
}

/**
 * A Gravity price string as a number.
 *
 * Gravity stores prices with their currency formatting — `$25.00`, `25,00 €` —
 * and a formula can only add numbers.
 *
 * @since 0.2.0
 *
 * @param mixed $price The stored price.
 * @return float
 */
function atf_gf_price( $price ) {
	$price = preg_replace( '/[^0-9.,\-]/', '', (string) $price );

	// A comma-decimal price with no dot reads as European formatting.
	if ( false !== strpos( $price, ',' ) && false === strpos( $price, '.' ) ) {
		$price = str_replace( ',', '.', $price );
	} else {
		$price = str_replace( ',', '', $price );
	}

	return (float) $price;
}

/**
 * The name parts a Gravity name field shows.
 *
 * @since 0.2.0
 *
 * @param array $source The source field.
 * @return string[]
 */
function atf_gf_name_parts( $source ) {
	$known = array(
		'2' => 'prefix',
		'3' => 'first',
		'4' => 'middle',
		'6' => 'last',
		'8' => 'suffix',
	);

	$parts = array();

	if ( isset( $source['inputs'] ) && is_array( $source['inputs'] ) ) {
		foreach ( $source['inputs'] as $input ) {
			if ( ! is_array( $input ) || ! empty( $input['isHidden'] ) || ! isset( $input['id'] ) ) {
				continue;
			}

			// Gravity numbers name inputs `{field}.{part}` — 3 is first, 6 is
			// last — and that sub-number is stable across every install.
			$sub = substr( (string) $input['id'], strpos( (string) $input['id'], '.' ) + 1 );

			if ( isset( $known[ $sub ] ) ) {
				$parts[] = $known[ $sub ];
			}
		}
	}

	return $parts ? $parts : array( 'first', 'last' );
}

/**
 * Converts Gravity conditional logic.
 *
 * The one importer where this is a straight translation: Gravity's logic is a
 * flat rule list with an all/any mode and a show/hide action — the same shape
 * this plugin evaluates.
 *
 * @since 0.2.0
 *
 * @param mixed $logic The `conditionalLogic` block.
 * @param array $map   Gravity field id => new field id.
 * @return array The logic block, disabled when there was none.
 */
function atf_gf_logic( $logic, $map ) {
	if ( ! is_array( $logic ) || empty( $logic['rules'] ) || ! is_array( $logic['rules'] ) ) {
		return array( 'enabled' => false );
	}

	$operators = array(
		'is'          => 'is',
		'isnot'       => 'is_not',
		'contains'    => 'contains',
		'starts_with' => 'starts_with',
		'ends_with'   => 'ends_with',
		'>'           => 'greater',
		'<'           => 'less',
	);

	$rules = array();

	foreach ( $logic['rules'] as $rule ) {
		if ( ! is_array( $rule ) || ! isset( $rule['fieldId'] ) ) {
			continue;
		}

		// A rule against a composite input (`1.3`) is a rule against the
		// composite field.
		$field_id = (string) $rule['fieldId'];
		$field_id = false !== strpos( $field_id, '.' ) ? substr( $field_id, 0, strpos( $field_id, '.' ) ) : $field_id;

		if ( ! isset( $map[ $field_id ] ) ) {
			continue;
		}

		$operator = isset( $rule['operator'] ) ? strtolower( (string) $rule['operator'] ) : 'is';

		$rules[] = array(
			'field'    => $map[ $field_id ],
			'operator' => isset( $operators[ $operator ] ) ? $operators[ $operator ] : 'is',
			'value'    => isset( $rule['value'] ) && is_scalar( $rule['value'] ) ? (string) $rule['value'] : '',
		);
	}

	if ( ! $rules ) {
		return array( 'enabled' => false );
	}

	return array(
		'enabled' => true,
		'action'  => isset( $logic['actionType'] ) && 'hide' === $logic['actionType'] ? 'hide' : 'show',
		'match'   => isset( $logic['logicType'] ) && 'any' === $logic['logicType'] ? 'any' : 'all',
		'rules'   => $rules,
	);
}

/**
 * Rewrites Gravity merge tags onto this plugin's.
 *
 * `{Label:3}` becomes `{field:…}` — the label half is display sugar, the
 * number is the reference. Specials with equivalents are translated; a tag
 * with no equivalent is left visible rather than silently deleted.
 *
 * @since 0.2.0
 *
 * @param string $text The template text.
 * @param array  $map  Gravity field id => new field id.
 * @return string
 */
function atf_gf_replace_tags( $text, $map ) {
	$specials = array(
		'{all_fields}'  => '{all_fields}',
		'{admin_email}' => '{admin_email}',
		'{form_title}'  => '{form}',
		'{ip}'          => '{ip}',
		'{embed_url}'   => '{referrer}',
		'{date_mdy}'    => '{date}',
		'{date_dmy}'    => '{date}',
	);

	$text = strtr( (string) $text, $specials );

	return preg_replace_callback(
		'/\{[^{}:]*:(\d+)(?:\.\d+)?(?::[^{}]*)?\}/',
		static function ( $found ) use ( $map ) {
			return isset( $map[ $found[1] ] ) ? '{field:' . $map[ $found[1] ] . '}' : $found[0];
		},
		$text
	);
}

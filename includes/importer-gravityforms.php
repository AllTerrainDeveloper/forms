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
function alltfo_register_gravityforms_importer( $importers ) {
	$importers['gravityforms'] = array(
		'label'          => __( 'Gravity Forms', 'allterrain-forms' ),
		'available'      => 'alltfo_gf_available',
		'forms'          => 'alltfo_gf_forms',
		'import'         => 'alltfo_gf_import',
		'entries'        => 'alltfo_gf_entry_count',
		'import_entries' => 'alltfo_gf_import_entries',
	);

	return $importers;
}
add_filter( 'alltfo_importers', 'alltfo_register_gravityforms_importer' );

/**
 * Whether Gravity Forms' tables exist on this site.
 *
 * @since 0.2.0
 *
 * @return bool
 */
function alltfo_gf_available() {
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
function alltfo_gf_forms() {
	global $wpdb;

	if ( ! alltfo_gf_available() ) {
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
function alltfo_gf_import( $source_id ) {
	global $wpdb;

	$source_id = absint( $source_id );

	if ( ! $source_id || ! alltfo_gf_available() ) {
		return new WP_Error( 'alltfo_import_missing', __( 'That form no longer exists.', 'allterrain-forms' ) );
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
		return new WP_Error( 'alltfo_import_missing', __( 'That form no longer exists.', 'allterrain-forms' ) );
	}

	$display = json_decode( (string) $row->display_meta, true );

	if ( ! is_array( $display ) ) {
		return new WP_Error( 'alltfo_import_unreadable', __( 'That form could not be read.', 'allterrain-forms' ) );
	}

	$schema = alltfo_gf_convert(
		$display,
		json_decode( (string) $row->notifications, true ),
		json_decode( (string) $row->confirmations, true )
	);

	$title = '' !== (string) $row->title ? (string) $row->title : ( isset( $display['title'] ) ? (string) $display['title'] : '' );

	return alltfo_create_imported_form( $title, $schema, 'gravityforms', (string) $source_id, alltfo_gf_map( $display ) );
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
 * @return array A raw schema, ready for `alltfo_normalize_schema()`.
 */
function alltfo_gf_convert( $display, $notifications = null, $confirmations = null ) {
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

		$field = alltfo_gf_field( $source, $map[ (string) $source['id'] ], $map );

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
			$to = alltfo_gf_replace_tags( $to, $map );
		}

		$schema['notifications'][] = array(
			'name'     => isset( $notification['name'] ) && '' !== $notification['name'] ? (string) $notification['name'] : __( 'Notification', 'allterrain-forms' ),
			'enabled'  => empty( $notification['isActive'] ) && isset( $notification['isActive'] ) ? false : true,
			'to'       => $to,
			'subject'  => alltfo_gf_replace_tags( isset( $notification['subject'] ) ? (string) $notification['subject'] : '', $map ),
			'message'  => alltfo_gf_replace_tags( isset( $notification['message'] ) ? (string) $notification['message'] : '', $map ),
			'fromName' => alltfo_gf_replace_tags( isset( $notification['fromName'] ) ? (string) $notification['fromName'] : '', $map ),
			'replyTo'  => alltfo_gf_replace_tags( isset( $notification['replyTo'] ) ? (string) $notification['replyTo'] : '', $map ),
			'logic'    => alltfo_gf_logic( isset( $notification['conditionalLogic'] ) ? $notification['conditionalLogic'] : null, $map ),
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
			'message' => alltfo_gf_replace_tags( isset( $confirmation['message'] ) ? (string) $confirmation['message'] : '', $map ),
			'url'     => isset( $confirmation['url'] ) ? (string) $confirmation['url'] : '',
			'pageId'  => isset( $confirmation['pageId'] ) ? absint( $confirmation['pageId'] ) : 0,
			'query'   => alltfo_gf_replace_tags( isset( $confirmation['queryString'] ) ? (string) $confirmation['queryString'] : '', $map ),
			'logic'   => alltfo_gf_logic( isset( $confirmation['conditionalLogic'] ) ? $confirmation['conditionalLogic'] : null, $map ),
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
function alltfo_gf_field( $source, $id, $map ) {
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
		'logic'    => alltfo_gf_logic( isset( $source['conditionalLogic'] ) ? $source['conditionalLogic'] : null, $map ),
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

			$converted = alltfo_gf_choices( $source );

			$field['choices'] = $converted['choices'];

			if ( $converted['other'] ) {
				$field['other'] = true;
			}

			return $field;

		case 'name':
			$field['type']  = 'name';
			$field['parts'] = alltfo_gf_name_parts( $source );

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
				$field['choices'] = alltfo_gf_choices( $source )['choices'];

				return $field;
			}

			// A fixed-price product is a number holding its price, so the
			// imported total can still sum it. The visitor-facing behaviour
			// differs from Gravity's quantity pairing — that is the honest
			// limit of the mapping, reviewable in the builder.
			$field['type'] = 'number';

			if ( isset( $source['basePrice'] ) && '' !== $source['basePrice'] ) {
				$field['default'] = (string) alltfo_gf_price( $source['basePrice'] );
			}

			return $field;

		case 'option':
			$field['type']    = 'checkboxes';
			$field['choices'] = alltfo_gf_choices( $source )['choices'];

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
function alltfo_gf_choices( $source ) {
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
			$converted['price'] = alltfo_gf_price( $choice['price'] );
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
function alltfo_gf_price( $price ) {
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
function alltfo_gf_name_parts( $source ) {
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
function alltfo_gf_logic( $logic, $map ) {
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
function alltfo_gf_replace_tags( $text, $map ) {
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

/**
 * The Gravity field id => new field id map for one form, recomputed.
 *
 * The same two passes `alltfo_gf_convert()` runs, minus building the schema —
 * minting has to happen for every field before dropping any, or the ids would
 * shift and stop matching the ones the conversion minted. Recomputed rather
 * than threaded out of the converter for the same reason the CF7 importer
 * re-parses its template: the source is a few kilobytes read once per form
 * ever imported, and every caller of the converter stays oblivious.
 *
 * @since 0.3.0
 *
 * @param array $display The decoded `display_meta`.
 * @return array Gravity field id => new field id.
 */
function alltfo_gf_map( $display ) {
	$source_fields = isset( $display['fields'] ) && is_array( $display['fields'] ) ? $display['fields'] : array();

	$map  = array();
	$next = 1;

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

		if ( ! alltfo_gf_field( $source, $map[ (string) $source['id'] ], $map ) ) {
			unset( $map[ (string) $source['id'] ] );
		}
	}

	return $map;
}

/**
 * Whether Gravity Forms' entry tables exist on this site.
 *
 * Checked separately from `alltfo_gf_available()` because the two can differ: a
 * site restored from a partial backup, or one that ran a very old Gravity
 * Forms, can hold the form tables without the entry tables.
 *
 * @since 0.3.0
 *
 * @return bool
 */
function alltfo_gf_entries_available() {
	global $wpdb;

	$table = $wpdb->prefix . 'gf_entry';

	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- There is no API for another plugin's table, and a SHOW TABLES result is not worth caching.
}

/**
 * The statuses a Gravity entry worth importing can be in.
 *
 * Trash is excluded here as everywhere in this migration: a trashed entry was
 * thrown away on purpose, and resurrecting it would undo a decision somebody
 * made.
 *
 * @since 0.3.0
 *
 * @return string[]
 */
function alltfo_gf_entry_statuses() {
	return array( 'active', 'spam' );
}

/**
 * How many stored Gravity entries a form has that have not been imported yet.
 *
 * @since 0.3.0
 *
 * @param string $source_id The Gravity form id.
 * @param int    $form_id   The AllTerrain form the entries would land on.
 * @return int
 */
function alltfo_gf_entry_count( $source_id, $form_id = 0 ) {
	global $wpdb;

	$source_id = absint( $source_id );

	if ( ! $source_id || ! alltfo_gf_entries_available() ) {
		return 0;
	}

	$statuses     = alltfo_gf_entry_statuses();
	$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Another plugin's table; $placeholders is a generated list of %s.
	$total = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}gf_entry WHERE form_id = %d AND status IN ( {$placeholders} )",
			array_merge( array( $source_id ), $statuses )
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( ! $form_id ) {
		return $total;
	}

	return max( 0, $total - count( alltfo_imported_entry_keys( (int) $form_id ) ) );
}

/**
 * Brings a slice of a Gravity form's stored entries across.
 *
 * Oldest first, so an interrupted migration leaves a contiguous history and a
 * second pass resumes where the first stopped.
 *
 * @since 0.3.0
 *
 * @param string $source_id The Gravity form id.
 * @param int    $form_id   The AllTerrain form to import onto.
 * @param int    $limit     How many to attempt in this pass.
 * @return array|WP_Error { imported, skipped, done, remaining }.
 */
function alltfo_gf_import_entries( $source_id, $form_id, $limit = 100 ) {
	global $wpdb;

	$source_id = absint( $source_id );
	$form_id   = (int) $form_id;

	if ( ! $source_id || ! alltfo_gf_available() ) {
		return new WP_Error( 'alltfo_import_missing', __( 'That form no longer exists.', 'allterrain-forms' ) );
	}

	if ( ! alltfo_gf_entries_available() ) {
		return array(
			'imported'  => 0,
			'skipped'   => 0,
			'done'      => true,
			'remaining' => 0,
		);
	}

	$fields = alltfo_gf_entry_fields( $form_id );

	if ( is_wp_error( $fields ) ) {
		return $fields;
	}

	$seen         = alltfo_imported_entry_keys( $form_id );
	$statuses     = alltfo_gf_entry_statuses();
	$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

	// The page is larger than the limit because records already imported are
	// skipped without costing a slot -- otherwise a second pass would spend its
	// whole budget rediscovering the first pass.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Another plugin's table; $placeholders is a generated list of %s.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, status, date_created, ip, user_agent FROM {$wpdb->prefix}gf_entry
				WHERE form_id = %d AND status IN ( {$placeholders} )
				ORDER BY date_created ASC, id ASC
				LIMIT %d",
			array_merge( array( $source_id ), $statuses, array( count( $seen ) + (int) $limit ) )
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$imported = 0;
	$skipped  = 0;

	foreach ( (array) $rows as $row ) {
		if ( $imported >= (int) $limit ) {
			break;
		}

		if ( isset( $seen[ alltfo_entry_source_key( 'gravityforms', $row->id ) ] ) ) {
			++$skipped;
			continue;
		}

		$result = alltfo_import_entry(
			$form_id,
			array(
				'values'       => alltfo_gf_entry_values( (int) $row->id, $fields ),
				'importer'     => 'gravityforms',
				'record'       => (string) $row->id,
				'submitted_at' => (int) strtotime( $row->date_created . ' UTC' ),
				'spam'         => 'spam' === $row->status,
				'ip'           => (string) $row->ip,
				'user_agent'   => (string) $row->user_agent,
			)
		);

		if ( is_wp_error( $result ) ) {
			// A single unreadable record must not strand the rest of the
			// migration, but an error that would repeat on every one of them
			// is worth stopping for.
			if ( in_array( $result->get_error_code(), array( 'alltfo_no_schema', 'alltfo_no_import_map' ), true ) ) {
				return $result;
			}

			++$skipped;
			continue;
		}

		++$imported;
	}

	$remaining = alltfo_gf_entry_count( (string) $source_id, $form_id );

	return array(
		'imported'  => $imported,
		'skipped'   => $skipped,
		'done'      => 0 === $remaining,
		'remaining' => $remaining,
	);
}

/**
 * Gravity field id => the imported field it became, for one AllTerrain form.
 *
 * The entry reader needs the *target* field's type to know what shape to
 * assemble — the same dotted meta keys hold a name's parts on one field and a
 * checkbox group's picks on another, and only the destination can say which.
 *
 * @since 0.3.0
 *
 * @param int $form_id The AllTerrain form.
 * @return array|WP_Error Gravity field id => normalised field.
 */
function alltfo_gf_entry_fields( $form_id ) {
	$schema = alltfo_get_form_schema( $form_id );

	if ( ! $schema ) {
		return new WP_Error( 'alltfo_no_schema', __( 'That form has no schema.', 'allterrain-forms' ) );
	}

	$map = alltfo_form_import_map( $form_id );

	if ( ! $map ) {
		return new WP_Error(
			'alltfo_no_import_map',
			__( 'That form has no field map, so its stored submissions cannot be read. Import the form again to record one.', 'allterrain-forms' )
		);
	}

	$by_id = array();

	foreach ( alltfo_input_fields( $schema ) as $field ) {
		$by_id[ $field['id'] ] = $field;
	}

	$fields = array();

	foreach ( $map as $source_id => $field_id ) {
		if ( isset( $by_id[ $field_id ] ) ) {
			$fields[ (string) $source_id ] = $by_id[ $field_id ];
		}
	}

	return $fields;
}

/**
 * The submitted values of one Gravity entry, keyed by Gravity field id.
 *
 * Gravity keys its entry meta by *input* id: `5` for a single-input field, and
 * `5.3`, `5.6` for the inputs of a multi-input one — where the same dotted
 * shape means name parts on a name field, address lines on an address field
 * and one row per ticked box on a checkbox group. The target field's type is
 * what disambiguates, which is why the map of converted fields rides along.
 *
 * @since 0.3.0
 *
 * @param int   $entry_id The Gravity entry id.
 * @param array $fields   Gravity field id => the imported field it became.
 * @return array Gravity field id => value, shaped for the target field.
 */
function alltfo_gf_entry_values( $entry_id, $fields ) {
	global $wpdb;

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Another plugin's table; read once per imported entry.
		$wpdb->prepare(
			"SELECT meta_key, meta_value FROM {$wpdb->prefix}gf_entry_meta WHERE entry_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only the prefix is interpolated.
			absint( $entry_id )
		)
	);

	// One bucket per Gravity field: `single` for an undotted key, `subs` for
	// the dotted ones, kept in input order so positional assembly is stable.
	$buckets = array();

	foreach ( (array) $rows as $row ) {
		$key = (string) $row->meta_key;
		$dot = strpos( $key, '.' );

		if ( false === $dot ) {
			$buckets[ $key ]['single'] = (string) $row->meta_value;
			continue;
		}

		$root = substr( $key, 0, $dot );

		$buckets[ $root ]['subs'][ substr( $key, $dot + 1 ) ] = (string) $row->meta_value;
	}

	$values = array();

	foreach ( $fields as $source_id => $field ) {
		if ( ! isset( $buckets[ $source_id ] ) ) {
			continue;
		}

		$value = alltfo_gf_entry_value( $buckets[ $source_id ], $field );

		if ( null !== $value ) {
			$values[ $source_id ] = $value;
		}
	}

	return $values;
}

/**
 * One Gravity entry value, assembled into the shape its new field stores.
 *
 * @since 0.3.0
 *
 * @param array $bucket { single?: string, subs?: array }.
 * @param array $field  The imported field the value now belongs to.
 * @return mixed The assembled value, or null when the field holds nothing here.
 */
function alltfo_gf_entry_value( $bucket, $field ) {
	$single = isset( $bucket['single'] ) ? $bucket['single'] : '';
	$subs   = isset( $bucket['subs'] ) ? $bucket['subs'] : array();

	switch ( $field['type'] ) {
		case 'name':
			// Gravity numbers name inputs `{field}.{part}`, and the sub-numbers
			// are stable across every install -- the same table
			// `alltfo_gf_name_parts()` reads when the form is converted.
			$parts = array(
				'2' => 'prefix',
				'3' => 'first',
				'4' => 'middle',
				'6' => 'last',
				'8' => 'suffix',
			);

			return alltfo_gf_entry_parts( $subs, $parts );

		case 'address':
			$parts = array(
				'1' => 'line1',
				'2' => 'line2',
				'3' => 'city',
				'4' => 'region',
				'5' => 'postcode',
				'6' => 'country',
			);

			return alltfo_gf_entry_parts( $subs, $parts );

		case 'checkboxes':
			// One dotted row per ticked box, holding the choice's value. An
			// unticked box usually writes nothing, but old exports have been
			// seen to write empty strings -- either way, absent means unticked.
			if ( $subs ) {
				uksort( $subs, 'strnatcmp' );

				return array_values( array_filter( $subs, 'strlen' ) );
			}

			return '' !== $single ? array( $single ) : null;

		case 'multiselect':
			// Stored as a JSON list since Gravity 2.2, comma-joined before it.
			if ( '' === $single ) {
				return null;
			}

			if ( '[' === $single[0] ) {
				$decoded = json_decode( $single, true );

				if ( is_array( $decoded ) ) {
					return array_map( 'strval', $decoded );
				}
			}

			return array_map( 'trim', explode( ',', $single ) );

		case 'repeater':
			// A Gravity List survives as a serialised array: strings for a
			// single-column list, one array per row for a multi-column one.
			// Rows map onto the repeater's sub-fields positionally, because the
			// sub-fields were minted from the columns in order.
			$list = maybe_unserialize( $single );

			if ( ! is_array( $list ) ) {
				return null;
			}

			$sub_ids = array();

			foreach ( isset( $field['fields'] ) && is_array( $field['fields'] ) ? $field['fields'] : array() as $sub ) {
				if ( isset( $sub['id'] ) ) {
					$sub_ids[] = (string) $sub['id'];
				}
			}

			if ( ! $sub_ids ) {
				return null;
			}

			$rows = array();

			foreach ( $list as $row ) {
				$row_values = is_array( $row ) ? array_values( $row ) : array( $row );
				$assembled  = array();

				foreach ( $sub_ids as $index => $sub_id ) {
					$assembled[ $sub_id ] = isset( $row_values[ $index ] ) && is_scalar( $row_values[ $index ] )
						? (string) $row_values[ $index ]
						: '';
				}

				$rows[] = $assembled;
			}

			return $rows;

		case 'file':
			// Gravity stores upload URLs; entries here hold attachment ids. A
			// URL on another plugin's disk is not an attachment, and inventing
			// one would put a broken id in the entry -- the honest mapping is
			// none, same as the sanitiser would enforce anyway.
			return null;
	}

	return '' !== $single ? $single : null;
}

/**
 * Dotted sub-values as a composite's parts.
 *
 * @since 0.3.0
 *
 * @param array $subs  Sub-number => stored value.
 * @param array $parts Sub-number => part key.
 * @return array|null Part key => value, or null when every part is empty.
 */
function alltfo_gf_entry_parts( $subs, $parts ) {
	$value = array();

	foreach ( $parts as $sub => $part ) {
		if ( isset( $subs[ $sub ] ) && '' !== $subs[ $sub ] ) {
			$value[ $part ] = $subs[ $sub ];
		}
	}

	return $value ? $value : null;
}

<?php
/**
 * The field types that ship with the plugin.
 *
 * One `atf_register_field_type()` call each, using exactly the API a third-party
 * plugin would use. There is no privileged path: if a built-in needs something
 * the registry cannot express, the registry is missing a feature and gets one,
 * rather than the built-in reaching around it. That is the only way to know the
 * extension API actually works.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * The settings every ordinary input offers in the inspector.
 *
 * @since 0.1.0
 *
 * @param string[] $extra Additional supports to append.
 * @return string[] Support flags.
 */
function atf_input_supports( $extra = array() ) {
	return array_merge(
		array( 'label', 'placeholder', 'hint', 'required', 'default', 'width', 'css', 'prefill', 'logic' ),
		$extra
	);
}

/**
 * Registers every built-in field type.
 *
 * Called once from `atf_boot_field_types()`.
 *
 * @since 0.1.0
 *
 * @return void
 */
function atf_register_builtin_field_types() {

	/* -------------------------------------------------------------- Text -- */

	atf_register_field_type(
		'text',
		array(
			'label'       => __( 'Single line', 'allterrain-forms' ),
			'description' => __( 'A name, a subject, anything short.', 'allterrain-forms' ),
			'group'       => 'text',
			'icon'        => 'dashicons-editor-textcolor',
			'value'       => 'string',
			'supports'    => atf_input_supports( array( 'minlength', 'maxlength', 'pattern', 'unique' ) ),
			'position'    => 10,
		)
	);

	atf_register_field_type(
		'textarea',
		array(
			'label'       => __( 'Paragraph', 'allterrain-forms' ),
			'description' => __( 'A message, a description, several lines.', 'allterrain-forms' ),
			'group'       => 'text',
			'icon'        => 'dashicons-editor-paragraph',
			'value'       => 'text',
			'supports'    => atf_input_supports( array( 'minlength', 'maxlength', 'rows' ) ),
			'settings'    => array( 'rows' => 5 ),
			'position'    => 20,
		)
	);

	atf_register_field_type(
		'email',
		array(
			'label'       => __( 'Email', 'allterrain-forms' ),
			'description' => __( 'Validated, and offered as the reply-to address.', 'allterrain-forms' ),
			'group'       => 'text',
			'icon'        => 'dashicons-email',
			'value'       => 'string',
			'supports'    => atf_input_supports( array( 'unique' ) ),
			'sanitize'    => static function ( $raw ) {
				return sanitize_email( (string) ( is_scalar( $raw ) ? $raw : '' ) );
			},
			'validate'    => static function ( $value, $field ) {
				if ( '' === $value || is_email( $value ) ) {
					return true;
				}

				return new WP_Error(
					'atf_invalid_email',
					atf_field_message( $field, 'invalid', __( 'That does not look like an email address.', 'allterrain-forms' ) )
				);
			},
			'position'    => 30,
		)
	);

	atf_register_field_type(
		'url',
		array(
			'label'    => __( 'Website', 'allterrain-forms' ),
			'group'    => 'text',
			'icon'     => 'dashicons-admin-links',
			'value'    => 'string',
			'supports' => atf_input_supports(),
			'sanitize' => static function ( $raw ) {
				return esc_url_raw( trim( (string) ( is_scalar( $raw ) ? $raw : '' ) ) );
			},
			'validate' => static function ( $value, $field ) {
				if ( '' === $value || atf_looks_like_a_url( $value ) ) {
					return true;
				}

				return new WP_Error(
					'atf_invalid_url',
					atf_field_message( $field, 'invalid', __( 'That does not look like a web address.', 'allterrain-forms' ) )
				);
			},
			'position' => 40,
		)
	);

	atf_register_field_type(
		'tel',
		array(
			'label'    => __( 'Phone', 'allterrain-forms' ),
			'group'    => 'text',
			'icon'     => 'dashicons-phone',
			'value'    => 'string',
			'supports' => atf_input_supports( array( 'pattern' ) ),
			'position' => 50,
		)
	);

	atf_register_field_type(
		'number',
		array(
			'label'    => __( 'Number', 'allterrain-forms' ),
			'group'    => 'text',
			'icon'     => 'dashicons-calculator',
			'value'    => 'number',
			'supports' => atf_input_supports( array( 'min', 'max', 'step' ) ),
			'settings' => array( 'step' => '' ),
			'position' => 60,
		)
	);

	atf_register_field_type(
		'password',
		array(
			'label'       => __( 'Password', 'allterrain-forms' ),
			'description' => __( 'For registration forms. Never stored in the entry.', 'allterrain-forms' ),
			'group'       => 'text',
			'icon'        => 'dashicons-lock',
			'value'       => 'string',
			'supports'    => atf_input_supports( array( 'minlength' ) ),
			// A password is the one value the entry must not keep. The
			// registration action reads it out of the submission while it is
			// still in memory; by storage time this has replaced it, so an
			// entries export can never leak one.
			'format'      => static function () {
				return '';
			},
			'position'    => 70,
		)
	);

	atf_register_field_type(
		'hidden',
		array(
			'label'       => __( 'Hidden', 'allterrain-forms' ),
			'description' => __( 'Carries a value the visitor never sees — a campaign, a referrer.', 'allterrain-forms' ),
			'group'       => 'text',
			'icon'        => 'dashicons-hidden',
			'value'       => 'string',
			'supports'    => array( 'label', 'default', 'prefill', 'css' ),
			'position'    => 80,
		)
	);

	/* ------------------------------------------------------------ Choice -- */

	atf_register_field_type(
		'select',
		array(
			'label'    => __( 'Dropdown', 'allterrain-forms' ),
			'group'    => 'choice',
			'icon'     => 'dashicons-arrow-down-alt2',
			'value'    => 'string',
			'choices'  => true,
			'supports' => atf_input_supports( array( 'choices', 'placeholder' ) ),
			'format'   => 'atf_format_choice_value',
			'position' => 10,
		)
	);

	atf_register_field_type(
		'multiselect',
		array(
			'label'    => __( 'Multi-select', 'allterrain-forms' ),
			'group'    => 'choice',
			'icon'     => 'dashicons-list-view',
			'value'    => 'array',
			'choices'  => true,
			'supports' => atf_input_supports( array( 'choices', 'minchoices', 'maxchoices' ) ),
			'format'   => 'atf_format_choice_list',
			'position' => 20,
		)
	);

	atf_register_field_type(
		'radio',
		array(
			'label'    => __( 'Radio buttons', 'allterrain-forms' ),
			'group'    => 'choice',
			'icon'     => 'dashicons-marker',
			'value'    => 'string',
			'choices'  => true,
			'supports' => atf_input_supports( array( 'choices', 'other', 'inline' ) ),
			'format'   => 'atf_format_choice_value',
			'position' => 30,
		)
	);

	atf_register_field_type(
		'checkboxes',
		array(
			'label'    => __( 'Checkboxes', 'allterrain-forms' ),
			'group'    => 'choice',
			'icon'     => 'dashicons-yes-alt',
			'value'    => 'array',
			'choices'  => true,
			'supports' => atf_input_supports( array( 'choices', 'other', 'inline', 'minchoices', 'maxchoices' ) ),
			'format'   => 'atf_format_choice_list',
			'position' => 40,
		)
	);

	atf_register_field_type(
		'image_choice',
		array(
			'label'       => __( 'Image choice', 'allterrain-forms' ),
			'description' => __( 'Pick by picture. Drag images straight from WP Explorer.', 'allterrain-forms' ),
			'group'       => 'choice',
			'icon'        => 'dashicons-format-gallery',
			'value'       => 'string',
			'choices'     => true,
			'supports'    => atf_input_supports( array( 'choices', 'multiple', 'columns' ) ),
			'settings'    => array( 'columns' => 3 ),
			// The declared shape is a string, but the renderer posts an array
			// the moment `multiple` is on -- so the shape has to follow the
			// flag, exactly as checkboxes always store an array. Leaving this
			// to the generic string path would coerce every multi-selection
			// to '' and quietly lose all of it.
			'sanitize'    => static function ( $raw, $field ) {
				if ( empty( $field['multiple'] ) ) {
					return sanitize_text_field( (string) ( is_scalar( $raw ) ? $raw : '' ) );
				}

				$raw = is_array( $raw ) ? $raw : ( '' === $raw || null === $raw ? array() : array( $raw ) );

				return array_values(
					array_map(
						static function ( $item ) {
							return sanitize_text_field( (string) ( is_scalar( $item ) ? $item : '' ) );
						},
						$raw
					)
				);
			},
			// The list formatter, because it handles both shapes: a lone value
			// formats as its label, an array as a comma-separated list.
			'format'      => 'atf_format_choice_list',
			'position'    => 50,
		)
	);

	atf_register_field_type(
		'switch',
		array(
			'label'    => __( 'Toggle', 'allterrain-forms' ),
			'group'    => 'choice',
			'icon'     => 'dashicons-controls-play',
			'value'    => 'bool',
			'supports' => atf_input_supports(),
			'position' => 60,
		)
	);

	/* ----------------------------------------------------- Date and time -- */

	atf_register_field_type(
		'date',
		array(
			'label'    => __( 'Date', 'allterrain-forms' ),
			'group'    => 'datetime',
			'icon'     => 'dashicons-calendar-alt',
			'value'    => 'string',
			'supports' => atf_input_supports( array( 'mindate', 'maxdate' ) ),
			'validate' => 'atf_validate_date_value',
			'position' => 10,
		)
	);

	atf_register_field_type(
		'time',
		array(
			'label'    => __( 'Time', 'allterrain-forms' ),
			'group'    => 'datetime',
			'icon'     => 'dashicons-clock',
			'value'    => 'string',
			'supports' => atf_input_supports( array( 'mintime', 'maxtime', 'step' ) ),
			'position' => 20,
		)
	);

	atf_register_field_type(
		'datetime',
		array(
			'label'    => __( 'Date & time', 'allterrain-forms' ),
			'group'    => 'datetime',
			'icon'     => 'dashicons-calendar',
			'value'    => 'string',
			'supports' => atf_input_supports( array( 'mindate', 'maxdate' ) ),
			'position' => 30,
		)
	);

	atf_register_field_type(
		'date_range',
		array(
			'label'       => __( 'Date range', 'allterrain-forms' ),
			'description' => __( 'A from and a to, validated against each other.', 'allterrain-forms' ),
			'group'       => 'datetime',
			'icon'        => 'dashicons-calendar-alt',
			'value'       => 'object',
			'supports'    => atf_input_supports( array( 'mindate', 'maxdate' ) ),
			'validate'    => 'atf_validate_date_range',
			'format'      => static function ( $value ) {
				$from = isset( $value['from'] ) ? $value['from'] : '';
				$to   = isset( $value['to'] ) ? $value['to'] : '';

				if ( '' === $from && '' === $to ) {
					return '';
				}

				/* translators: 1: start date, 2: end date. */
				return sprintf( __( '%1$s to %2$s', 'allterrain-forms' ), $from, $to );
			},
			'position'    => 40,
		)
	);

	/* ---------------------------------------------------------- Advanced -- */

	atf_register_field_type(
		'file',
		array(
			'label'       => __( 'File upload', 'allterrain-forms' ),
			'description' => __( 'One file or many, into a directory nobody can browse.', 'allterrain-forms' ),
			'group'       => 'advanced',
			'icon'        => 'dashicons-upload',
			'value'       => 'files',
			'supports'    => atf_input_supports( array( 'filetypes', 'maxsize', 'maxfiles' ) ),
			'settings'    => array(
				'filetypes' => array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'txt', 'csv', 'zip' ),
				'maxsize'   => 10,
				'maxfiles'  => 1,
			),
			'format'      => 'atf_format_file_value',
			'position'    => 10,
		)
	);

	atf_register_field_type(
		'signature',
		array(
			'label'       => __( 'Signature', 'allterrain-forms' ),
			'description' => __( 'Sign with a finger or a mouse. Stored as an image.', 'allterrain-forms' ),
			'group'       => 'advanced',
			'icon'        => 'dashicons-edit',
			'value'       => 'string',
			'supports'    => atf_input_supports(),
			// The canvas posts a data URI. Anything that is not one is either a
			// browser that failed to draw or a forged field, and both are the
			// same empty answer.
			'sanitize'    => static function ( $raw ) {
				$raw = is_scalar( $raw ) ? (string) $raw : '';

				return preg_match( '#^data:image/(png|jpeg);base64,[a-zA-Z0-9+/=\s]+$#', $raw ) ? $raw : '';
			},
			'format'      => static function ( $value ) {
				return '' === $value ? '' : __( '(signed)', 'allterrain-forms' );
			},
			'position'    => 20,
		)
	);

	atf_register_field_type(
		'rating',
		array(
			'label'    => __( 'Star rating', 'allterrain-forms' ),
			'group'    => 'advanced',
			'icon'     => 'dashicons-star-filled',
			'value'    => 'number',
			'supports' => atf_input_supports( array( 'max' ) ),
			'settings' => array( 'max' => 5 ),
			'position' => 30,
		)
	);

	atf_register_field_type(
		'scale',
		array(
			'label'       => __( 'Opinion scale', 'allterrain-forms' ),
			'description' => __( 'Nought to ten, with a label at each end. NPS out of the box.', 'allterrain-forms' ),
			'group'       => 'advanced',
			'icon'        => 'dashicons-chart-bar',
			'value'       => 'number',
			'supports'    => atf_input_supports( array( 'min', 'max', 'endlabels' ) ),
			'settings'    => array(
				'min'      => 0,
				'max'      => 10,
				'minLabel' => '',
				'maxLabel' => '',
			),
			'position'    => 40,
		)
	);

	atf_register_field_type(
		'likert',
		array(
			'label'       => __( 'Likert matrix', 'allterrain-forms' ),
			'description' => __( 'A grid of statements against a shared set of answers.', 'allterrain-forms' ),
			'group'       => 'advanced',
			'icon'        => 'dashicons-grid-view',
			'value'       => 'object',
			'choices'     => true,
			'supports'    => atf_input_supports( array( 'choices', 'rows' ) ),
			'settings'    => array( 'rows' => array() ),
			'format'      => 'atf_format_likert_value',
			'position'    => 50,
		)
	);

	atf_register_field_type(
		'range',
		array(
			'label'    => __( 'Slider', 'allterrain-forms' ),
			'group'    => 'advanced',
			'icon'     => 'dashicons-leftright',
			'value'    => 'number',
			'supports' => atf_input_supports( array( 'min', 'max', 'step' ) ),
			'settings' => array(
				'min'  => 0,
				'max'  => 100,
				'step' => 1,
			),
			'position' => 60,
		)
	);

	atf_register_field_type(
		'color',
		array(
			'label'    => __( 'Colour', 'allterrain-forms' ),
			'group'    => 'advanced',
			'icon'     => 'dashicons-art',
			'value'    => 'string',
			'supports' => atf_input_supports(),
			'sanitize' => static function ( $raw ) {
				$hex = sanitize_hex_color( (string) ( is_scalar( $raw ) ? $raw : '' ) );

				return $hex ? $hex : '';
			},
			'position' => 70,
		)
	);

	atf_register_field_type(
		'name',
		array(
			'label'       => __( 'Name', 'allterrain-forms' ),
			'description' => __( 'Prefix, first, middle, last, suffix — turn on the parts you need.', 'allterrain-forms' ),
			'group'       => 'advanced',
			'icon'        => 'dashicons-admin-users',
			'value'       => 'object',
			'supports'    => atf_input_supports( array( 'parts' ) ),
			'settings'    => array(
				'parts' => array( 'first', 'last' ),
			),
			// Names join with spaces, not with the commas the generic composite
			// formatter uses. "Ada, Lovelace" is what an address looks like; it
			// is not what anybody is called, and it is what lands in the entries
			// list, every notification and every export.
			'format'      => static function ( $value ) {
				if ( ! is_array( $value ) ) {
					return '';
				}

				$order = array( 'prefix', 'first', 'middle', 'last', 'suffix' );
				$parts = array();

				foreach ( $order as $key ) {
					if ( isset( $value[ $key ] ) && '' !== trim( (string) $value[ $key ] ) ) {
						$parts[] = trim( (string) $value[ $key ] );
					}
				}

				return implode( ' ', $parts );
			},
			'position'    => 80,
		)
	);

	atf_register_field_type(
		'address',
		array(
			'label'    => __( 'Address', 'allterrain-forms' ),
			'group'    => 'advanced',
			'icon'     => 'dashicons-location',
			'value'    => 'object',
			'supports' => atf_input_supports( array( 'parts' ) ),
			'settings' => array(
				'parts' => array( 'line1', 'line2', 'city', 'region', 'postcode', 'country' ),
			),
			'format'   => static function ( $value ) {
				$order = array( 'line1', 'line2', 'city', 'region', 'postcode', 'country' );
				$parts = array();

				foreach ( $order as $key ) {
					if ( ! empty( $value[ $key ] ) ) {
						$parts[] = $value[ $key ];
					}
				}

				return implode( ', ', $parts );
			},
			'position' => 90,
		)
	);

	atf_register_field_type(
		'country',
		array(
			'label'    => __( 'Country', 'allterrain-forms' ),
			'group'    => 'advanced',
			'icon'     => 'dashicons-admin-site-alt3',
			'value'    => 'string',
			'supports' => atf_input_supports( array( 'placeholder' ) ),
			'position' => 100,
		)
	);

	atf_register_field_type(
		'repeater',
		array(
			'label'       => __( 'Repeater', 'allterrain-forms' ),
			'description' => __( 'A group of fields the visitor can answer as many times as they need.', 'allterrain-forms' ),
			'group'       => 'advanced',
			'icon'        => 'dashicons-plus-alt',
			'value'       => 'array',
			'supports'    => array( 'label', 'hint', 'required', 'width', 'css', 'logic', 'minrows', 'maxrows', 'addlabel', 'itemlabel' ),
			'settings'    => array(
				'fields'    => array(),
				'minRows'   => 1,
				'maxRows'   => 10,
				'addLabel'  => '',
				'itemLabel' => '',
			),
			'sanitize'    => 'atf_sanitize_repeater_value',
			'format'      => 'atf_format_repeater_value',
			'position'    => 110,
		)
	);

	/* ------------------------------------------------------------ Layout -- */

	atf_register_field_type(
		'heading',
		array(
			'label'    => __( 'Section heading', 'allterrain-forms' ),
			'group'    => 'layout',
			'icon'     => 'dashicons-heading',
			'input'    => false,
			'supports' => array( 'label', 'hint', 'level', 'css', 'logic' ),
			'settings' => array( 'level' => 3 ),
			'position' => 10,
		)
	);

	atf_register_field_type(
		'html',
		array(
			'label'       => __( 'HTML block', 'allterrain-forms' ),
			'description' => __( 'Arbitrary markup between fields.', 'allterrain-forms' ),
			'group'       => 'layout',
			'icon'        => 'dashicons-editor-code',
			'input'       => false,
			'supports'    => array( 'content', 'css', 'logic' ),
			'settings'    => array( 'content' => '' ),
			'position'    => 20,
		)
	);

	atf_register_field_type(
		'divider',
		array(
			'label'    => __( 'Divider', 'allterrain-forms' ),
			'group'    => 'layout',
			'icon'     => 'dashicons-minus',
			'input'    => false,
			'supports' => array( 'css', 'logic' ),
			'position' => 30,
		)
	);

	atf_register_field_type(
		'spacer',
		array(
			'label'    => __( 'Spacer', 'allterrain-forms' ),
			'group'    => 'layout',
			'icon'     => 'dashicons-image-flip-vertical',
			'input'    => false,
			'supports' => array( 'height', 'css', 'logic' ),
			'settings' => array( 'height' => 24 ),
			'position' => 40,
		)
	);

	atf_register_field_type(
		'page_break',
		array(
			'label'       => __( 'Page break', 'allterrain-forms' ),
			'description' => __( 'Everything after this is the next step.', 'allterrain-forms' ),
			'group'       => 'layout',
			'icon'        => 'dashicons-image-flip-horizontal',
			'input'       => false,
			'supports'    => array( 'label', 'nextlabel', 'prevlabel', 'logic' ),
			'settings'    => array(
				'nextLabel' => '',
				'prevLabel' => '',
			),
			'position'    => 50,
		)
	);

	/* ----------------------------------------------------------- Special -- */

	atf_register_field_type(
		'consent',
		array(
			'label'       => __( 'Consent', 'allterrain-forms' ),
			'description' => __( 'A GDPR checkbox that records exactly what was agreed to.', 'allterrain-forms' ),
			'group'       => 'special',
			'icon'        => 'dashicons-privacy',
			'value'       => 'bool',
			'supports'    => array( 'label', 'hint', 'required', 'css', 'logic', 'consenttext' ),
			'settings'    => array( 'consentText' => '' ),
			'validate'    => static function ( $value, $field ) {
				// A consent field that is not ticked is not "empty" -- it is a
				// refusal, and the required check has to say so in those terms
				// or the error reads as a form bug rather than a decision.
				if ( empty( $field['required'] ) || $value ) {
					return true;
				}

				return new WP_Error(
					'atf_consent_required',
					atf_field_message( $field, 'required', __( 'This has to be agreed to before the form can be sent.', 'allterrain-forms' ) )
				);
			},
			'position'    => 10,
		)
	);

	atf_register_field_type(
		'total',
		array(
			'label'       => __( 'Total', 'allterrain-forms' ),
			'description' => __( 'A read-only number computed from other fields.', 'allterrain-forms' ),
			'group'       => 'special',
			'icon'        => 'dashicons-calculator',
			'value'       => 'number',
			'supports'    => array( 'label', 'hint', 'width', 'css', 'logic', 'formula', 'currency' ),
			'settings'    => array(
				'formula'  => '',
				'currency' => '',
				'decimals' => 2,
			),
			'format'      => 'atf_format_total_value',
			'position'    => 20,
		)
	);

	atf_register_field_type(
		'quiz',
		array(
			'label'       => __( 'Quiz question', 'allterrain-forms' ),
			'description' => __( 'A choice with a right answer and a point value.', 'allterrain-forms' ),
			'group'       => 'special',
			'icon'        => 'dashicons-awards',
			'value'       => 'string',
			'choices'     => true,
			'supports'    => atf_input_supports( array( 'choices', 'correct', 'points' ) ),
			'settings'    => array(
				'correct' => '',
				'points'  => 1,
			),
			'format'      => 'atf_format_choice_value',
			'position'    => 30,
		)
	);
}

/**
 * Formats a single choice value as its label.
 *
 * @since 0.1.0
 *
 * @param mixed $value Stored value.
 * @param array $field The field.
 * @return string
 */
function atf_format_choice_value( $value, $field ) {
	return '' === $value || null === $value ? '' : atf_choice_label( $value, $field );
}

/**
 * Formats a list of choice values as a comma-separated list of labels.
 *
 * @since 0.1.0
 *
 * @param mixed $value Stored value.
 * @param array $field The field.
 * @return string
 */
function atf_format_choice_list( $value, $field ) {
	if ( ! is_array( $value ) ) {
		return '' === $value ? '' : atf_choice_label( $value, $field );
	}

	$labels = array();

	foreach ( $value as $item ) {
		$labels[] = atf_choice_label( $item, $field );
	}

	return implode( ', ', $labels );
}

/**
 * Formats uploaded files as their URLs, or as a count in a table.
 *
 * The entries table gets "3 files" because a cell full of long URLs is
 * unreadable; an e-mail and a CSV get the URLs, because that is the only way to
 * reach the file from outside wp-admin.
 *
 * @since 0.1.0
 *
 * @param mixed  $value   Attachment ids.
 * @param array  $field   The field.
 * @param string $context Destination.
 * @return string
 */
function atf_format_file_value( $value, $field, $context = 'table' ) {
	$ids = array_filter( array_map( 'absint', (array) $value ) );

	if ( ! $ids ) {
		return '';
	}

	if ( 'table' === $context ) {
		/* translators: %d: number of uploaded files. */
		return sprintf( _n( '%d file', '%d files', count( $ids ), 'allterrain-forms' ), count( $ids ) );
	}

	$urls = array();

	foreach ( $ids as $id ) {
		$url = wp_get_attachment_url( $id );

		if ( $url ) {
			$urls[] = $url;
		}
	}

	return implode( "\n", $urls );
}

/**
 * Formats a Likert matrix as "Statement: Answer" lines.
 *
 * @since 0.1.0
 *
 * @param mixed $value Row key => choice value.
 * @param array $field The field.
 * @return string
 */
function atf_format_likert_value( $value, $field ) {
	if ( ! is_array( $value ) ) {
		return '';
	}

	$rows  = isset( $field['rows'] ) && is_array( $field['rows'] ) ? $field['rows'] : array();
	$lines = array();

	foreach ( $value as $row_key => $answer ) {
		$label = $row_key;

		foreach ( $rows as $row ) {
			if ( isset( $row['key'] ) && $row['key'] === $row_key ) {
				$label = isset( $row['label'] ) ? $row['label'] : $row_key;
				break;
			}
		}

		$lines[] = $label . ': ' . atf_choice_label( $answer, $field );
	}

	return implode( "\n", $lines );
}

/**
 * Formats a calculated total, with its currency symbol when it has one.
 *
 * @since 0.1.0
 *
 * @param mixed $value Stored number.
 * @param array $field The field.
 * @return string
 */
function atf_format_total_value( $value, $field ) {
	if ( '' === $value || null === $value ) {
		return '';
	}

	$decimals = isset( $field['decimals'] ) ? absint( $field['decimals'] ) : 2;
	$number   = number_format_i18n( (float) $value, $decimals );
	$currency = isset( $field['currency'] ) ? (string) $field['currency'] : '';

	return '' === $currency ? $number : $currency . $number;
}

/**
 * Sanitises a repeater's rows.
 *
 * Each row is sanitised against the repeater's *own* sub-field list rather than
 * generically, so a number inside a repeater is still a number and a textarea
 * inside one still keeps its newlines. Rows beyond `maxRows` are dropped here
 * rather than rejected, because a request carrying more rows than the form
 * offers is a forged one and there is nothing to tell the visitor.
 *
 * @since 0.1.0
 *
 * @param mixed $raw   Raw rows.
 * @param array $field The repeater field.
 * @return array Sanitised rows.
 */
function atf_sanitize_repeater_value( $raw, $field ) {
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$sub_fields = isset( $field['fields'] ) && is_array( $field['fields'] ) ? $field['fields'] : array();
	$max_rows   = isset( $field['maxRows'] ) ? absint( $field['maxRows'] ) : 10;
	$rows       = array();

	foreach ( array_values( $raw ) as $row ) {
		if ( count( $rows ) >= $max_rows ) {
			break;
		}

		if ( ! is_array( $row ) ) {
			continue;
		}

		$clean = array();

		foreach ( $sub_fields as $sub ) {
			$key = isset( $sub['id'] ) ? (string) $sub['id'] : '';

			if ( '' === $key ) {
				continue;
			}

			$clean[ $key ] = atf_sanitize_field_value( isset( $row[ $key ] ) ? $row[ $key ] : '', $sub );
		}

		// A row where every field came back empty is a row the visitor added and
		// never filled in. Keeping it would put blank lines in the e-mail and an
		// empty row in the export.
		$has_content = false;

		foreach ( $clean as $value ) {
			if ( '' !== $value && array() !== $value && false !== $value ) {
				$has_content = true;
				break;
			}
		}

		if ( $has_content ) {
			$rows[] = $clean;
		}
	}

	return $rows;
}

/**
 * Formats a repeater as one line per row.
 *
 * @since 0.1.0
 *
 * @param mixed  $value   Rows.
 * @param array  $field   The repeater field.
 * @param string $context Destination.
 * @return string
 */
function atf_format_repeater_value( $value, $field, $context = 'table' ) {
	if ( ! is_array( $value ) || ! $value ) {
		return '';
	}

	$item_label = atf_repeater_item_label( $field );

	if ( 'table' === $context ) {
		if ( isset( $field['itemLabel'] ) && '' !== $field['itemLabel'] ) {
			/* translators: 1: number of repeater rows, 2: what one row is called, e.g. "Attendee". */
			return sprintf( _n( '%1$d %2$s', '%1$d × %2$s', count( $value ), 'allterrain-forms' ), count( $value ), $field['itemLabel'] );
		}

		/* translators: %d: number of repeater rows. */
		return sprintf( _n( '%d row', '%d rows', count( $value ), 'allterrain-forms' ), count( $value ) );
	}

	$sub_fields = isset( $field['fields'] ) && is_array( $field['fields'] ) ? $field['fields'] : array();
	$lines      = array();

	foreach ( $value as $index => $row ) {
		$parts = array();

		foreach ( $sub_fields as $sub ) {
			$key = isset( $sub['id'] ) ? (string) $sub['id'] : '';

			if ( '' === $key || ! isset( $row[ $key ] ) ) {
				continue;
			}

			$label   = isset( $sub['label'] ) && '' !== $sub['label'] ? $sub['label'] : $key;
			$parts[] = $label . ': ' . atf_format_field_value( $row[ $key ], $sub, $context );
		}

		/* translators: 1: what one row is called, e.g. "Attendee", 2: row number, 3: the row's values. */
		$lines[] = sprintf( __( '%1$s %2$d — %3$s', 'allterrain-forms' ), $item_label, $index + 1, implode( ', ', $parts ) );
	}

	return implode( "\n", $lines );
}

/**
 * What one of a repeater's rows is called.
 *
 * "Attendee", "Guest", "Line item" — whatever the builder set — falling back
 * to a plain "Row". Numbered by the caller: "Attendee 1", "Attendee 2".
 *
 * @since 0.1.0
 *
 * @param array $field The repeater field.
 * @return string
 */
function atf_repeater_item_label( $field ) {
	return isset( $field['itemLabel'] ) && '' !== $field['itemLabel']
		? (string) $field['itemLabel']
		: __( 'Row', 'allterrain-forms' );
}

/**
 * Validates a date value against the field's own bounds.
 *
 * @since 0.1.0
 *
 * @param mixed $value The submitted date, `YYYY-MM-DD`.
 * @param array $field The field.
 * @return true|WP_Error
 */
function atf_validate_date_value( $value, $field ) {
	if ( '' === $value ) {
		return true;
	}

	$date = DateTime::createFromFormat( 'Y-m-d', (string) $value );

	if ( ! $date || $date->format( 'Y-m-d' ) !== (string) $value ) {
		return new WP_Error(
			'atf_invalid_date',
			atf_field_message( $field, 'invalid', __( 'That is not a date we recognise.', 'allterrain-forms' ) )
		);
	}

	if ( ! empty( $field['minDate'] ) && $value < $field['minDate'] ) {
		return new WP_Error(
			'atf_date_too_early',
			atf_field_message(
				$field,
				'min',
				/* translators: %s: the earliest allowed date. */
				sprintf( __( 'Pick a date on or after %s.', 'allterrain-forms' ), $field['minDate'] )
			)
		);
	}

	if ( ! empty( $field['maxDate'] ) && $value > $field['maxDate'] ) {
		return new WP_Error(
			'atf_date_too_late',
			atf_field_message(
				$field,
				'max',
				/* translators: %s: the latest allowed date. */
				sprintf( __( 'Pick a date on or before %s.', 'allterrain-forms' ), $field['maxDate'] )
			)
		);
	}

	return true;
}

/**
 * Validates that a date range runs forwards.
 *
 * @since 0.1.0
 *
 * @param mixed $value The submitted range.
 * @param array $field The field.
 * @return true|WP_Error
 */
function atf_validate_date_range( $value, $field ) {
	$from = isset( $value['from'] ) ? (string) $value['from'] : '';
	$to   = isset( $value['to'] ) ? (string) $value['to'] : '';

	if ( '' === $from || '' === $to ) {
		return true;
	}

	if ( $from > $to ) {
		return new WP_Error(
			'atf_range_backwards',
			atf_field_message( $field, 'invalid', __( 'The end date comes before the start date.', 'allterrain-forms' ) )
		);
	}

	return true;
}

/**
 * The message a field shows for a given failure, honouring any override.
 *
 * Every field can carry its own wording under `messages`, because the right
 * words for a required field depend entirely on what it is asking for -- "we
 * need your email to reply" beats "this field is required" every time, and only
 * the person building the form knows which is which.
 *
 * @since 0.1.0
 *
 * @param array  $field   The field.
 * @param string $key     Failure key: required|invalid|min|max|unique.
 * @param string $default Wording to use when the field has no override.
 * @return string
 */
function atf_field_message( $field, $key, $default ) {
	if ( isset( $field['messages'][ $key ] ) && '' !== trim( (string) $field['messages'][ $key ] ) ) {
		return (string) $field['messages'][ $key ];
	}

	return $default;
}

/**
 * Whether a string is shaped like a web address a person could visit.
 *
 * Deliberately *not* `wp_http_validate_url()`, which is the wrong tool by
 * design. That function exists to decide whether **the server** may make an HTTP
 * request to a URL, so it is built to refuse things that are perfectly valid for
 * a visitor to type:
 *
 * - it resolves the host through DNS on every call, which means a submission's
 *   speed depends on a name server, and a site with no outbound DNS — a local
 *   install, a locked-down host, a container — rejects `https://example.com`;
 * - it refuses any host that resolves inside a private range, so an intranet
 *   address a staff form legitimately collects is called invalid;
 * - it allows only ports 80, 443 and 8080.
 *
 * Every one of those is correct for SSRF protection and wrong for "is this a
 * website address". Worse, the DNS lookup turns the form into an oracle: whoever
 * can submit it can ask the server to resolve any hostname they like.
 *
 * So this checks the shape, and only the shape — a parseable URL with a scheme
 * we are willing to link to and a host with a dot in it. Whether the site at the
 * other end exists is not something a form can know, and pretending otherwise is
 * how a valid address gets rejected at the moment somebody is trying to submit.
 *
 * @since 0.1.0
 *
 * @param string $value The submitted value.
 * @return bool
 */
function atf_looks_like_a_url( $value ) {
	$value = trim( (string) $value );

	if ( '' === $value || ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
		return false;
	}

	$parts = wp_parse_url( $value );

	if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return false;
	}

	/**
	 * Filters the schemes a website field accepts.
	 *
	 * `http` and `https` only by default. `javascript:` and `data:` parse as
	 * valid URLs and must never be accepted here — the value ends up in an
	 * `href` in a notification email and in the entries screen.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $schemes Accepted schemes.
	 */
	$schemes = apply_filters( 'atf_url_schemes', array( 'http', 'https' ) );

	if ( ! in_array( strtolower( $parts['scheme'] ), $schemes, true ) ) {
		return false;
	}

	// A host with no dot is either a bare hostname on a local network or a typo.
	// `localhost` is spelled out because it is the one dotless host people
	// genuinely mean, and refusing it makes every development site fail.
	return false !== strpos( $parts['host'], '.' ) || 'localhost' === strtolower( $parts['host'] );
}

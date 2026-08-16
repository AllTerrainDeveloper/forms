<?php
/**
 * The control for each field type.
 *
 * Split from `render.php` because that file is about the chrome every field
 * shares -- wrapper, label, hint, error, logic attributes -- and this one is
 * about the input itself. Keeping them apart means adding a field type touches
 * one `case` here and nothing else.
 *
 * Grouped controls (radio, checkboxes, image choice, likert, name, address) are
 * wrapped in a `<fieldset>` with a `<legend>` rather than given a `<label>`.
 * That is not a stylistic preference: a `<label>` may only point at one control,
 * so a label above six radios is bound to none of them, and a screen-reader user
 * arriving at the third option is told nothing about what is being asked.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the control for one field.
 *
 * @since 0.1.0
 *
 * @param array $field   The field.
 * @param mixed $value   Its current value.
 * @param array $context The render context from `atf_render_field()`.
 * @return string
 */
function atf_render_field_control( $field, $value, $context ) {
	switch ( $field['type'] ) {

		case 'textarea':
			return atf_render_label( $field, $context['id'] ) . sprintf(
				'<textarea class="atf-input atf-textarea"%s rows="%d">%s</textarea>',
				atf_control_attributes( $field, $context ),
				isset( $field['rows'] ) ? absint( $field['rows'] ) : 5,
				esc_textarea( (string) $value )
			);

		case 'select':
			return atf_render_select( $field, $value, $context );

		case 'multiselect':
			return atf_render_multiselect( $field, $value, $context );

		case 'radio':
		case 'quiz':
			return atf_render_choice_group( $field, $value, $context, 'radio' );

		case 'checkboxes':
			return atf_render_choice_group( $field, $value, $context, 'checkbox' );

		case 'image_choice':
			return atf_render_image_choice( $field, $value, $context );

		case 'switch':
		case 'consent':
			return atf_render_single_checkbox( $field, $value, $context );

		case 'rating':
			return atf_render_rating( $field, $value, $context );

		case 'scale':
			return atf_render_scale( $field, $value, $context );

		case 'likert':
			return atf_render_likert( $field, $value, $context );

		case 'range':
			return atf_render_range( $field, $value, $context );

		case 'file':
			return atf_render_file( $field, $value, $context );

		case 'signature':
			return atf_render_signature( $field, $value, $context );

		case 'name':
			return atf_render_composite( $field, $value, $context, atf_name_parts() );

		case 'address':
			return atf_render_composite( $field, $value, $context, atf_address_parts() );

		case 'date_range':
			return atf_render_composite(
				$field,
				$value,
				$context,
				array(
					'from' => array(
						'label' => __( 'From', 'allterrain-forms' ),
						'type'  => 'date',
					),
					'to'   => array(
						'label' => __( 'To', 'allterrain-forms' ),
						'type'  => 'date',
					),
				)
			);

		case 'country':
			return atf_render_country( $field, $value, $context );

		case 'repeater':
			return atf_render_repeater( $field, $value, $context );

		case 'total':
			return atf_render_total( $field, $value, $context );

		case 'hidden':
			return sprintf(
				'<input type="hidden" id="%s" name="%s" value="%s" data-atf-input>',
				esc_attr( $context['id'] ),
				esc_attr( 'atf[' . $field['id'] . ']' ),
				esc_attr( (string) $value )
			);

		case 'heading':
			$level = isset( $field['level'] ) ? max( 2, min( 6, absint( $field['level'] ) ) ) : 3;

			return sprintf(
				'<h%1$d class="atf-heading">%2$s</h%1$d>%3$s',
				$level,
				esc_html( $field['label'] ),
				'' !== $field['hint'] ? '' : ''
			);

		case 'html':
			// Already run through `wp_kses_post()` at normalisation, which is
			// the right moment: sanitising on output would re-filter trusted
			// stored markup on every page view for no further safety.
			return sprintf( '<div class="atf-html">%s</div>', isset( $field['content'] ) ? $field['content'] : '' );

		case 'divider':
			return '<hr class="atf-divider">';

		case 'spacer':
			return sprintf(
				'<div class="atf-spacer" style="height:%dpx" aria-hidden="true"></div>',
				isset( $field['height'] ) ? absint( $field['height'] ) : 24
			);

		case 'page_break':
			// Consumed by `atf_schema_pages()` before rendering ever reaches a
			// break, so arriving here means a break inside a repeater or some
			// other nesting that cannot paginate. Rendering nothing is right.
			return '';

		case 'date':
		case 'time':
		case 'datetime':
		case 'email':
		case 'url':
		case 'tel':
		case 'number':
		case 'password':
		case 'color':
		case 'text':
		default:
			return atf_render_text_input( $field, $value, $context );
	}
}

/**
 * A single-line input of whatever HTML type the field maps to.
 *
 * @since 0.1.0
 *
 * @param array $field   The field.
 * @param mixed $value   Its value.
 * @param array $context The render context.
 * @return string
 */
function atf_render_text_input( $field, $value, $context ) {
	$types = array(
		'email'    => 'email',
		'url'      => 'url',
		'tel'      => 'tel',
		'number'   => 'number',
		'password' => 'password',
		'date'     => 'date',
		'time'     => 'time',
		'datetime' => 'datetime-local',
		'color'    => 'color',
	);

	$type       = isset( $types[ $field['type'] ] ) ? $types[ $field['type'] ] : 'text';
	$attributes = atf_control_attributes( $field, $context );

	// The date bounds are `min`/`max` in HTML but named `minDate`/`maxDate` in
	// the schema, because a date field also has a numeric `min` in the shared
	// settings and one would silently overwrite the other.
	foreach ( array(
		'minDate' => 'min',
		'maxDate' => 'max',
		'minTime' => 'min',
		'maxTime' => 'max',
	) as $key => $attribute ) {
		if ( ! empty( $field[ $key ] ) ) {
			$attributes .= sprintf( ' %s="%s"', $attribute, esc_attr( $field[ $key ] ) );
		}
	}

	return atf_render_label( $field, $context['id'] ) . sprintf(
		'<input type="%s" class="atf-input"%s value="%s" data-atf-input>',
		esc_attr( $type ),
		$attributes,
		esc_attr( is_scalar( $value ) ? (string) $value : '' )
	);
}

/**
 * A dropdown.
 *
 * @since 0.1.0
 *
 * @param array $field   The field.
 * @param mixed $value   Its value.
 * @param array $context The render context.
 * @return string
 */
function atf_render_select( $field, $value, $context ) {
	$options = '';

	// A placeholder option is `value=""` and `disabled` only when the field is
	// required -- on an optional field the visitor must be able to get back to
	// "nothing chosen" after choosing something.
	if ( '' !== $field['placeholder'] ) {
		$options .= sprintf(
			'<option value=""%s>%s</option>',
			$field['required'] ? ' disabled' : '',
			esc_html( $field['placeholder'] )
		);
	}

	foreach ( $field['choices'] as $choice ) {
		$options .= sprintf(
			'<option value="%s"%s>%s</option>',
			esc_attr( $choice['value'] ),
			selected( (string) $value, (string) $choice['value'], false ),
			esc_html( $choice['label'] )
		);
	}

	return atf_render_label( $field, $context['id'] ) . sprintf(
		'<select class="atf-input atf-select"%s data-atf-input>%s</select>',
		atf_control_attributes( $field, $context ),
		$options
	);
}

/**
 * A multi-select.
 *
 * @since 0.1.0
 *
 * @param array $field   The field.
 * @param mixed $value   Its value.
 * @param array $context The render context.
 * @return string
 */
function atf_render_multiselect( $field, $value, $context ) {
	$selected = array_map( 'strval', (array) $value );
	$options  = '';

	foreach ( $field['choices'] as $choice ) {
		$options .= sprintf(
			'<option value="%s"%s>%s</option>',
			esc_attr( $choice['value'] ),
			in_array( (string) $choice['value'], $selected, true ) ? ' selected' : '',
			esc_html( $choice['label'] )
		);
	}

	// The name takes `[]` so PHP collects the whole selection rather than only
	// the last option.
	$attributes = str_replace(
		sprintf( 'name="%s"', esc_attr( 'atf[' . $field['id'] . ']' ) ),
		sprintf( 'name="%s"', esc_attr( 'atf[' . $field['id'] . '][]' ) ),
		atf_control_attributes( $field, $context )
	);

	return atf_render_label( $field, $context['id'] ) . sprintf(
		'<select class="atf-input atf-select atf-multiselect" multiple%s data-atf-input>%s</select>',
		$attributes,
		$options
	);
}

/**
 * A group of radios or checkboxes.
 *
 * @since 0.1.0
 *
 * @param array  $field   The field.
 * @param mixed  $value   Its value.
 * @param array  $context The render context.
 * @param string $type    `radio` or `checkbox`.
 * @return string
 */
function atf_render_choice_group( $field, $value, $context, $type ) {
	$multiple = 'checkbox' === $type;
	$selected = array_map( 'strval', (array) $value );
	$name     = 'atf[' . $field['id'] . ']' . ( $multiple ? '[]' : '' );

	$described = '' !== $context['describedby']
		? sprintf( ' aria-describedby="%s"', esc_attr( $context['describedby'] ) )
		: '';

	$out = sprintf(
		'<fieldset class="atf-choices%s"%s%s>',
		! empty( $field['inline'] ) ? ' atf-choices--inline' : '',
		$field['required'] ? ' aria-required="true"' : '',
		$described
	);

	$out .= atf_render_label( $field, $context['id'], 'legend' );
	$out .= '<div class="atf-choices__list">';

	foreach ( $field['choices'] as $index => $choice ) {
		$choice_id = $context['id'] . '-' . $index;

		$out .= sprintf(
			'<div class="atf-choice"><input type="%s" id="%s" name="%s" value="%s"%s class="atf-choice__input" data-atf-input>'
			. '<label class="atf-choice__label" for="%s">%s</label></div>',
			esc_attr( $type ),
			esc_attr( $choice_id ),
			esc_attr( $name ),
			esc_attr( $choice['value'] ),
			in_array( (string) $choice['value'], $selected, true ) ? ' checked' : '',
			esc_attr( $choice_id ),
			esc_html( $choice['label'] )
		);
	}

	// "Other" is a choice plus a text box that is only meaningful while that
	// choice is picked. The text box is not `required` even when the field is,
	// because the group's own requirement is already satisfied by picking
	// something -- and a required input inside a hidden branch is a form nobody
	// can submit.
	if ( ! empty( $field['other'] ) ) {
		$other_id = $context['id'] . '-other';

		$out .= sprintf(
			'<div class="atf-choice atf-choice--other"><input type="%1$s" id="%2$s" name="%3$s" value="__other__" class="atf-choice__input" data-atf-other-toggle>'
			. '<label class="atf-choice__label" for="%2$s">%4$s</label>'
			. '<input type="text" class="atf-input atf-choice__other" name="%5$s" aria-label="%6$s" data-atf-other-input>'
			. '</div>',
			esc_attr( $type ),
			esc_attr( $other_id ),
			esc_attr( $name ),
			esc_html__( 'Other', 'allterrain-forms' ),
			esc_attr( 'atf_other[' . $field['id'] . ']' ),
			esc_attr__( 'Please specify', 'allterrain-forms' )
		);
	}

	return $out . '</div></fieldset>';
}

/**
 * A grid of image choices.
 *
 * @since 0.1.0
 *
 * @param array $field   The field.
 * @param mixed $value   Its value.
 * @param array $context The render context.
 * @return string
 */
function atf_render_image_choice( $field, $value, $context ) {
	$multiple = ! empty( $field['multiple'] );
	$type     = $multiple ? 'checkbox' : 'radio';
	$selected = array_map( 'strval', (array) $value );
	$name     = 'atf[' . $field['id'] . ']' . ( $multiple ? '[]' : '' );
	$columns  = isset( $field['columns'] ) ? max( 1, min( 6, absint( $field['columns'] ) ) ) : 3;

	$out  = sprintf( '<fieldset class="atf-images" style="--atf-image-columns:%d">', $columns );
	$out .= atf_render_label( $field, $context['id'], 'legend' );
	$out .= '<div class="atf-images__grid">';

	foreach ( $field['choices'] as $index => $choice ) {
		$choice_id = $context['id'] . '-' . $index;
		$image     = ! empty( $choice['image'] ) ? wp_get_attachment_image( $choice['image'], 'medium', false, array( 'class' => 'atf-images__img' ) ) : '';

		$out .= sprintf(
			'<div class="atf-images__item"><input type="%s" id="%s" name="%s" value="%s"%s class="atf-images__input" data-atf-input>'
			. '<label class="atf-images__label" for="%s">%s<span class="atf-images__caption">%s</span></label></div>',
			esc_attr( $type ),
			esc_attr( $choice_id ),
			esc_attr( $name ),
			esc_attr( $choice['value'] ),
			in_array( (string) $choice['value'], $selected, true ) ? ' checked' : '',
			esc_attr( $choice_id ),
			// Already escaped by `wp_get_attachment_image()`; an empty string
			// when the choice has no image, which is a legitimate half-built
			// state in the builder rather than an error.
			$image,
			esc_html( $choice['label'] )
		);
	}

	return $out . '</div></fieldset>';
}

/**
 * A lone checkbox: a toggle or a consent box.
 *
 * The label sits after the control, which is the one place in this renderer
 * where that order is right -- a checkbox reads as "[x] I agree", not
 * "I agree [x]".
 *
 * @since 0.1.0
 *
 * @param array $field   The field.
 * @param mixed $value   Its value.
 * @param array $context The render context.
 * @return string
 */
function atf_render_single_checkbox( $field, $value, $context ) {
	$text = 'consent' === $field['type'] && ! empty( $field['consentText'] )
		? $field['consentText']
		: $field['label'];

	$attributes = atf_control_attributes( $field, $context );

	// A Toggle is drawn as a switch and a Consent as a tick box. They behave
	// identically — both are one checkbox — but they say different things: a
	// switch reads as "this setting is on", and consent reads as "I have agreed",
	// which is a statement a tick box makes and a switch does not. Calling the
	// field type "Toggle" and then drawing a checkbox was the naming lying about
	// the control.
	$modifier = 'switch' === $field['type'] ? ' atf-toggle--switch' : '';

	return sprintf(
		'<div class="atf-toggle%s"><input type="checkbox" class="atf-toggle__input"%s value="1"%s data-atf-input>'
		. '<label class="atf-toggle__label" for="%s">%s%s</label></div>',
		$modifier,
		$attributes,
		$value ? ' checked' : '',
		esc_attr( $context['id'] ),
		wp_kses_post( $text ),
		$field['required'] ? '<span class="atf-required" aria-hidden="true">*</span>' : ''
	);
}

/**
 * A star rating.
 *
 * Radio inputs underneath, styled as stars. Not a row of buttons, and not a
 * `range`: radios are what "pick exactly one of five" means to assistive
 * technology, they arrive in the POST body without JavaScript, and they are
 * keyboard-navigable with arrow keys for free.
 *
 * @since 0.1.0
 *
 * @param array $field   The field.
 * @param mixed $value   Its value.
 * @param array $context The render context.
 * @return string
 */
function atf_render_rating( $field, $value, $context ) {
	$max  = isset( $field['max'] ) ? max( 2, min( 10, absint( $field['max'] ) ) ) : 5;
	$name = 'atf[' . $field['id'] . ']';

	$out  = '<fieldset class="atf-rating">';
	$out .= atf_render_label( $field, $context['id'], 'legend' );
	$out .= '<div class="atf-rating__stars">';

	for ( $i = 1; $i <= $max; $i++ ) {
		$star_id = $context['id'] . '-' . $i;

		$out .= sprintf(
			'<input type="radio" id="%s" name="%s" value="%d"%s class="atf-rating__input" data-atf-input>'
			. '<label class="atf-rating__star" for="%s"><span class="screen-reader-text">%s</span></label>',
			esc_attr( $star_id ),
			esc_attr( $name ),
			$i,
			(string) $value === (string) $i ? ' checked' : '',
			esc_attr( $star_id ),
			esc_html(
				sprintf(
					/* translators: 1: this star's number, 2: the highest rating. */
					_n( '%1$d out of %2$d', '%1$d out of %2$d', $i, 'allterrain-forms' ),
					$i,
					$max
				)
			)
		);
	}

	return $out . '</div></fieldset>';
}

/**
 * An opinion scale, with a label at each end.
 *
 * @since 0.1.0
 *
 * @param array $field   The field.
 * @param mixed $value   Its value.
 * @param array $context The render context.
 * @return string
 */
function atf_render_scale( $field, $value, $context ) {
	$min  = isset( $field['min'] ) ? (int) $field['min'] : 0;
	$max  = isset( $field['max'] ) ? (int) $field['max'] : 10;
	$name = 'atf[' . $field['id'] . ']';

	if ( $max <= $min ) {
		$max = $min + 10;
	}

	// A scale wider than this is a slider wearing the wrong control, and would
	// emit dozens of radios nobody can use.
	$max = min( $max, $min + 20 );

	$out  = '<fieldset class="atf-scale">';
	$out .= atf_render_label( $field, $context['id'], 'legend' );
	$out .= '<div class="atf-scale__row">';

	for ( $i = $min; $i <= $max; $i++ ) {
		$point_id = $context['id'] . '-' . $i;

		$out .= sprintf(
			'<div class="atf-scale__point"><input type="radio" id="%s" name="%s" value="%d"%s class="atf-scale__input" data-atf-input>'
			. '<label class="atf-scale__label" for="%s">%d</label></div>',
			esc_attr( $point_id ),
			esc_attr( $name ),
			$i,
			(string) $value === (string) $i ? ' checked' : '',
			esc_attr( $point_id ),
			$i
		);
	}

	$out .= '</div>';

	if ( ! empty( $field['minLabel'] ) || ! empty( $field['maxLabel'] ) ) {
		$out .= sprintf(
			'<div class="atf-scale__ends" aria-hidden="true"><span>%s</span><span>%s</span></div>',
			esc_html( isset( $field['minLabel'] ) ? $field['minLabel'] : '' ),
			esc_html( isset( $field['maxLabel'] ) ? $field['maxLabel'] : '' )
		);
	}

	return $out . '</fieldset>';
}

/**
 * A Likert matrix.
 *
 * A real `<table>` with `scope` on both header directions, so each radio's
 * accessible name is its row's statement crossed with its column's answer. A
 * grid of divs would leave every radio in the grid announced as just "radio".
 *
 * @since 0.1.0
 *
 * @param array $field   The field.
 * @param mixed $value   Its value.
 * @param array $context The render context.
 * @return string
 */
function atf_render_likert( $field, $value, $context ) {
	$rows = isset( $field['rows'] ) && is_array( $field['rows'] ) ? $field['rows'] : array();

	if ( ! $rows || ! $field['choices'] ) {
		return atf_render_label( $field, $context['id'] );
	}

	$value = is_array( $value ) ? $value : array();

	$out  = '<div class="atf-likert">';
	$out .= atf_render_label( $field, $context['id'] );
	$out .= sprintf( '<table class="atf-likert__table"><caption class="screen-reader-text">%s</caption><thead><tr><td></td>', esc_html( $field['label'] ) );

	foreach ( $field['choices'] as $choice ) {
		$out .= sprintf( '<th scope="col">%s</th>', esc_html( $choice['label'] ) );
	}

	$out .= '</tr></thead><tbody>';

	foreach ( $rows as $index => $row ) {
		$row_key   = isset( $row['key'] ) ? (string) $row['key'] : 'r' . $index;
		$row_label = isset( $row['label'] ) ? (string) $row['label'] : $row_key;
		$answer    = isset( $value[ $row_key ] ) ? (string) $value[ $row_key ] : '';

		$out .= sprintf( '<tr><th scope="row">%s</th>', esc_html( $row_label ) );

		foreach ( $field['choices'] as $choice_index => $choice ) {
			$cell_id = $context['id'] . '-' . $index . '-' . $choice_index;

			$out .= sprintf(
				'<td><input type="radio" id="%s" name="%s" value="%s"%s data-atf-input>'
				. '<label for="%s" class="screen-reader-text">%s</label></td>',
				esc_attr( $cell_id ),
				esc_attr( 'atf[' . $field['id'] . '][' . $row_key . ']' ),
				esc_attr( $choice['value'] ),
				$answer === (string) $choice['value'] ? ' checked' : '',
				esc_attr( $cell_id ),
				esc_html( $row_label . ' — ' . $choice['label'] )
			);
		}

		$out .= '</tr>';
	}

	return $out . '</tbody></table></div>';
}

/**
 * A slider with a live numeric readout.
 *
 * @since 0.1.0
 *
 * @param array $field   The field.
 * @param mixed $value   Its value.
 * @param array $context The render context.
 * @return string
 */
function atf_render_range( $field, $value, $context ) {
	$min     = isset( $field['min'] ) ? (float) $field['min'] : 0;
	$max     = isset( $field['max'] ) ? (float) $field['max'] : 100;
	$current = '' === $value || null === $value ? $min : (float) $value;

	return atf_render_label( $field, $context['id'] ) . sprintf(
		'<div class="atf-range"><input type="range" class="atf-range__input"%s value="%s" data-atf-input>'
		. '<output class="atf-range__output" for="%s">%s</output></div>',
		atf_control_attributes( $field, $context ),
		esc_attr( (string) $current ),
		esc_attr( $context['id'] ),
		esc_html( (string) $current )
	);
}

/**
 * A file input.
 *
 * @since 0.1.0
 *
 * @param array $field   The field.
 * @param mixed $value   Its value.
 * @param array $context The render context.
 * @return string
 */
function atf_render_file( $field, $value, $context ) {
	$types    = isset( $field['filetypes'] ) && is_array( $field['filetypes'] ) ? $field['filetypes'] : array();
	$multiple = isset( $field['maxfiles'] ) && absint( $field['maxfiles'] ) > 1;

	$accept = '';

	if ( $types ) {
		$accept = sprintf(
			' accept="%s"',
			esc_attr(
				implode(
					',',
					array_map(
						static function ( $type ) {
							return '.' . ltrim( sanitize_key( $type ), '.' );
						},
						$types
					)
				)
			)
		);
	}

	// The name is the field id without `atf[…]`, because uploads arrive in
	// `$_FILES` where PHP's nested-array handling is famously awkward. A flat
	// name keeps `atf_handle_uploads()` readable.
	$name = 'atf_file_' . $field['id'] . ( $multiple ? '[]' : '' );

	$attributes = sprintf(
		' id="%s" name="%s"',
		esc_attr( $context['id'] ),
		esc_attr( $name )
	);

	if ( $field['required'] ) {
		$attributes .= ' required aria-required="true"';
	}

	if ( '' !== $context['describedby'] ) {
		$attributes .= sprintf( ' aria-describedby="%s"', esc_attr( $context['describedby'] ) );
	}

	$hint = '';

	if ( $types ) {
		$hint = sprintf(
			'<p class="atf-file__types">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: list of file extensions, 2: maximum size in megabytes. */
					__( 'Accepted: %1$s. Up to %2$s MB each.', 'allterrain-forms' ),
					implode( ', ', $types ),
					isset( $field['maxsize'] ) ? absint( $field['maxsize'] ) : 10
				)
			)
		);
	}

	return atf_render_label( $field, $context['id'] ) . sprintf(
		'<div class="atf-file"><input type="file" class="atf-file__input"%s%s%s data-atf-input>%s</div>',
		$attributes,
		$accept,
		$multiple ? ' multiple' : '',
		$hint
	);
}

/**
 * A signature pad.
 *
 * The canvas is drawn on by the bundle; the hidden input carries the resulting
 * data URI. With scripting off the canvas is inert, so a typed fallback is
 * offered instead -- a form that cannot be completed without JavaScript is a
 * form that excludes people, and "sign by typing your name" is what a paper
 * form would have accepted anyway.
 *
 * @since 0.1.0
 *
 * @param array $field   The field.
 * @param mixed $value   Its value.
 * @param array $context The render context.
 * @return string
 */
function atf_render_signature( $field, $value, $context ) {
	return atf_render_label( $field, $context['id'] ) . sprintf(
		'<div class="atf-signature" data-atf-signature>'
		. '<canvas class="atf-signature__pad" width="600" height="200" aria-label="%1$s" role="img"></canvas>'
		. '<input type="hidden" id="%2$s" name="%3$s" value="%4$s" data-atf-input>'
		. '<div class="atf-signature__actions">'
		. '<button type="button" class="atf-button atf-button--ghost" data-atf-signature-clear>%5$s</button>'
		. '</div>'
		. '<noscript><input type="text" class="atf-input" name="%6$s" placeholder="%7$s"></noscript>'
		. '</div>',
		esc_attr__( 'Signature pad', 'allterrain-forms' ),
		esc_attr( $context['id'] ),
		esc_attr( 'atf[' . $field['id'] . ']' ),
		esc_attr( (string) $value ),
		esc_html__( 'Clear', 'allterrain-forms' ),
		esc_attr( 'atf_typed[' . $field['id'] . ']' ),
		esc_attr__( 'Type your name to sign', 'allterrain-forms' )
	);
}

/**
 * A composite field: a name, an address, a date range.
 *
 * @since 0.1.0
 *
 * @param array $field   The field.
 * @param mixed $value   Its value.
 * @param array $context The render context.
 * @param array $parts   Part key => { label, type, autocomplete }.
 * @return string
 */
function atf_render_composite( $field, $value, $context, $parts ) {
	$enabled = isset( $field['parts'] ) && is_array( $field['parts'] ) ? $field['parts'] : array_keys( $parts );
	$value   = is_array( $value ) ? $value : array();

	$out  = '<fieldset class="atf-composite">';
	$out .= atf_render_label( $field, $context['id'], 'legend' );
	$out .= '<div class="atf-composite__parts">';

	foreach ( $parts as $key => $part ) {
		if ( ! in_array( $key, $enabled, true ) ) {
			continue;
		}

		$part_id = $context['id'] . '-' . $key;

		$out .= sprintf(
			'<div class="atf-composite__part atf-composite__part--%s">'
			. '<label class="atf-label atf-label--sub" for="%s">%s</label>'
			. '<input type="%s" class="atf-input" id="%s" name="%s" value="%s"%s%s data-atf-input>'
			. '</div>',
			esc_attr( $key ),
			esc_attr( $part_id ),
			esc_html( $part['label'] ),
			esc_attr( isset( $part['type'] ) ? $part['type'] : 'text' ),
			esc_attr( $part_id ),
			esc_attr( 'atf[' . $field['id'] . '][' . $key . ']' ),
			esc_attr( isset( $value[ $key ] ) ? (string) $value[ $key ] : '' ),
			// Required applies to the whole composite, and the server checks it
			// that way; marking every part required would refuse an address
			// with no second line.
			'',
			isset( $part['autocomplete'] ) ? sprintf( ' autocomplete="%s"', esc_attr( $part['autocomplete'] ) ) : ''
		);
	}

	return $out . '</div></fieldset>';
}

/**
 * The parts a name field can have.
 *
 * @since 0.1.0
 *
 * @return array<string, array>
 */
function atf_name_parts() {
	/**
	 * Filters the parts offered by the name field.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array> $parts Part key => { label, autocomplete }.
	 */
	return apply_filters(
		'atf_name_parts',
		array(
			'prefix' => array(
				'label'        => __( 'Title', 'allterrain-forms' ),
				'autocomplete' => 'honorific-prefix',
			),
			'first'  => array(
				'label'        => __( 'First name', 'allterrain-forms' ),
				'autocomplete' => 'given-name',
			),
			'middle' => array(
				'label'        => __( 'Middle name', 'allterrain-forms' ),
				'autocomplete' => 'additional-name',
			),
			'last'   => array(
				'label'        => __( 'Last name', 'allterrain-forms' ),
				'autocomplete' => 'family-name',
			),
			'suffix' => array(
				'label'        => __( 'Suffix', 'allterrain-forms' ),
				'autocomplete' => 'honorific-suffix',
			),
		)
	);
}

/**
 * The parts an address field can have.
 *
 * @since 0.1.0
 *
 * @return array<string, array>
 */
function atf_address_parts() {
	/**
	 * Filters the parts offered by the address field.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array> $parts Part key => { label, autocomplete }.
	 */
	return apply_filters(
		'atf_address_parts',
		array(
			'line1'    => array(
				'label'        => __( 'Address', 'allterrain-forms' ),
				'autocomplete' => 'address-line1',
			),
			'line2'    => array(
				'label'        => __( 'Address line 2', 'allterrain-forms' ),
				'autocomplete' => 'address-line2',
			),
			'city'     => array(
				'label'        => __( 'Town or city', 'allterrain-forms' ),
				'autocomplete' => 'address-level2',
			),
			'region'   => array(
				'label'        => __( 'County or state', 'allterrain-forms' ),
				'autocomplete' => 'address-level1',
			),
			'postcode' => array(
				'label'        => __( 'Postcode', 'allterrain-forms' ),
				'autocomplete' => 'postal-code',
			),
			'country'  => array(
				'label'        => __( 'Country', 'allterrain-forms' ),
				'autocomplete' => 'country-name',
			),
		)
	);
}

/**
 * A country dropdown.
 *
 * @since 0.1.0
 *
 * @param array $field   The field.
 * @param mixed $value   Its value.
 * @param array $context The render context.
 * @return string
 */
function atf_render_country( $field, $value, $context ) {
	$options = sprintf(
		'<option value=""%s>%s</option>',
		$field['required'] ? ' disabled' : '',
		esc_html( '' !== $field['placeholder'] ? $field['placeholder'] : __( 'Choose a country', 'allterrain-forms' ) )
	);

	foreach ( atf_countries() as $code => $name ) {
		$options .= sprintf(
			'<option value="%s"%s>%s</option>',
			esc_attr( $code ),
			selected( (string) $value, (string) $code, false ),
			esc_html( $name )
		);
	}

	return atf_render_label( $field, $context['id'] ) . sprintf(
		'<select class="atf-input atf-select"%s data-atf-input>%s</select>',
		atf_control_attributes( $field, $context ),
		$options
	);
}

/**
 * A repeater.
 *
 * The first row is rendered server-side so the field is usable without
 * JavaScript -- one row, which is what most repeaters collect anyway. The
 * bundle clones the template for further rows.
 *
 * @since 0.1.0
 *
 * @param array $field   The field.
 * @param mixed $value   Its value.
 * @param array $context The render context.
 * @return string
 */
function atf_render_repeater( $field, $value, $context ) {
	$sub_fields = isset( $field['fields'] ) && is_array( $field['fields'] ) ? $field['fields'] : array();
	$rows       = is_array( $value ) && $value ? $value : array( array() );

	$out  = sprintf(
		'<div class="atf-repeater" data-atf-repeater="%s" data-atf-min="%d" data-atf-max="%d">',
		esc_attr( $field['id'] ),
		isset( $field['minRows'] ) ? absint( $field['minRows'] ) : 1,
		isset( $field['maxRows'] ) ? absint( $field['maxRows'] ) : 10
	);
	$out .= atf_render_label( $field, $context['id'] );
	$out .= '<div class="atf-repeater__rows">';

	foreach ( $rows as $index => $row ) {
		$out .= atf_render_repeater_row( $field, $sub_fields, is_array( $row ) ? $row : array(), $index, $context );
	}

	$out .= '</div>';

	$add_label = ! empty( $field['addLabel'] ) ? $field['addLabel'] : __( 'Add another', 'allterrain-forms' );

	$out .= sprintf(
		'<button type="button" class="atf-button atf-button--ghost atf-repeater__add" data-atf-repeater-add>%s</button>',
		esc_html( $add_label )
	);

	// The template row is inert markup with `__INDEX__` where the row number
	// goes. In a `<template>` so nothing in it is submitted, focusable, or
	// visible to a screen reader until it is cloned into place.
	$out .= sprintf(
		'<template data-atf-repeater-template>%s</template>',
		atf_render_repeater_row( $field, $sub_fields, array(), '__INDEX__', $context )
	);

	return $out . '</div>';
}

/**
 * One row of a repeater.
 *
 * @since 0.1.0
 *
 * @param array      $field      The repeater.
 * @param array[]    $sub_fields Its sub-fields.
 * @param array      $row        The row's values.
 * @param int|string $index      Row index, or `__INDEX__` for the template.
 * @param array      $context    The render context.
 * @return string
 */
function atf_render_repeater_row( $field, $sub_fields, $row, $index, $context ) {
	$out = '<div class="atf-repeater__row" data-atf-repeater-row>';

	foreach ( $sub_fields as $sub ) {
		$sub_id      = $context['id'] . '-' . $index . '-' . $sub['id'];
		$sub_context = array(
			'id'          => $sub_id,
			'instance'    => $context['instance'],
			'schema'      => $context['schema'],
			'values'      => array(),
			'error'       => '',
			'describedby' => '',
		);

		// The sub-field is rendered through the ordinary control renderer and
		// then its name rewritten to the nested form. Rendering it specially
		// instead would mean a second implementation of every control that can
		// appear inside a repeater, and they would drift.
		$control = atf_render_field_control(
			$sub,
			isset( $row[ $sub['id'] ] ) ? $row[ $sub['id'] ] : $sub['default'],
			$sub_context
		);

		// The rewrite matches the *prefix* of the name, not the whole
		// attribute, because a control does not always close the brackets
		// where a plain input does: checkboxes and multiselects append `[]`,
		// and the composites -- name, address, Likert -- append `[part]`.
		// Anchoring on the closing quote would leave all of those under the
		// sub-field's own name, where the rows collide with each other and
		// `atf_sanitize_repeater_value()` finds nothing it recognises.
		$control = str_replace(
			'name="' . esc_attr( 'atf[' . $sub['id'] . ']' ),
			'name="' . esc_attr( 'atf[' . $field['id'] . '][' . $index . '][' . $sub['id'] . ']' ),
			$control
		);

		$out .= sprintf(
			'<div class="atf-field atf-field--%s atf-repeater__field">%s</div>',
			esc_attr( $sub['width'] ),
			$control
		);
	}

	$out .= sprintf(
		'<button type="button" class="atf-repeater__remove" data-atf-repeater-remove aria-label="%s">&times;</button>',
		esc_attr__( 'Remove this row', 'allterrain-forms' )
	);

	return $out . '</div>';
}

/**
 * A calculated total.
 *
 * `readonly` rather than `disabled`, because a disabled input is not submitted
 * and the value would never reach the entry. The server recomputes it anyway,
 * so a tampered value is discarded -- but a missing one would look like a form
 * that forgot to collect its own total.
 *
 * @since 0.1.0
 *
 * @param array $field   The field.
 * @param mixed $value   Its value.
 * @param array $context The render context.
 * @return string
 */
function atf_render_total( $field, $value, $context ) {
	return atf_render_label( $field, $context['id'] ) . sprintf(
		'<div class="atf-total">%s<input type="text" class="atf-input atf-total__input" id="%s" name="%s" value="%s" readonly'
		. ' data-atf-total data-atf-formula="%s" data-atf-decimals="%d" data-atf-input></div>',
		! empty( $field['currency'] ) ? sprintf( '<span class="atf-total__currency" aria-hidden="true">%s</span>', esc_html( $field['currency'] ) ) : '',
		esc_attr( $context['id'] ),
		esc_attr( 'atf[' . $field['id'] . ']' ),
		esc_attr( is_scalar( $value ) ? (string) $value : '' ),
		esc_attr( isset( $field['formula'] ) ? $field['formula'] : '' ),
		isset( $field['decimals'] ) ? absint( $field['decimals'] ) : 2
	);
}

/**
 * The country list.
 *
 * ISO 3166-1 alpha-2, English names. A site wanting localised names or a subset
 * replaces the list through the filter rather than editing this array.
 *
 * @since 0.1.0
 *
 * @return array<string, string> Code => name.
 */
function atf_countries() {
	$countries = array(
		'AF' => 'Afghanistan',
		'AL' => 'Albania',
		'DZ' => 'Algeria',
		'AD' => 'Andorra',
		'AO' => 'Angola',
		'AG' => 'Antigua and Barbuda',
		'AR' => 'Argentina',
		'AM' => 'Armenia',
		'AU' => 'Australia',
		'AT' => 'Austria',
		'AZ' => 'Azerbaijan',
		'BS' => 'Bahamas',
		'BH' => 'Bahrain',
		'BD' => 'Bangladesh',
		'BB' => 'Barbados',
		'BY' => 'Belarus',
		'BE' => 'Belgium',
		'BZ' => 'Belize',
		'BJ' => 'Benin',
		'BT' => 'Bhutan',
		'BO' => 'Bolivia',
		'BA' => 'Bosnia and Herzegovina',
		'BW' => 'Botswana',
		'BR' => 'Brazil',
		'BN' => 'Brunei',
		'BG' => 'Bulgaria',
		'BF' => 'Burkina Faso',
		'BI' => 'Burundi',
		'KH' => 'Cambodia',
		'CM' => 'Cameroon',
		'CA' => 'Canada',
		'CV' => 'Cape Verde',
		'CF' => 'Central African Republic',
		'TD' => 'Chad',
		'CL' => 'Chile',
		'CN' => 'China',
		'CO' => 'Colombia',
		'KM' => 'Comoros',
		'CG' => 'Congo',
		'CD' => 'Congo (DRC)',
		'CR' => 'Costa Rica',
		'CI' => "Côte d'Ivoire",
		'HR' => 'Croatia',
		'CU' => 'Cuba',
		'CY' => 'Cyprus',
		'CZ' => 'Czechia',
		'DK' => 'Denmark',
		'DJ' => 'Djibouti',
		'DM' => 'Dominica',
		'DO' => 'Dominican Republic',
		'EC' => 'Ecuador',
		'EG' => 'Egypt',
		'SV' => 'El Salvador',
		'GQ' => 'Equatorial Guinea',
		'ER' => 'Eritrea',
		'EE' => 'Estonia',
		'SZ' => 'Eswatini',
		'ET' => 'Ethiopia',
		'FJ' => 'Fiji',
		'FI' => 'Finland',
		'FR' => 'France',
		'GA' => 'Gabon',
		'GM' => 'Gambia',
		'GE' => 'Georgia',
		'DE' => 'Germany',
		'GH' => 'Ghana',
		'GR' => 'Greece',
		'GD' => 'Grenada',
		'GT' => 'Guatemala',
		'GN' => 'Guinea',
		'GW' => 'Guinea-Bissau',
		'GY' => 'Guyana',
		'HT' => 'Haiti',
		'HN' => 'Honduras',
		'HU' => 'Hungary',
		'IS' => 'Iceland',
		'IN' => 'India',
		'ID' => 'Indonesia',
		'IR' => 'Iran',
		'IQ' => 'Iraq',
		'IE' => 'Ireland',
		'IL' => 'Israel',
		'IT' => 'Italy',
		'JM' => 'Jamaica',
		'JP' => 'Japan',
		'JO' => 'Jordan',
		'KZ' => 'Kazakhstan',
		'KE' => 'Kenya',
		'KI' => 'Kiribati',
		'KW' => 'Kuwait',
		'KG' => 'Kyrgyzstan',
		'LA' => 'Laos',
		'LV' => 'Latvia',
		'LB' => 'Lebanon',
		'LS' => 'Lesotho',
		'LR' => 'Liberia',
		'LY' => 'Libya',
		'LI' => 'Liechtenstein',
		'LT' => 'Lithuania',
		'LU' => 'Luxembourg',
		'MG' => 'Madagascar',
		'MW' => 'Malawi',
		'MY' => 'Malaysia',
		'MV' => 'Maldives',
		'ML' => 'Mali',
		'MT' => 'Malta',
		'MH' => 'Marshall Islands',
		'MR' => 'Mauritania',
		'MU' => 'Mauritius',
		'MX' => 'Mexico',
		'FM' => 'Micronesia',
		'MD' => 'Moldova',
		'MC' => 'Monaco',
		'MN' => 'Mongolia',
		'ME' => 'Montenegro',
		'MA' => 'Morocco',
		'MZ' => 'Mozambique',
		'MM' => 'Myanmar',
		'NA' => 'Namibia',
		'NR' => 'Nauru',
		'NP' => 'Nepal',
		'NL' => 'Netherlands',
		'NZ' => 'New Zealand',
		'NI' => 'Nicaragua',
		'NE' => 'Niger',
		'NG' => 'Nigeria',
		'KP' => 'North Korea',
		'MK' => 'North Macedonia',
		'NO' => 'Norway',
		'OM' => 'Oman',
		'PK' => 'Pakistan',
		'PW' => 'Palau',
		'PS' => 'Palestine',
		'PA' => 'Panama',
		'PG' => 'Papua New Guinea',
		'PY' => 'Paraguay',
		'PE' => 'Peru',
		'PH' => 'Philippines',
		'PL' => 'Poland',
		'PT' => 'Portugal',
		'QA' => 'Qatar',
		'RO' => 'Romania',
		'RU' => 'Russia',
		'RW' => 'Rwanda',
		'KN' => 'Saint Kitts and Nevis',
		'LC' => 'Saint Lucia',
		'VC' => 'Saint Vincent and the Grenadines',
		'WS' => 'Samoa',
		'SM' => 'San Marino',
		'ST' => 'São Tomé and Príncipe',
		'SA' => 'Saudi Arabia',
		'SN' => 'Senegal',
		'RS' => 'Serbia',
		'SC' => 'Seychelles',
		'SL' => 'Sierra Leone',
		'SG' => 'Singapore',
		'SK' => 'Slovakia',
		'SI' => 'Slovenia',
		'SB' => 'Solomon Islands',
		'SO' => 'Somalia',
		'ZA' => 'South Africa',
		'KR' => 'South Korea',
		'SS' => 'South Sudan',
		'ES' => 'Spain',
		'LK' => 'Sri Lanka',
		'SD' => 'Sudan',
		'SR' => 'Suriname',
		'SE' => 'Sweden',
		'CH' => 'Switzerland',
		'SY' => 'Syria',
		'TW' => 'Taiwan',
		'TJ' => 'Tajikistan',
		'TZ' => 'Tanzania',
		'TH' => 'Thailand',
		'TL' => 'Timor-Leste',
		'TG' => 'Togo',
		'TO' => 'Tonga',
		'TT' => 'Trinidad and Tobago',
		'TN' => 'Tunisia',
		'TR' => 'Türkiye',
		'TM' => 'Turkmenistan',
		'TV' => 'Tuvalu',
		'UG' => 'Uganda',
		'UA' => 'Ukraine',
		'AE' => 'United Arab Emirates',
		'GB' => 'United Kingdom',
		'US' => 'United States',
		'UY' => 'Uruguay',
		'UZ' => 'Uzbekistan',
		'VU' => 'Vanuatu',
		'VA' => 'Vatican City',
		'VE' => 'Venezuela',
		'VN' => 'Vietnam',
		'YE' => 'Yemen',
		'ZM' => 'Zambia',
		'ZW' => 'Zimbabwe',
	);

	/**
	 * Filters the country list.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $countries ISO 3166-1 alpha-2 code => name.
	 */
	return apply_filters( 'atf_countries', $countries );
}

<?php
/**
 * The colour field.
 *
 * It used to render as `<input type="color" class="atf-input">`, which the
 * stylesheet stretched into a full-width bar that read as a broken checkbox,
 * and which could not be empty -- an optional colour nobody touched posted
 * `#000000`. These tests pin what replaced it: a hex text box that is the one
 * named input, a chip beside it, and suggested colours that are cleaned before
 * they reach a style attribute.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * The colour field's renderer and normaliser.
 *
 * @group allterrain-forms
 */
class ALLTFO_Test_Color_Field extends WP_UnitTestCase {

	/**
	 * Renders a one-field colour form.
	 *
	 * @param array $field Overrides for the field.
	 * @return string The rendered HTML.
	 */
	private function render_color( $field = array() ) {
		$form_id = alltfo_test_form(
			array(
				'fields' => array(
					array_merge(
						array(
							'id'    => 'f1',
							'type'  => 'color',
							'label' => 'Favourite colour',
						),
						$field
					),
				),
			)
		);

		return alltfo_render_form( $form_id );
	}

	/**
	 * The field is a text box, never the native colour input.
	 *
	 * @covers ::alltfo_render_color
	 */
	public function test_renders_a_text_box_not_a_native_swatch() {
		$html = $this->render_color();

		$this->assertStringNotContainsString( '<input type="color"', $html );
		$this->assertMatchesRegularExpression( '/<input type="text" class="atf-input atf-color__input"[^>]*name="atf\[f1\]"/', $html );
		$this->assertStringContainsString( 'data-atf-color', $html );
	}

	/**
	 * An untouched optional colour is empty, not black.
	 *
	 * @covers ::alltfo_render_color
	 */
	public function test_empty_value_stays_empty() {
		$html = $this->render_color();

		$this->assertMatchesRegularExpression( '/atf-color__input"[^>]*value=""/', $html );
		$this->assertStringContainsString( 'atf-color__chip is-empty', $html );
	}

	/**
	 * A default shows in the box and paints the chip.
	 *
	 * @covers ::alltfo_render_color
	 */
	public function test_default_paints_the_chip() {
		$html = $this->render_color( array( 'default' => '#36F' ) );

		$this->assertStringContainsString( 'value="#3366ff"', $html );
		$this->assertStringContainsString( 'style="background-color:#3366ff"', $html );
	}

	/**
	 * The box accepts what the picker and the server accept, and no more.
	 *
	 * @covers ::alltfo_render_color
	 */
	public function test_box_carries_a_hex_pattern() {
		$html = $this->render_color();

		$this->assertStringContainsString( 'pattern="#?([0-9a-fA-F]{3}){1,2}"', $html );
		$this->assertStringContainsString( 'maxlength="7"', $html );
	}

	/**
	 * Suggested colours are normalised, de-duplicated, and stripped of junk.
	 *
	 * @covers ::alltfo_render_color
	 */
	public function test_swatches_are_cleaned() {
		$html = $this->render_color(
			array(
				'swatches' => array( '#1D4ED8', 'f59e0b', '#1d4ed8', 'red;background:url(x)', '"><script>' ),
			)
		);

		$this->assertStringContainsString( 'data-atf-swatches="#1d4ed8,#f59e0b"', $html );
		$this->assertStringNotContainsString( 'url(x)', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	/**
	 * No swatches, no attribute.
	 *
	 * @covers ::alltfo_render_color
	 */
	public function test_no_swatches_no_attribute() {
		$this->assertStringNotContainsString( 'data-atf-swatches', $this->render_color() );
	}

	/**
	 * The swatches setting survives normalisation as a list.
	 *
	 * @covers ::alltfo_normalize_field
	 */
	public function test_swatches_setting_is_declared() {
		$definition = alltfo_get_field_type( 'color' );

		$this->assertContains( 'swatches', $definition['supports'] );
		$this->assertSame( array(), $definition['settings']['swatches'] );
	}

	/**
	 * Typed colours normalise to one stored shape.
	 *
	 * @dataProvider data_hex_colors
	 * @covers ::alltfo_normalize_hex_color
	 *
	 * @param string $raw      What was typed.
	 * @param string $expected What is stored.
	 */
	public function test_normalize_hex_color( $raw, $expected ) {
		$this->assertSame( $expected, alltfo_normalize_hex_color( $raw ) );
	}

	/**
	 * Typed colours and what they become.
	 *
	 * Kept in step with `normalizeHex()`'s cases in
	 * `tests/vitest/color-picker.test.ts`.
	 *
	 * @return array[]
	 */
	public function data_hex_colors() {
		return array(
			'six digits'       => array( '#3366ff', '#3366ff' ),
			'upper case'       => array( '#3366FF', '#3366ff' ),
			'no hash'          => array( '3366ff', '#3366ff' ),
			'three digits'     => array( '#36f', '#3366ff' ),
			'three, no hash'   => array( 'ABC', '#aabbcc' ),
			'padded'           => array( '  #abcdef ', '#abcdef' ),
			'empty'            => array( '', '' ),
			'a name'           => array( 'red', '' ),
			'too long'         => array( '#1234567', '' ),
			'four digits'      => array( '#1234', '' ),
			'not hex'          => array( '#ggg', '' ),
			'an injection try' => array( '#fff;background:url(x)', '' ),
		);
	}

	/**
	 * A submitted colour is stored normalised.
	 *
	 * @covers ::alltfo_sanitize_field_value
	 */
	public function test_submitted_value_is_normalised() {
		$field = array(
			'id'   => 'f1',
			'type' => 'color',
		);

		$this->assertSame( '#aabbcc', alltfo_sanitize_field_value( 'ABC', $field ) );
		$this->assertSame( '', alltfo_sanitize_field_value( 'not a colour', $field ) );
	}
}

<?php
/**
 * Validation presets, server side.
 *
 * Every case comes from `tests/fixtures/validation-cases.json`, which
 * `tests/vitest/validation.test.ts` reads too. The preset tables in
 * `includes/validation.php` and `src/shared/validation.ts` are twins and have
 * to agree: the browser tells the visitor "that does not look like an email
 * address" as they type, and this side decides whether the submission is
 * accepted. A disagreement rejects an answer the form itself said was fine.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * Validation presets, server side.
 *
 * @group allterrain-forms
 */
class ALLTFO_Test_Validation_Presets extends WP_UnitTestCase {

	/**
	 * The conformance table.
	 *
	 * @dataProvider data_presets
	 * @covers ::alltfo_validate_preset
	 *
	 * @param string $preset The preset slug.
	 * @param string $value  The value as typed.
	 * @param bool   $valid  Whether the preset must accept it.
	 */
	public function test_preset_verdicts_match_the_fixture( $preset, $value, $valid ) {
		$error = alltfo_validate_preset( array( 'messages' => array() ), $preset, $value );

		$this->assertSame(
			$valid,
			'' === $error,
			sprintf( '%s( %s )', $preset, var_export( $value, true ) )
		);
	}

	/**
	 * Cases from the shared fixture.
	 *
	 * @return array
	 */
	public function data_presets() {
		$cases = array();

		foreach ( alltfo_test_fixture( 'validation-cases' )['presets'] as $index => $case ) {
			$cases[ $index . ': ' . $case['preset'] . ' ' . $case['value'] ] = array(
				$case['preset'],
				$case['value'],
				$case['valid'],
			);
		}

		return $cases;
	}

	/**
	 * Every fixture slug is a preset this build knows.
	 *
	 * A typo in the fixture would otherwise pass both suites: an unknown slug
	 * is deliberately not an error at validation time.
	 *
	 * @covers ::alltfo_validation_presets
	 */
	public function test_fixture_slugs_all_exist() {
		$presets = alltfo_validation_presets();

		foreach ( alltfo_test_fixture( 'validation-cases' )['presets'] as $case ) {
			$this->assertArrayHasKey( $case['preset'], $presets );
		}
	}

	/**
	 * Every preset has at least one fixture case, so none ships untested.
	 *
	 * @covers ::alltfo_validation_presets
	 */
	public function test_every_preset_is_in_the_fixture() {
		$tested = wp_list_pluck( alltfo_test_fixture( 'validation-cases' )['presets'], 'preset' );

		foreach ( array_keys( alltfo_validation_presets() ) as $slug ) {
			$this->assertContains( $slug, $tested, "Preset '{$slug}' has no conformance case." );
		}
	}

	/**
	 * A slug from a newer version passes rather than rejecting everything.
	 *
	 * @covers ::alltfo_validate_preset
	 */
	public function test_unknown_preset_passes() {
		$this->assertSame( '', alltfo_validate_preset( array( 'messages' => array() ), 'from_the_future', 'anything' ) );
	}

	/**
	 * The preset is enforced through the ordinary field validation path.
	 *
	 * @covers ::alltfo_validate_field
	 */
	public function test_validate_field_applies_the_preset() {
		$field = array(
			'id'         => 'f1',
			'type'       => 'text',
			'label'      => 'Work email',
			'required'   => false,
			'choices'    => array(),
			'messages'   => array(),
			'validation' => 'email',
		);

		$this->assertSame( '', alltfo_validate_field( $field, 'jane@example.com', array() ) );
		$this->assertNotSame( '', alltfo_validate_field( $field, 'not-an-email', array() ) );
	}

	/**
	 * The sentinel `custom` defers to the field's own pattern.
	 *
	 * @covers ::alltfo_validate_field
	 */
	public function test_custom_defers_to_the_pattern() {
		$field = array(
			'id'         => 'f1',
			'type'       => 'text',
			'label'      => 'Code',
			'required'   => false,
			'choices'    => array(),
			'messages'   => array(),
			'validation' => 'custom',
			'pattern'    => '^AT-[0-9]+$',
		);

		$this->assertSame( '', alltfo_validate_field( $field, 'AT-42', array() ) );
		$this->assertNotSame( '', alltfo_validate_field( $field, 'GF-42', array() ) );
	}

	/**
	 * The field's own invalid message beats the preset's default.
	 *
	 * @covers ::alltfo_validate_preset
	 */
	public function test_field_message_wins() {
		$field = array( 'messages' => array( 'invalid' => 'Work addresses only.' ) );

		$this->assertSame( 'Work addresses only.', alltfo_validate_preset( $field, 'email', 'nope' ) );
	}

	/**
	 * The Luhn checksum, on its own.
	 *
	 * @covers ::alltfo_luhn_passes
	 */
	public function test_luhn() {
		$this->assertTrue( alltfo_luhn_passes( '4242 4242 4242 4242' ) );
		$this->assertFalse( alltfo_luhn_passes( '4242 4242 4242 4241' ) );
		$this->assertFalse( alltfo_luhn_passes( '42' ) );
	}

	/**
	 * The schema keeps the preset slug and rebuilds the recipe from a whitelist.
	 *
	 * @covers ::alltfo_normalize_validation_recipe
	 */
	public function test_schema_keeps_validation_and_normalises_the_recipe() {
		$schema = alltfo_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'               => 'f1',
						'type'             => 'text',
						'label'            => 'Code',
						'validation'       => 'custom',
						'pattern'          => '^AT-[0-9]+$',
						'validationRecipe' => wp_json_encode(
							array(
								'mode'   => 'blocks',
								'starts' => 'AT-',
								'chars'  => array( 'numbers', 'not-a-real-group' ),
								'tests'  => array( 'AT-1', 'GF-2' ),
								'sneaky' => array( 'nested' => 'structure' ),
							)
						),
					),
				),
			)
		);

		$field = $schema['fields'][0];

		$this->assertSame( 'custom', $field['validation'] );

		$recipe = json_decode( $field['validationRecipe'], true );

		$this->assertSame( 'AT-', $recipe['starts'] );
		$this->assertSame( array( 'numbers' ), $recipe['chars'] );
		$this->assertSame( array( 'AT-1', 'GF-2' ), $recipe['tests'] );
		$this->assertArrayNotHasKey( 'sneaky', $recipe );
	}

	/**
	 * A recipe that is not JSON is dropped rather than stored.
	 *
	 * @covers ::alltfo_normalize_validation_recipe
	 */
	public function test_garbage_recipe_is_dropped() {
		$this->assertSame( '', alltfo_normalize_validation_recipe( 'not json' ) );
	}
}

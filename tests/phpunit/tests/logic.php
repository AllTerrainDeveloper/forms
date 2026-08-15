<?php
/**
 * Conditional logic, server side.
 *
 * Every case comes from `tests/fixtures/logic-cases.json`, which
 * `tests/vitest/logic.test.ts` reads too. The two engines are twins and have to
 * agree: the browser hides and shows fields as the visitor types, the server
 * decides which fields were actually required, and a disagreement shows somebody
 * a form they cannot submit with an error about a field they cannot see.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

class ATF_Test_Logic extends WP_UnitTestCase {

	/**
	 * The comparison table.
	 *
	 * @dataProvider data_compare
	 * @covers ::atf_logic_compare
	 *
	 * @param string $operator The operator.
	 * @param mixed  $actual   The submitted value.
	 * @param string $expected The rule's value.
	 * @param bool   $result   What the comparison must return.
	 */
	public function test_compare( $operator, $actual, $expected, $result ) {
		$this->assertSame(
			$result,
			atf_logic_compare( $operator, $actual, $expected ),
			sprintf( '%s( %s, %s )', $operator, var_export( $actual, true ), var_export( $expected, true ) )
		);
	}

	/**
	 * Cases from the shared fixture.
	 *
	 * @return array[]
	 */
	public function data_compare() {
		$cases = array();

		foreach ( atf_test_fixture( 'logic-cases' )['compare'] as $index => $case ) {
			$cases[ 'compare ' . $index ] = array(
				$case['operator'],
				$case['actual'],
				$case['expected'],
				$case['result'],
			);
		}

		return $cases;
	}

	/**
	 * One rule against one value, including the multi-value readings.
	 *
	 * @dataProvider data_rules
	 * @covers ::atf_logic_rule_passes
	 *
	 * @param array $rule   The rule.
	 * @param mixed $value  The value.
	 * @param bool  $result What it must return.
	 */
	public function test_rule_passes( $rule, $value, $result ) {
		$this->assertSame( $result, atf_logic_rule_passes( $rule, $value ) );
	}

	/**
	 * Cases from the shared fixture.
	 *
	 * @return array[]
	 */
	public function data_rules() {
		$cases = array();

		foreach ( atf_test_fixture( 'logic-cases' )['rule'] as $index => $case ) {
			$cases[ 'rule ' . $index ] = array( $case['rule'], $case['value'], $case['result'] );
		}

		return $cases;
	}

	/**
	 * Visibility across a whole schema, including chained logic.
	 *
	 * @dataProvider data_visibility
	 * @covers ::atf_visible_fields
	 *
	 * @param array      $fields  The schema's fields.
	 * @param array      $values  Submitted values.
	 * @param array|null $visible Expected visibility, or null when the case only
	 *                            asserts that resolution terminates.
	 */
	public function test_visible_fields( $fields, $values, $visible ) {
		$result = atf_visible_fields( array( 'fields' => $fields ), $values );

		if ( null === $visible ) {
			// A cyclic form has no correct answer. The requirement is that the
			// resolver stops rather than spinning, and returns a verdict per
			// field either way.
			$this->assertCount( count( $fields ), $result );

			return;
		}

		$this->assertSame( $visible, $result );
	}

	/**
	 * Cases from the shared fixture.
	 *
	 * @return array[]
	 */
	public function data_visibility() {
		$cases = array();

		foreach ( atf_test_fixture( 'logic-cases' )['visibility'] as $index => $case ) {
			$cases[ 'visibility ' . $index ] = array(
				$case['fields'],
				$case['values'],
				isset( $case['terminates'] ) ? null : $case['visible'],
			);
		}

		return $cases;
	}

	/**
	 * A disabled logic block never hides anything.
	 *
	 * @covers ::atf_logic_passes
	 */
	public function test_disabled_logic_always_passes() {
		$logic = array(
			'enabled' => false,
			'action'  => 'show',
			'match'   => 'all',
			'rules'   => array(
				array(
					'field'    => 'f1',
					'operator' => 'is',
					'value'    => 'never',
				),
			),
		);

		$this->assertTrue( atf_logic_passes( $logic, array() ) );
	}

	/**
	 * A hide action inverts the condition.
	 *
	 * @covers ::atf_logic_passes
	 */
	public function test_hide_inverts() {
		$logic = array(
			'enabled' => true,
			'action'  => 'hide',
			'match'   => 'all',
			'rules'   => array(
				array(
					'field'    => 'f1',
					'operator' => 'is',
					'value'    => 'yes',
				),
			),
		);

		$this->assertFalse( atf_logic_passes( $logic, array( 'f1' => 'yes' ) ) );
		$this->assertTrue( atf_logic_passes( $logic, array( 'f1' => 'no' ) ) );
	}

	/**
	 * An unrecognised operator falls back to `is` rather than being dropped.
	 *
	 * Dropping the rule would silently widen the condition, which is the failure
	 * that shows a field to everybody when it was meant for one answer.
	 *
	 * @covers ::atf_normalize_operator
	 */
	public function test_unknown_operator_narrows_rather_than_widens() {
		$this->assertSame( 'is', atf_normalize_operator( 'definitely_not_an_operator' ) );
		$this->assertSame( 'is', atf_normalize_operator( array( 'nonsense' ) ) );
	}

	/**
	 * A required field hidden by logic is not validated.
	 *
	 * The single most important interaction in the plugin: without it, a form
	 * refuses to submit over a question the visitor was never shown.
	 *
	 * @covers ::atf_validate_submission
	 */
	public function test_hidden_required_field_is_not_required() {
		$schema = atf_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'   => 'f1',
						'type' => 'radio',
						'choices' => array(
							array( 'label' => 'Yes', 'value' => 'yes' ),
							array( 'label' => 'No', 'value' => 'no' ),
						),
					),
					array(
						'id'       => 'f2',
						'type'     => 'text',
						'label'    => 'Why',
						'required' => true,
						'logic'    => array(
							'enabled' => true,
							'action'  => 'show',
							'match'   => 'all',
							'rules'   => array(
								array(
									'field'    => 'f1',
									'operator' => 'is',
									'value'    => 'yes',
								),
							),
						),
					),
				),
			)
		);

		// Hidden: the empty required field must not be an error.
		$this->assertSame(
			array(),
			atf_validate_submission( $schema, array( 'f1' => 'no', 'f2' => '' ) )
		);

		// Shown: it must be.
		$errors = atf_validate_submission( $schema, array( 'f1' => 'yes', 'f2' => '' ) );

		$this->assertArrayHasKey( 'f2', $errors );
	}
}

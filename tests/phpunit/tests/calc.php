<?php
/**
 * Calculations, server side.
 *
 * Every case comes from `tests/fixtures/calc-cases.json`, which
 * `tests/vitest/calc.test.ts` reads too. The browser shows a running total as
 * the visitor types and the server recomputes it on submit; a disagreement means
 * the number somebody was shown is not the number that was stored, which on an
 * order form is a charge dispute.
 *
 * The security tests here are not decoration. The evaluator exists precisely so
 * that a formula — author-supplied, stored, and evaluated on every submission —
 * cannot become code execution.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

class ATF_Test_Calc extends WP_UnitTestCase {

	/**
	 * The shared conformance table.
	 *
	 * @dataProvider data_formulas
	 * @covers ::atf_calculate
	 *
	 * @param string     $formula The formula.
	 * @param array      $values  Field values.
	 * @param array      $fields  The schema's fields, for choice prices.
	 * @param float|null $result  What it must evaluate to.
	 */
	public function test_calculate( $formula, $values, $fields, $result ) {
		$schema = $fields ? array( 'fields' => $fields ) : array();
		$got    = atf_calculate( $formula, $values, $schema );

		if ( null === $result ) {
			$this->assertNull( $got, sprintf( 'Expected "%s" to be refused.', $formula ) );

			return;
		}

		$this->assertNotNull( $got, sprintf( 'Expected "%s" to evaluate.', $formula ) );
		$this->assertEqualsWithDelta( $result, $got, 0.000000001, $formula );
	}

	/**
	 * Cases from the shared fixture.
	 *
	 * @return array[]
	 */
	public function data_formulas() {
		$cases = array();

		foreach ( atf_test_fixture( 'calc-cases' )['cases'] as $index => $case ) {
			$cases[ 'formula ' . $index . ': ' . $case['formula'] ] = array(
				$case['formula'],
				$case['values'],
				isset( $case['fields'] ) ? $case['fields'] : array(),
				$case['result'],
			);
		}

		return $cases;
	}

	/**
	 * Nothing that is not arithmetic evaluates.
	 *
	 * @dataProvider data_attacks
	 * @covers ::atf_calculate
	 *
	 * @param string $formula A formula that must be refused.
	 */
	public function test_refuses_code( $formula ) {
		$this->assertNull( atf_calculate( $formula, array() ) );
	}

	/**
	 * The shapes an injection attempt takes.
	 *
	 * @return array[]
	 */
	public function data_attacks() {
		$attacks = array(
			'phpinfo()',
			'system( "ls" )',
			'exec( "ls" )',
			'`ls`',
			'$GLOBALS',
			'file_get_contents( "/etc/passwd" )',
			'eval( "1" )',
			'1; DROP TABLE wp_posts',
			'include "evil.php"',
			'shell_exec( "id" )',
			'<?php echo 1; ?>',
			'{$x}',
		);

		$cases = array();

		foreach ( $attacks as $attack ) {
			$cases[ $attack ] = array( $attack );
		}

		return $cases;
	}

	/**
	 * A formula long enough to be a denial of service is refused outright.
	 *
	 * @covers ::atf_calculate
	 */
	public function test_refuses_enormous_formula() {
		$this->assertNull( atf_calculate( str_repeat( '1+', 1200 ) . '1', array() ) );
	}

	/**
	 * Only whitelisted functions exist.
	 *
	 * @covers ::atf_calc_functions
	 */
	public function test_function_whitelist_is_pure() {
		$allowed = array_keys( atf_calc_functions() );

		sort( $allowed );

		$this->assertSame(
			array( 'abs', 'avg', 'ceil', 'floor', 'max', 'min', 'pow', 'round', 'sqrt', 'sum' ),
			$allowed,
			'A function was added to the calculation whitelist. Every one of them must be pure and numeric.'
		);
	}

	/**
	 * Calculated fields are filled in across a schema, in order.
	 *
	 * @covers ::atf_apply_calculations
	 */
	public function test_apply_calculations_chains() {
		$schema = atf_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'   => 'f1',
						'type' => 'number',
					),
					array(
						'id'      => 'sub',
						'type'    => 'total',
						'formula' => '{f1} * 2',
					),
					array(
						'id'      => 'vat',
						'type'    => 'total',
						'formula' => '{sub} * 0.2',
					),
					array(
						'id'      => 'all',
						'type'    => 'total',
						'formula' => '{sub} + {vat}',
					),
				),
			)
		);

		$values = atf_apply_calculations( $schema, array( 'f1' => 100 ) );

		$this->assertEquals( 200, $values['sub'] );
		$this->assertEquals( 40, $values['vat'] );
		$this->assertEquals( 240, $values['all'] );
	}

	/**
	 * A choice's price is what it contributes to a total.
	 *
	 * @covers ::atf_calc_numeric_value
	 */
	public function test_choice_price_feeds_the_total() {
		$schema = atf_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'      => 'f1',
						'type'    => 'select',
						'choices' => array(
							array(
								'label' => 'Small',
								'value' => 'small',
								'price' => 20,
							),
							array(
								'label' => 'Large',
								'value' => 'large',
								'price' => 50,
							),
						),
					),
					array(
						'id'   => 'f2',
						'type' => 'number',
					),
					array(
						'id'      => 'total',
						'type'    => 'total',
						'formula' => '{f1} * {f2}',
					),
				),
			)
		);

		$values = atf_apply_calculations(
			$schema,
			array(
				'f1' => 'large',
				'f2' => 3,
			)
		);

		$this->assertEquals( 150, $values['total'] );
	}

	/**
	 * The server's total wins over anything the browser posted.
	 *
	 * A client that submits a total of 1p for a 50-pound order must be ignored.
	 *
	 * @covers ::atf_apply_calculations
	 */
	public function test_client_total_is_overwritten() {
		$schema = atf_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'   => 'qty',
						'type' => 'number',
					),
					array(
						'id'      => 'total',
						'type'    => 'total',
						'formula' => '{qty} * 25',
					),
				),
			)
		);

		$values = atf_apply_calculations(
			$schema,
			array(
				'qty'   => 2,
				'total' => 0.01,
			)
		);

		$this->assertEquals( 50, $values['total'] );
	}
}

<?php
/**
 * Schema normalisation.
 *
 * `atf_normalize_schema()` is the single door everything untrusted comes through
 * — the builder, an import, a template — and everything downstream assumes what
 * it guarantees. These tests pin those guarantees.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

class ATF_Test_Schema extends WP_UnitTestCase {

	/**
	 * Rubbish in gives a valid schema out rather than an exception.
	 *
	 * @dataProvider data_rubbish
	 * @covers ::atf_normalize_schema
	 *
	 * @param mixed $input Something that is not a schema.
	 */
	public function test_normalises_anything( $input ) {
		$schema = atf_normalize_schema( $input );

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'fields', $schema );
		$this->assertArrayHasKey( 'settings', $schema );
		$this->assertArrayHasKey( 'notifications', $schema );
		$this->assertArrayHasKey( 'confirmations', $schema );
		$this->assertIsArray( $schema['fields'] );
	}

	/**
	 * Things that are not schemas.
	 *
	 * @return array[]
	 */
	public function data_rubbish() {
		return array(
			'null'          => array( null ),
			'false'         => array( false ),
			'zero'          => array( 0 ),
			'empty string'  => array( '' ),
			'a word'        => array( 'nonsense' ),
			'broken json'   => array( '{"fields": [' ),
			'json scalar'   => array( '42' ),
			'a list'        => array( array( 1, 2, 3 ) ),
			'nested junk'   => array( array( 'fields' => 'not a list' ) ),
			'object fields' => array( array( 'fields' => array( 'nope', 5, null ) ) ),
		);
	}

	/**
	 * Every field gets a unique id, even when the input has none or repeats one.
	 *
	 * Field ids are what logic, calculations and merge tags all reference, so a
	 * duplicate makes every one of them address the wrong field.
	 *
	 * @covers ::atf_normalize_schema
	 * @covers ::atf_generate_field_id
	 */
	public function test_field_ids_are_unique() {
		$schema = atf_normalize_schema(
			array(
				'fields' => array(
					array( 'type' => 'text' ),
					array(
						'id'   => 'f1',
						'type' => 'text',
					),
					array(
						'id'   => 'f1',
						'type' => 'email',
					),
					array( 'type' => 'textarea' ),
				),
			)
		);

		$ids = wp_list_pluck( $schema['fields'], 'id' );

		$this->assertCount( 4, $ids );
		$this->assertSame( $ids, array_unique( $ids ), 'Field ids must be unique.' );

		foreach ( $ids as $id ) {
			$this->assertNotSame( '', $id );
		}
	}

	/**
	 * A field with no type is dropped; there is nothing to render.
	 *
	 * @covers ::atf_normalize_field
	 */
	public function test_typeless_fields_are_dropped() {
		$schema = atf_normalize_schema(
			array(
				'fields' => array(
					array( 'label' => 'No type here' ),
					array(
						'id'   => 'f2',
						'type' => 'text',
					),
				),
			)
		);

		$this->assertCount( 1, $schema['fields'] );
		$this->assertSame( 'text', $schema['fields'][0]['type'] );
	}

	/**
	 * A field's type-specific settings are filled in from its registration.
	 *
	 * @covers ::atf_normalize_field
	 */
	public function test_type_settings_are_merged() {
		$schema = atf_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'   => 'f1',
						'type' => 'textarea',
					),
					array(
						'id'   => 'f2',
						'type' => 'rating',
					),
				),
			)
		);

		$this->assertSame( 5, $schema['fields'][0]['rows'] );
		$this->assertSame( 5, $schema['fields'][1]['max'] );
	}

	/**
	 * A choice with a label and no value takes the label as its value.
	 *
	 * @covers ::atf_normalize_choices
	 */
	public function test_choices_normalise() {
		$schema = atf_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'      => 'f1',
						'type'    => 'select',
						'choices' => array(
							'Just a string',
							array( 'label' => 'Label only' ),
							array(
								'label' => 'Both',
								'value' => 'both-value',
							),
							array( 'nothing' => 'useful' ),
						),
					),
				),
			)
		);

		$choices = $schema['fields'][0]['choices'];

		$this->assertCount( 3, $choices, 'A choice with neither a label nor a value is dropped.' );
		$this->assertSame( 'Just a string', $choices[0]['value'] );
		$this->assertSame( 'Label only', $choices[1]['value'] );
		$this->assertSame( 'both-value', $choices[2]['value'] );
	}

	/**
	 * Settings merge without losing their siblings.
	 *
	 * Sending only `spam.honeypot` must not wipe `spam.timeTrap`.
	 *
	 * @covers ::atf_normalize_settings
	 */
	public function test_partial_settings_keep_siblings() {
		$schema = atf_normalize_schema(
			array(
				'settings' => array(
					'spam' => array( 'honeypot' => false ),
				),
			)
		);

		$this->assertFalse( $schema['settings']['spam']['honeypot'] );
		$this->assertSame( 3, $schema['settings']['spam']['timeTrap'], 'A sibling setting was lost in the merge.' );
	}

	/**
	 * An HTML block is filtered, so editing a form is not a way to run script.
	 *
	 * @covers ::atf_normalize_field
	 */
	public function test_html_block_is_filtered() {
		$schema = atf_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'      => 'f1',
						'type'    => 'html',
						'content' => '<p>Fine</p><script>alert(1)</script>',
					),
				),
			)
		);

		$this->assertStringContainsString( 'Fine', $schema['fields'][0]['content'] );
		$this->assertStringNotContainsString( '<script', $schema['fields'][0]['content'] );
	}

	/**
	 * A repeater's sub-fields go through the same normaliser.
	 *
	 * @covers ::atf_normalize_field
	 */
	public function test_repeater_subfields_normalise() {
		$schema = atf_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'     => 'f1',
						'type'   => 'repeater',
						'fields' => array(
							array(
								'id'   => 'role',
								'type' => 'text',
							),
							array( 'type' => 'number' ),
							'rubbish',
						),
					),
				),
			)
		);

		$subs = $schema['fields'][0]['fields'];

		$this->assertCount( 2, $subs );
		$this->assertSame( 'role', $subs[0]['id'] );
		$this->assertNotSame( '', $subs[1]['id'] );
	}

	/**
	 * A schema survives a round trip through storage unchanged.
	 *
	 * @covers ::atf_save_form_schema
	 * @covers ::atf_get_form_schema
	 */
	public function test_schema_round_trips() {
		$form_id = atf_test_form(
			array(
				'fields' => array(
					array(
						'id'       => 'f1',
						'type'     => 'text',
						'label'    => "Quotes ' and \" and \\ backslash",
						'required' => true,
					),
					array(
						'id'      => 'f2',
						'type'    => 'select',
						'choices' => array(
							array(
								'label' => 'Ünïcödé — em dash',
								'value' => 'a',
							),
						),
					),
				),
			)
		);

		$stored = atf_get_form_schema( $form_id );

		$this->assertSame( "Quotes ' and \" and \\ backslash", $stored['fields'][0]['label'] );
		$this->assertSame( 'Ünïcödé — em dash', $stored['fields'][1]['choices'][0]['label'] );
		$this->assertTrue( $stored['fields'][0]['required'] );
	}

	/**
	 * Pages split at each page break, and there is always at least one.
	 *
	 * @covers ::atf_schema_pages
	 * @covers ::atf_is_multi_page
	 */
	public function test_pages_split_at_breaks() {
		$single = atf_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'   => 'f1',
						'type' => 'text',
					),
				),
			)
		);

		$this->assertCount( 1, atf_schema_pages( $single ) );
		$this->assertFalse( atf_is_multi_page( $single ) );

		$multi = atf_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'   => 'f1',
						'type' => 'text',
					),
					array(
						'id'   => 'b1',
						'type' => 'page_break',
					),
					array(
						'id'   => 'f2',
						'type' => 'text',
					),
					array(
						'id'   => 'b2',
						'type' => 'page_break',
					),
					array(
						'id'   => 'f3',
						'type' => 'text',
					),
				),
			)
		);

		$pages = atf_schema_pages( $multi );

		$this->assertCount( 3, $pages );
		$this->assertTrue( atf_is_multi_page( $multi ) );
		$this->assertSame( 'f1', $pages[0]['fields'][0]['id'] );
		$this->assertSame( 'b1', $pages[0]['break']['id'], 'A break belongs to the page it closes.' );
		$this->assertNull( $pages[2]['break'] );
	}

	/**
	 * An empty schema still has a page, so nothing has to special-case it.
	 *
	 * @covers ::atf_schema_pages
	 */
	public function test_empty_schema_has_one_page() {
		$this->assertCount( 1, atf_schema_pages( atf_normalize_schema( array() ) ) );
	}

	/**
	 * Layout fields do not count as inputs.
	 *
	 * @covers ::atf_input_fields
	 * @covers ::atf_field_is_input
	 */
	public function test_layout_fields_are_not_inputs() {
		$schema = atf_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'   => 'f1',
						'type' => 'text',
					),
					array(
						'id'   => 'h1',
						'type' => 'heading',
					),
					array(
						'id'   => 'd1',
						'type' => 'divider',
					),
					array(
						'id'   => 'f2',
						'type' => 'email',
					),
				),
			)
		);

		$this->assertSame( array( 'f1', 'f2' ), wp_list_pluck( atf_input_fields( $schema ), 'id' ) );
	}

	/**
	 * An unknown field type is kept and treated as an input.
	 *
	 * A form that used a field from a plugin that has since been deactivated
	 * still has that answer in every entry, and pretending the field was
	 * decorative would drop the column from the export.
	 *
	 * @covers ::atf_field_is_input
	 */
	public function test_unknown_type_is_treated_as_an_input() {
		$this->assertTrue( atf_field_is_input( array( 'type' => 'some_plugins_field' ) ) );
	}

	/**
	 * A field can be found anywhere, including inside a repeater.
	 *
	 * @covers ::atf_find_field
	 */
	public function test_find_field_reaches_into_repeaters() {
		$schema = atf_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'     => 'rep',
						'type'   => 'repeater',
						'fields' => array(
							array(
								'id'   => 'inner',
								'type' => 'text',
							),
						),
					),
				),
			)
		);

		$this->assertSame( 'inner', atf_find_field( $schema, 'inner' )['id'] );
		$this->assertNull( atf_find_field( $schema, 'nope' ) );
	}

	/**
	 * A choice field that arrives with no choices gets some.
	 *
	 * The bug this pins: a radio group, a checkbox list, an image choice or a quiz
	 * with an empty `choices` array renders a legend and nothing else — a question
	 * with no way to answer it. If it is also `required`, the form cannot be
	 * submitted at all. The builder never produces one because it seeds two
	 * choices when a field is dragged in; Import, the REST API and a hand-written
	 * migration schema all can, and those are exactly the paths where nobody is
	 * watching the form render.
	 *
	 * @dataProvider data_choice_types
	 * @covers ::atf_seed_field_defaults
	 *
	 * @param string $type A field type that is a list of choices.
	 */
	public function test_a_choice_field_is_never_left_empty( $type ) {
		$schema = atf_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'   => 'f1',
						'type' => $type,
					),
				),
			)
		);

		$this->assertNotEmpty(
			$schema['fields'][0]['choices'],
			sprintf( 'A %s with no choices renders a question nobody can answer.', $type )
		);
	}

	/**
	 * The field types that are a list of choices.
	 *
	 * Read from the registry rather than listed here, so a type registered later
	 * is covered without anybody remembering to add it.
	 *
	 * @return array[]
	 */
	public function data_choice_types() {
		$cases = array();

		foreach ( atf_get_field_types() as $slug => $definition ) {
			if ( ! empty( $definition['choices'] ) ) {
				$cases[ $slug ] = array( $slug );
			}
		}

		return $cases;
	}

	/**
	 * Choices that *were* supplied are left exactly as they came.
	 *
	 * The seeding must be a fallback, not a policy. Overwriting or appending to a
	 * real list would corrupt every imported form it touched.
	 *
	 * @covers ::atf_seed_field_defaults
	 */
	public function test_seeding_never_touches_real_choices() {
		$schema = atf_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'      => 'f1',
						'type'    => 'radio',
						'choices' => array(
							array(
								'label' => 'Only one',
								'value' => 'only',
							),
						),
					),
				),
			)
		);

		$this->assertCount( 1, $schema['fields'][0]['choices'] );
		$this->assertSame( 'Only one', $schema['fields'][0]['choices'][0]['label'] );
	}

	/**
	 * A Likert matrix gets both of its axes.
	 *
	 * Choices are the shared answer scale and rows are the statements. One
	 * without the other is still an unanswerable question.
	 *
	 * @covers ::atf_seed_field_defaults
	 */
	public function test_a_likert_gets_statements_as_well_as_a_scale() {
		$schema = atf_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'   => 'f1',
						'type' => 'likert',
					),
				),
			)
		);

		$this->assertNotEmpty( $schema['fields'][0]['choices'], 'A Likert matrix needs an answer scale.' );
		$this->assertNotEmpty( $schema['fields'][0]['rows'], 'A Likert matrix needs statements to rate.' );
	}

	/**
	 * A repeater gets something to repeat.
	 *
	 * @covers ::atf_seed_field_defaults
	 */
	public function test_a_repeater_gets_a_sub_field() {
		$schema = atf_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'   => 'f1',
						'type' => 'repeater',
					),
				),
			)
		);

		$this->assertNotEmpty( $schema['fields'][0]['fields'], 'A repeater with nothing in it repeats nothing.' );
		$this->assertSame( 'text', $schema['fields'][0]['fields'][0]['type'] );
	}
}

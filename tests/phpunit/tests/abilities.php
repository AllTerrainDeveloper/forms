<?php
/**
 * The abilities agents use the forms through.
 *
 * Exercised through `wp_get_ability()->execute()` — the same door an MCP
 * client or the REST channel walks through — so the permission gates and the
 * input schemas are part of what is being tested, not bypassed by it.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * Registration, permissions, and each ability's behaviour.
 *
 * @group allterrain-forms
 */
class ATF_Test_Abilities extends WP_UnitTestCase {

	/**
	 * Skips the class on a WordPress without the Abilities API.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API is not available on this WordPress.' );
		}

		atf_add_capabilities();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Runs one ability the way a client would.
	 *
	 * @param string $name  The ability name.
	 * @param mixed  $input Its input.
	 * @return mixed The result, or a WP_Error.
	 */
	protected function run_ability( $name, $input = null ) {
		$ability = wp_get_ability( $name );

		$this->assertNotNull( $ability, "The ability {$name} is registered." );

		return $ability->execute( $input );
	}

	/**
	 * A form with one of each answer shape the tests below use.
	 *
	 * @return int The form id.
	 */
	protected function make_form() {
		$result = $this->run_ability(
			'allterrain-forms/create-form',
			array(
				'title'  => 'Agent-built booking form',
				'fields' => array(
					array(
						'type'     => 'text',
						'label'    => 'Your name',
						'required' => true,
					),
					array(
						'type'  => 'email',
						'label' => 'Email',
					),
					array(
						'type'    => 'radio',
						'label'   => 'Room',
						'choices' => array(
							array(
								'label' => 'Garden room',
								'value' => 'garden',
							),
							array(
								'label' => 'Sea view',
								'value' => 'sea',
							),
						),
					),
				),
			)
		);

		$this->assertIsArray( $result );

		return (int) $result['id'];
	}

	/**
	 * Every ability is registered, in the plugin's own category.
	 *
	 * @covers ::atf_register_abilities
	 */
	public function test_the_set_is_registered() {
		$names = array(
			'allterrain-forms/list-forms',
			'allterrain-forms/get-form',
			'allterrain-forms/list-field-types',
			'allterrain-forms/create-form',
			'allterrain-forms/set-form-theme',
			'allterrain-forms/list-entries',
			'allterrain-forms/get-entry',
			'allterrain-forms/submit-form',
			'allterrain-forms/form-report',
		);

		foreach ( $names as $name ) {
			$this->assertInstanceOf( 'WP_Ability', wp_get_ability( $name ), $name );
		}
	}

	/**
	 * An agent can build a working form from a loose description.
	 *
	 * Ids are minted, the shortcode is returned, and the fields come back in
	 * the distilled shape every other ability speaks.
	 *
	 * @covers ::atf_ability_create_form
	 * @covers ::atf_ability_form
	 */
	public function test_create_form_builds_something_usable() {
		$form_id = $this->make_form();
		$form    = $this->run_ability( 'allterrain-forms/get-form', array( 'form_id' => $form_id ) );

		$this->assertSame( 'Agent-built booking form', $form['title'] );
		$this->assertSame( sprintf( '[allterrain_form id="%d"]', $form_id ), $form['shortcode'] );
		$this->assertCount( 3, $form['fields'] );
		$this->assertSame( 'text', $form['fields'][0]['type'] );
		$this->assertTrue( $form['fields'][0]['required'] );
		$this->assertNotSame( '', $form['fields'][0]['id'], 'Field ids are issued automatically.' );
		$this->assertSame( 'garden', $form['fields'][2]['choices'][0]['value'] );
	}

	/**
	 * The vocabulary lists real, usable types.
	 *
	 * @covers ::atf_ability_list_field_types
	 */
	public function test_field_type_vocabulary() {
		$types = $this->run_ability( 'allterrain-forms/list-field-types' );
		$slugs = wp_list_pluck( $types, 'type' );

		foreach ( array( 'text', 'email', 'radio', 'file', 'signature', 'repeater' ) as $expected ) {
			$this->assertContains( $expected, $slugs );
		}
	}

	/**
	 * Submitting runs the real pipeline: validation refuses, then stores.
	 *
	 * @covers ::atf_ability_submit_form
	 */
	public function test_submit_form_validates_then_stores() {
		$form_id = $this->make_form();
		$form    = $this->run_ability( 'allterrain-forms/get-form', array( 'form_id' => $form_id ) );
		$name_id = $form['fields'][0]['id'];
		$room_id = $form['fields'][2]['id'];

		// The required name is missing: nothing may be stored.
		$refused = $this->run_ability(
			'allterrain-forms/submit-form',
			array(
				'form_id' => $form_id,
				'values'  => array( $room_id => 'sea' ),
			)
		);

		$this->assertFalse( $refused['success'] );
		$this->assertArrayHasKey( $name_id, $refused['errors'] );
		$this->assertSame( 0, atf_count_entries( $form_id ) );

		$accepted = $this->run_ability(
			'allterrain-forms/submit-form',
			array(
				'form_id' => $form_id,
				'values'  => array(
					$name_id => 'Aoife Brennan',
					$room_id => 'sea',
				),
			)
		);

		$this->assertTrue( $accepted['success'] );
		$this->assertGreaterThan( 0, $accepted['entry_id'] );
		$this->assertSame( 1, atf_count_entries( $form_id ) );
	}

	/**
	 * Entries come back readable, and one entry comes back whole.
	 *
	 * @covers ::atf_ability_list_entries
	 * @covers ::atf_ability_get_entry
	 */
	public function test_entries_are_readable() {
		$form_id = $this->make_form();
		$form    = $this->run_ability( 'allterrain-forms/get-form', array( 'form_id' => $form_id ) );

		$this->run_ability(
			'allterrain-forms/submit-form',
			array(
				'form_id' => $form_id,
				'values'  => array( $form['fields'][0]['id'] => 'Tomas Berg' ),
			)
		);

		$list = $this->run_ability( 'allterrain-forms/list-entries', array( 'form_id' => $form_id ) );

		$this->assertSame( 1, $list['total'] );

		$entry = $this->run_ability(
			'allterrain-forms/get-entry',
			array( 'entry_id' => (int) $list['entries'][0]['id'] )
		);

		$labels = wp_list_pluck( $entry['fields'], 'label' );

		$this->assertContains( 'Your name', $labels );
	}

	/**
	 * The theme ability applies real themes and refuses invented ones.
	 *
	 * @covers ::atf_ability_set_form_theme
	 */
	public function test_set_form_theme() {
		$form_id = $this->make_form();

		$themed = $this->run_ability(
			'allterrain-forms/set-form-theme',
			array(
				'form_id' => $form_id,
				'theme'   => 'neon',
			)
		);

		$this->assertSame( 'neon', $themed['theme'] );

		$refused = $this->run_ability(
			'allterrain-forms/set-form-theme',
			array(
				'form_id' => $form_id,
				'theme'   => 'vantablack',
			)
		);

		$this->assertWPError( $refused );
	}

	/**
	 * The report summarises without fetching entries.
	 *
	 * @covers ::atf_ability_form_report
	 */
	public function test_form_report() {
		$form_id = $this->make_form();
		$form    = $this->run_ability( 'allterrain-forms/get-form', array( 'form_id' => $form_id ) );

		$this->run_ability(
			'allterrain-forms/submit-form',
			array(
				'form_id' => $form_id,
				'values'  => array( $form['fields'][0]['id'] => 'Priya Nandra' ),
			)
		);

		$report = $this->run_ability( 'allterrain-forms/form-report', array( 'form_id' => $form_id ) );

		$this->assertSame( 1, $report['submissions'] );
		$this->assertArrayHasKey( 'fields', $report );
	}

	/**
	 * Capability gates hold: a subscriber can neither build nor read.
	 *
	 * @covers ::atf_register_abilities
	 */
	public function test_subscriber_is_refused() {
		$form_id = $this->make_form();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$create = wp_get_ability( 'allterrain-forms/create-form' )->execute( array( 'title' => 'Nope' ) );
		$read   = wp_get_ability( 'allterrain-forms/list-entries' )->execute( array( 'form_id' => $form_id ) );

		$this->assertWPError( $create );
		$this->assertWPError( $read );
	}
}

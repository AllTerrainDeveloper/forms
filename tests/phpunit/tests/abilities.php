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
class ALLTFO_Test_Abilities extends WP_UnitTestCase {

	/**
	 * Skips the class on a WordPress without the Abilities API.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API is not available on this WordPress.' );
		}

		alltfo_add_capabilities();
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
	 * @covers ::alltfo_register_abilities
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
	 * @covers ::alltfo_ability_create_form
	 * @covers ::alltfo_ability_form
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
	 * @covers ::alltfo_ability_list_field_types
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
	 * @covers ::alltfo_ability_submit_form
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
		$this->assertSame( 0, alltfo_count_entries( $form_id ) );

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
		$this->assertSame( 1, alltfo_count_entries( $form_id ) );
	}

	/**
	 * Entries come back readable, and one entry comes back whole.
	 *
	 * @covers ::alltfo_ability_list_entries
	 * @covers ::alltfo_ability_get_entry
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
	 * @covers ::alltfo_ability_set_form_theme
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
	 * @covers ::alltfo_ability_form_report
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
	 * @covers ::alltfo_register_abilities
	 */
	public function test_subscriber_is_refused() {
		$form_id = $this->make_form();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$create = wp_get_ability( 'allterrain-forms/create-form' )->execute( array( 'title' => 'Nope' ) );
		$read   = wp_get_ability( 'allterrain-forms/list-entries' )->execute( array( 'form_id' => $form_id ) );

		$this->assertWPError( $create );
		$this->assertWPError( $read );
	}

	/**
	 * An unauthenticated caller is refused everywhere — submit-form included.
	 *
	 * Submitting mints a valid time-trap signature, a liberty that is only
	 * honest on a channel that identifies its caller. Anonymous traffic has
	 * the rendered form and the public REST route, where the trap runs whole.
	 *
	 * @covers ::alltfo_register_abilities
	 */
	public function test_logged_out_caller_is_refused() {
		$form_id = $this->make_form();
		$form    = $this->run_ability( 'allterrain-forms/get-form', array( 'form_id' => $form_id ) );
		$name_id = $form['fields'][0]['id'];

		wp_set_current_user( 0 );

		$this->assertWPError( wp_get_ability( 'allterrain-forms/get-form' )->execute( array( 'form_id' => $form_id ) ) );
		$this->assertWPError( wp_get_ability( 'allterrain-forms/form-report' )->execute( array( 'form_id' => $form_id ) ) );

		$refused = wp_get_ability( 'allterrain-forms/submit-form' )->execute(
			array(
				'form_id' => $form_id,
				'values'  => array( $name_id => 'Anonymous' ),
			)
		);

		$this->assertWPError( $refused );
		$this->assertSame( 0, alltfo_count_entries( $form_id ), 'The refused submit stored nothing.' );
	}

	/**
	 * Any authenticated user may submit — submitting is what visitors do.
	 *
	 * @covers ::alltfo_ability_submit_form
	 */
	public function test_a_subscriber_can_submit() {
		$form_id = $this->make_form();
		$form    = $this->run_ability( 'allterrain-forms/get-form', array( 'form_id' => $form_id ) );
		$name_id = $form['fields'][0]['id'];

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$accepted = wp_get_ability( 'allterrain-forms/submit-form' )->execute(
			array(
				'form_id' => $form_id,
				'values'  => array( $name_id => 'Signed-in visitor' ),
			)
		);

		$this->assertTrue( $accepted['success'] );
		$this->assertSame( 1, alltfo_count_entries( $form_id ) );
	}

	/**
	 * The per-form filter confines every reading ability, id in hand.
	 *
	 * A user the `alltfo_can_read_entries` filter restricts to one form gets
	 * that form and no other — from get-form, from form-report, and from the
	 * list itself.
	 *
	 * @covers ::alltfo_register_abilities
	 * @covers ::alltfo_ability_list_forms
	 */
	public function test_per_form_filter_confines_the_read_abilities() {
		$allowed = $this->make_form();
		$other   = $this->make_form();

		$reader = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		( new WP_User( $reader ) )->add_cap( 'alltfo_read_entries' );
		wp_set_current_user( $reader );

		$filter = static function ( $can, $form_id ) use ( $allowed ) {
			// 0 is the "any form at all" question the list gate asks; every
			// concrete id except the allowed one is refused.
			return ( 0 === $form_id || $allowed === $form_id ) ? $can : false;
		};

		add_filter( 'alltfo_can_read_entries', $filter, 10, 2 );

		$mine   = wp_get_ability( 'allterrain-forms/get-form' )->execute( array( 'form_id' => $allowed ) );
		$theirs = wp_get_ability( 'allterrain-forms/get-form' )->execute( array( 'form_id' => $other ) );

		$this->assertIsArray( $mine );
		$this->assertWPError( $theirs, 'The schema of a form outside the filter is not readable.' );

		$this->assertWPError(
			wp_get_ability( 'allterrain-forms/form-report' )->execute( array( 'form_id' => $other ) ),
			'The analytics of a form outside the filter are not readable.'
		);

		$ids = wp_list_pluck( wp_get_ability( 'allterrain-forms/list-forms' )->execute(), 'id' );

		$this->assertContains( $allowed, $ids );
		$this->assertNotContains( $other, $ids, 'The list is confined the same way.' );

		remove_filter( 'alltfo_can_read_entries', $filter, 10 );
	}
}

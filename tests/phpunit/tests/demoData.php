<?php
/**
 * The demo-data generator.
 *
 * Two promises, and the second one is the dangerous one.
 *
 * **It makes real submissions.** Every generated entry goes through
 * `atf_process_submission()`, the same function a stranger's POST reaches. A
 * seeder that wrote rows straight into the database would produce data the
 * pipeline could never have produced, and the first thing that would hide is a
 * bug in the pipeline.
 *
 * **It comes back out, and takes nothing else with it.** This is the one that
 * would be unforgivable to get wrong. The demo form is a working form with a
 * working shortcode, so somebody *will* submit a real answer to it, and removal
 * must not delete that — nor anything on any other form. The marker is the whole
 * safety mechanism and the tests below attack it from both directions: a real
 * entry on the demo form survives, and a demo entry is found wherever it is.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * The demo-data generator.
 *
 * @group allterrain-forms
 */
class ATF_Test_Demo_Data extends WP_UnitTestCase {

	/**
	 * Developer mode, forced on for the duration.
	 *
	 * Every entry point checks it, so without this every test here would assert
	 * only that the gate works.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		add_filter( 'atf_developer_mode', '__return_true' );

		// The plugin's capabilities are granted on activation, which the test
		// harness never runs -- so an administrator has none of them until this is
		// called, and every gate below would refuse for the wrong reason.
		atf_add_capabilities();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Puts the gate back.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_filter( 'atf_developer_mode', '__return_true' );

		parent::tear_down();
	}

	/**
	 * The survey is a schema the plugin will actually accept.
	 *
	 * Normalisation is where a hand-written schema quietly loses fields — a
	 * mistyped type, a choice list in the wrong shape — and the result is a demo
	 * that generates a smaller survey than the one that was written.
	 *
	 * @covers ::atf_demo_survey_schema
	 */
	public function test_survey_survives_normalisation() {
		$written    = atf_demo_survey_schema();
		$normalised = atf_normalize_schema( $written );

		$this->assertCount(
			count( $written['fields'] ),
			$normalised['fields'],
			'Normalisation dropped a question.'
		);

		foreach ( $normalised['fields'] as $index => $field ) {
			$this->assertSame( $written['fields'][ $index ]['type'], $field['type'] );
		}
	}

	/**
	 * The survey covers every shape a report has to draw.
	 *
	 * The questions were chosen for what they make possible downstream, so this
	 * asserts that intent rather than the list: swapping the 0–10 question for
	 * another rating would leave the survey looking fine and the NPS panel with
	 * nothing to render.
	 *
	 * @covers ::atf_demo_survey_schema
	 */
	public function test_survey_covers_the_report() {
		$schema = atf_normalize_schema( atf_demo_survey_schema() );
		$types  = wp_list_pluck( $schema['fields'], 'type' );

		$this->assertContains( 'select', $types, 'Something to group by.' );
		$this->assertContains( 'radio', $types, 'A second, ordered dimension.' );
		$this->assertContains( 'rating', $types, 'A mean worth taking.' );
		$this->assertContains( 'checkboxes', $types, 'A multi-select, where percentages exceed 100.' );
		$this->assertContains( 'textarea', $types, 'A response rate that is not 100.' );

		$this->assertNotEmpty(
			atf_analytics_dimensions( $schema ),
			'Nothing in the survey can be grouped by, so the cross-tab would be empty.'
		);

		$nps = array_filter( $schema['fields'], 'atf_analytics_is_nps_field' );

		$this->assertNotEmpty( $nps, 'No 0-10 question, so the NPS panel has nothing to draw.' );
	}

	/**
	 * The survey sends no e-mail.
	 *
	 * Five hundred submissions with a notification configured is five hundred
	 * e-mails, to whoever runs the site, because somebody wanted some demo data.
	 *
	 * @covers ::atf_demo_survey_schema
	 */
	public function test_survey_has_no_notifications() {
		$schema = atf_normalize_schema( atf_demo_survey_schema() );

		// Read from the top level, which is where the renderer reads them. Written
		// under `settings` this assertion passed against a key nothing uses, and
		// would have gone on passing if a notification were added in the right
		// place.
		$this->assertSame( array(), $schema['notifications'] );

		// The confirmation, meanwhile, has to have survived — proving the level is
		// right rather than that everything at that level is simply empty.
		$this->assertCount( 1, $schema['confirmations'] );
	}

	/**
	 * Seeding makes real, readable entries.
	 *
	 * @covers ::atf_demo_seed
	 */
	public function test_seeding_stores_entries() {
		$status = atf_demo_seed( 12 );

		$this->assertNotWPError( $status );
		$this->assertSame( 12, $status['entries'] );
		$this->assertGreaterThan( 0, $status['formId'] );

		$entries = get_posts(
			array(
				'post_type'      => ATF_ENTRY_TYPE,
				'post_status'    => atf_entry_statuses(),
				'posts_per_page' => -1,
				'meta_key'       => ATF_META_FORM,
				'meta_value'     => $status['formId'],
			)
		);

		$this->assertCount( 12, $entries );

		$values = json_decode( (string) get_post_meta( $entries[0]->ID, ATF_META_VALUES, true ), true );

		$this->assertIsArray( $values );
		$this->assertArrayHasKey( 'team', $values, 'An entry with no answers means the pipeline rejected them all.' );
		$this->assertArrayHasKey( 'recommend', $values );
	}

	/**
	 * The submissions are not filed as spam.
	 *
	 * The seeder has to satisfy the time trap honestly, with a signed timestamp,
	 * rather than being waved through. If that ever stops working every generated
	 * entry lands in the spam folder — the report would go quietly empty while the
	 * count of entries kept rising, which is a confusing way to fail.
	 *
	 * @covers ::atf_demo_submit
	 */
	public function test_submissions_are_not_screened_as_spam() {
		$status = atf_demo_seed( 20 );

		$spam = get_posts(
			array(
				'post_type'      => ATF_ENTRY_TYPE,
				'post_status'    => ATF_STATUS_SPAM,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => ATF_META_FORM,
				'meta_value'     => $status['formId'],
			)
		);

		// A couple of percent are marked spam on purpose, so the filters have
		// something to show. The assertion is that it is a sprinkle rather than
		// everything, which is what a failing time trap would produce.
		$this->assertLessThan( 10, count( $spam ), 'Almost everything was screened as spam.' );
	}

	/**
	 * Entries are spread over time rather than all stamped now.
	 *
	 * A timeline of five hundred submissions on one day is not a timeline.
	 *
	 * @covers ::atf_demo_answered_at
	 */
	public function test_entries_are_spread_over_time() {
		$status = atf_demo_seed( 25 );

		$entries = get_posts(
			array(
				'post_type'      => ATF_ENTRY_TYPE,
				'post_status'    => atf_entry_statuses(),
				'posts_per_page' => -1,
				'meta_key'       => ATF_META_FORM,
				'meta_value'     => $status['formId'],
			)
		);

		$days = array();

		foreach ( $entries as $entry ) {
			$days[ substr( $entry->post_date_gmt, 0, 10 ) ] = true;
		}

		$this->assertGreaterThan( 5, count( $days ), 'Every entry landed on nearly the same day.' );
	}

	/**
	 * Two chunks are two different groups of people.
	 *
	 * The generator's state is carried between calls. Restarting it from the seed
	 * each time would make every chunk produce the *same* respondents, and five
	 * hundred submissions would be one group of people repeated — which looks
	 * completely fine in the totals and makes every distribution a lie.
	 *
	 * @covers ::atf_demo_seed
	 */
	public function test_chunks_do_not_repeat_the_same_people() {
		atf_demo_seed( 10 );
		$status = atf_demo_seed( 10 );

		$entries = get_posts(
			array(
				'post_type'      => ATF_ENTRY_TYPE,
				'post_status'    => atf_entry_statuses(),
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_key'       => ATF_META_FORM,
				'meta_value'     => $status['formId'],
			)
		);

		$this->assertCount( 20, $entries );

		$answers = array();

		foreach ( $entries as $entry ) {
			$answers[] = get_post_meta( $entry->ID, ATF_META_VALUES, true );
		}

		$first  = array_slice( $answers, 0, 10 );
		$second = array_slice( $answers, 10, 10 );

		$this->assertNotSame( $first, $second, 'The second chunk repeated the first.' );
	}

	/**
	 * The population is varied rather than uniform.
	 *
	 * A generator that answered at random would pass every test above while
	 * producing flat charts, which is the failure this whole file exists to catch.
	 *
	 * @covers ::atf_demo_respondent
	 */
	public function test_the_population_is_varied() {
		$state = 12345;
		$teams = array();
		$scores = array();

		for ( $i = 0; $i < 200; $i++ ) {
			$person = atf_demo_respondent( $state );

			$teams[ $person['team'] ] = true;
			$scores[] = (int) $person['recommend'];
		}

		$this->assertGreaterThan( 4, count( $teams ), 'Almost everybody is on one team.' );

		$summary = atf_analytics_numbers( $scores );

		$this->assertGreaterThan( 3, $summary['max'] - $summary['min'], 'Every answer is nearly the same.' );

		$nps = atf_analytics_nps( $scores );

		// All three bands populated. A population sitting entirely in one of them
		// renders the NPS panel as a single bar and proves nothing about the split.
		$this->assertGreaterThan( 0, $nps['promoters'] );
		$this->assertGreaterThan( 0, $nps['passives'] );
		$this->assertGreaterThan( 0, $nps['detractors'] );
	}

	/**
	 * The same seed gives the same people.
	 *
	 * @covers ::atf_demo_respondent
	 */
	public function test_the_generator_is_reproducible() {
		$one = 999;
		$two = 999;

		$this->assertSame( atf_demo_respondent( $one ), atf_demo_respondent( $two ) );
	}

	/**
	 * Removing takes back everything it made.
	 *
	 * @covers ::atf_demo_remove
	 */
	public function test_removal_takes_it_all_back() {
		$status = atf_demo_seed( 15 );

		$removed = atf_demo_remove();

		$this->assertSame( 15, $removed['entries'] );
		$this->assertSame( 1, $removed['forms'] );

		$this->assertSame( 0, atf_demo_entry_count() );
		$this->assertSame( 0, atf_demo_form_id() );
		$this->assertNull( get_post( $status['formId'] ) );
	}

	/**
	 * Removing leaves a real submission to the demo form alone.
	 *
	 * The demo form is a working form with a working shortcode, so somebody will
	 * eventually answer it for real. Deleting "every entry on the demo form" would
	 * be the obvious implementation and would take that with it.
	 *
	 * @covers ::atf_demo_remove
	 */
	public function test_removal_spares_a_real_entry_on_the_demo_form() {
		$status = atf_demo_seed( 5 );

		// A genuine submission, stored the ordinary way and carrying no marker.
		$real = atf_store_entry(
			$status['formId'],
			atf_get_form_schema( $status['formId'] ),
			array( 'team' => 'Engineering' )
		);

		$this->assertNotWPError( $real );

		atf_demo_remove();

		$this->assertNotNull( get_post( $real ), 'A real submission was deleted with the demo data.' );
	}

	/**
	 * Removing leaves other forms and their entries alone.
	 *
	 * @covers ::atf_demo_remove
	 */
	public function test_removal_spares_other_forms() {
		$other = atf_test_form(
			array(
				'fields' => array(
					array(
						'id'   => 'f1',
						'type' => 'text',
					),
				),
			)
		);

		$entry = atf_store_entry( $other, atf_get_form_schema( $other ), array( 'f1' => 'hello' ) );

		atf_demo_seed( 5 );
		atf_demo_remove();

		$this->assertNotNull( get_post( $other ) );
		$this->assertNotNull( get_post( $entry ) );
	}

	/**
	 * Seeding twice does not make a second survey.
	 *
	 * @covers ::atf_demo_create_form
	 */
	public function test_seeding_reuses_the_form() {
		$first  = atf_demo_seed( 5 );
		$second = atf_demo_seed( 5 );

		$this->assertSame( $first['formId'], $second['formId'] );
		$this->assertSame( 10, $second['entries'] );
	}

	/**
	 * A finished batch stops rather than overshooting.
	 *
	 * The client loops until nothing is left, so a seeder that always made
	 * something would never terminate.
	 *
	 * @covers ::atf_demo_seed
	 */
	public function test_seeding_stops_at_the_target() {
		$form_id = atf_demo_create_form( 1 );

		update_post_meta( $form_id, ATF_META_DEMO . '_target', 3 );

		atf_demo_seed( 25 );
		$status = atf_demo_seed( 25 );

		$this->assertSame( 3, $status['entries'] );
		$this->assertSame( 0, $status['remaining'] );
	}

	/**
	 * Without developer mode nothing runs.
	 *
	 * @covers ::atf_demo_seed
	 * @covers ::atf_demo_remove
	 */
	public function test_the_gate_refuses() {
		remove_filter( 'atf_developer_mode', '__return_true' );

		$this->assertWPError( atf_demo_seed( 1 ) );
		$this->assertWPError( atf_demo_remove() );

		add_filter( 'atf_developer_mode', '__return_true' );
	}

	/**
	 * Developer mode alone is not permission.
	 *
	 * The preference says "show me these"; the capability says "you may use
	 * them". A preference is stored in user meta, so treating it as authorisation
	 * would mean anybody who can write their own meta could seed a database.
	 *
	 * @covers ::atf_can_use_developer_tools
	 */
	public function test_developer_mode_is_not_a_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertTrue( atf_developer_mode(), 'The preference is on for this test.' );
		$this->assertFalse( atf_can_use_developer_tools(), 'A subscriber must not be able to seed.' );
		$this->assertWPError( atf_demo_seed( 1 ) );
	}
}

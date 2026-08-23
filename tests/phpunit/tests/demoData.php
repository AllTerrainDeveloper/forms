<?php
/**
 * The demo-data generator.
 *
 * Two promises, and the second one is the dangerous one.
 *
 * **It makes real submissions.** Every generated entry goes through
 * `alltfo_process_submission()`, the same function a stranger's POST reaches. A
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
class ALLTFO_Test_Demo_Data extends WP_UnitTestCase {

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

		add_filter( 'alltfo_developer_mode', '__return_true' );

		// The plugin's capabilities are granted on activation, which the test
		// harness never runs -- so an administrator has none of them until this is
		// called, and every gate below would refuse for the wrong reason.
		alltfo_add_capabilities();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Puts the gate back.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_filter( 'alltfo_developer_mode', '__return_true' );

		parent::tear_down();
	}

	/**
	 * The survey is a schema the plugin will actually accept.
	 *
	 * Normalisation is where a hand-written schema quietly loses fields — a
	 * mistyped type, a choice list in the wrong shape — and the result is a demo
	 * that generates a smaller survey than the one that was written.
	 *
	 * @covers ::alltfo_demo_survey_schema
	 */
	public function test_survey_survives_normalisation() {
		$written    = alltfo_demo_survey_schema();
		$normalised = alltfo_normalize_schema( $written );

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
	 * @covers ::alltfo_demo_survey_schema
	 */
	public function test_survey_covers_the_report() {
		$schema = alltfo_normalize_schema( alltfo_demo_survey_schema() );
		$types  = wp_list_pluck( $schema['fields'], 'type' );

		$this->assertContains( 'select', $types, 'Something to group by.' );
		$this->assertContains( 'radio', $types, 'A second, ordered dimension.' );
		$this->assertContains( 'rating', $types, 'A mean worth taking.' );
		$this->assertContains( 'checkboxes', $types, 'A multi-select, where percentages exceed 100.' );
		$this->assertContains( 'textarea', $types, 'A response rate that is not 100.' );

		$this->assertNotEmpty(
			alltfo_analytics_dimensions( $schema ),
			'Nothing in the survey can be grouped by, so the cross-tab would be empty.'
		);

		$nps = array_filter( $schema['fields'], 'alltfo_analytics_is_nps_field' );

		$this->assertNotEmpty( $nps, 'No 0-10 question, so the NPS panel has nothing to draw.' );

		// The repeater, with enough inside it to exercise every per-row report:
		// a numeric sub-field to pool, a choice sub-field to tally, and a text
		// sub-field whose response rate is under 100.
		$this->assertContains( 'repeater', $types, 'Nothing repeats, so the per-row panels have nothing to draw.' );

		$repeater  = $schema['fields'][ array_search( 'repeater', $types, true ) ];
		$sub_types = wp_list_pluck( $repeater['fields'], 'type' );

		$this->assertContains( 'number', $sub_types );
		$this->assertContains( 'select', $sub_types );
		$this->assertContains( 'text', $sub_types );

		// And a total that aggregates the repeater, so the pipeline recomputes
		// the new grammar on every one of five hundred submissions.
		$total = $schema['fields'][ array_search( 'total', $types, true ) ];

		$this->assertStringContainsString( '{projects.hours}', $total['formula'] );
	}

	/**
	 * The survey sends no e-mail.
	 *
	 * Five hundred submissions with a notification configured is five hundred
	 * e-mails, to whoever runs the site, because somebody wanted some demo data.
	 *
	 * @covers ::alltfo_demo_survey_schema
	 */
	public function test_survey_has_no_notifications() {
		$schema = alltfo_normalize_schema( alltfo_demo_survey_schema() );

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
	 * @covers ::alltfo_demo_seed
	 */
	public function test_seeding_stores_entries() {
		$status = alltfo_demo_seed( 12 );

		$this->assertNotWPError( $status );
		$this->assertSame( 12, $status['entries'] );
		$this->assertGreaterThan( 0, $status['formId'] );

		$entries = get_posts(
			array(
				'post_type'      => ALLTFO_ENTRY_TYPE,
				'post_status'    => alltfo_entry_statuses(),
				'posts_per_page' => -1,
				'meta_key'       => ALLTFO_META_FORM,
				'meta_value'     => $status['formId'],
			)
		);

		$this->assertCount( 12, $entries );

		$values = json_decode( (string) get_post_meta( $entries[0]->ID, ALLTFO_META_VALUES, true ), true );

		$this->assertIsArray( $values );
		$this->assertArrayHasKey( 'team', $values, 'An entry with no answers means the pipeline rejected them all.' );
		$this->assertArrayHasKey( 'recommend', $values );
	}

	/**
	 * The repeater's rows survive the pipeline, and the total is the pipeline's.
	 *
	 * The generator never posts `project_hours` — the stored number can only
	 * have come from the server evaluating `sum( {projects.hours} )` against
	 * the sanitised rows. This is the aggregate grammar proven end to end, on
	 * every entry, not on a fixture.
	 *
	 * @covers ::alltfo_demo_projects
	 */
	public function test_projects_are_stored_and_their_total_is_computed() {
		$status = alltfo_demo_seed( 10 );

		$this->assertNotWPError( $status );

		$entries = get_posts(
			array(
				'post_type'      => ALLTFO_ENTRY_TYPE,
				'post_status'    => alltfo_entry_statuses(),
				'posts_per_page' => -1,
				'meta_key'       => ALLTFO_META_FORM,
				'meta_value'     => $status['formId'],
			)
		);

		$this->assertNotEmpty( $entries );

		foreach ( $entries as $entry ) {
			$values = json_decode( (string) get_post_meta( $entry->ID, ALLTFO_META_VALUES, true ), true );

			$this->assertIsArray( $values['projects'] );
			$this->assertGreaterThanOrEqual( 1, count( $values['projects'] ) );
			$this->assertLessThanOrEqual( 4, count( $values['projects'] ) );

			$hours = 0;

			foreach ( $values['projects'] as $row ) {
				$this->assertIsNumeric( $row['hours'], 'A row lost its hours in sanitisation.' );

				$hours += (float) $row['hours'];
			}

			$this->assertEquals(
				$hours,
				(float) $values['project_hours'],
				'The stored total is not the sum of the rows, so the server never computed it.'
			);
		}
	}

	/**
	 * The submissions are not filed as spam.
	 *
	 * The seeder has to satisfy the time trap honestly, with a signed timestamp,
	 * rather than being waved through. If that ever stops working every generated
	 * entry lands in the spam folder — the report would go quietly empty while the
	 * count of entries kept rising, which is a confusing way to fail.
	 *
	 * @covers ::alltfo_demo_submit
	 */
	public function test_submissions_are_not_screened_as_spam() {
		$status = alltfo_demo_seed( 20 );

		$spam = get_posts(
			array(
				'post_type'      => ALLTFO_ENTRY_TYPE,
				'post_status'    => ALLTFO_STATUS_SPAM,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => ALLTFO_META_FORM,
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
	 * @covers ::alltfo_demo_answered_at
	 */
	public function test_entries_are_spread_over_time() {
		$status = alltfo_demo_seed( 25 );

		$entries = get_posts(
			array(
				'post_type'      => ALLTFO_ENTRY_TYPE,
				'post_status'    => alltfo_entry_statuses(),
				'posts_per_page' => -1,
				'meta_key'       => ALLTFO_META_FORM,
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
	 * @covers ::alltfo_demo_seed
	 */
	public function test_chunks_do_not_repeat_the_same_people() {
		alltfo_demo_seed( 10 );
		$status = alltfo_demo_seed( 10 );

		$entries = get_posts(
			array(
				'post_type'      => ALLTFO_ENTRY_TYPE,
				'post_status'    => alltfo_entry_statuses(),
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_key'       => ALLTFO_META_FORM,
				'meta_value'     => $status['formId'],
			)
		);

		$this->assertCount( 20, $entries );

		$answers = array();

		foreach ( $entries as $entry ) {
			$answers[] = get_post_meta( $entry->ID, ALLTFO_META_VALUES, true );
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
	 * @covers ::alltfo_demo_respondent
	 */
	public function test_the_population_is_varied() {
		$state  = 12345;
		$teams  = array();
		$scores = array();

		for ( $i = 0; $i < 200; $i++ ) {
			$person = alltfo_demo_respondent( $state );

			$teams[ $person['team'] ] = true;
			$scores[]                 = (int) $person['recommend'];
		}

		$this->assertGreaterThan( 4, count( $teams ), 'Almost everybody is on one team.' );

		$summary = alltfo_analytics_numbers( $scores );

		$this->assertGreaterThan( 3, $summary['max'] - $summary['min'], 'Every answer is nearly the same.' );

		$nps = alltfo_analytics_nps( $scores );

		// All three bands populated. A population sitting entirely in one of them
		// renders the NPS panel as a single bar and proves nothing about the split.
		$this->assertGreaterThan( 0, $nps['promoters'] );
		$this->assertGreaterThan( 0, $nps['passives'] );
		$this->assertGreaterThan( 0, $nps['detractors'] );
	}

	/**
	 * The project rows vary on every axis the per-row report reads.
	 *
	 * @covers ::alltfo_demo_projects
	 */
	public function test_the_projects_are_varied() {
		$state    = 4242;
		$counts   = array();
		$outcomes = array();
		$blank    = 0;
		$rows     = 0;

		for ( $i = 0; $i < 200; $i++ ) {
			$projects = alltfo_demo_respondent( $state )['projects'];

			$counts[ count( $projects ) ] = true;

			foreach ( $projects as $row ) {
				++$rows;
				$outcomes[ $row['outcome'] ] = true;

				if ( '' === $row['project_name'] ) {
					++$blank;
				}
			}
		}

		$this->assertGreaterThan( 2, count( $counts ), 'Everybody carries the same number of projects, so the rows-per-entry histogram is a spike.' );
		$this->assertCount( 3, $outcomes, 'An outcome nobody ever reaches renders as a permanent zero.' );

		// Some names blank, most not — that is what makes the sub-field's
		// per-row response rate a number worth drawing.
		$this->assertGreaterThan( 0, $blank );
		$this->assertLessThan( $rows / 2, $blank );
	}

	/**
	 * The same seed gives the same people.
	 *
	 * @covers ::alltfo_demo_respondent
	 */
	public function test_the_generator_is_reproducible() {
		$one = 999;
		$two = 999;

		$this->assertSame( alltfo_demo_respondent( $one ), alltfo_demo_respondent( $two ) );
	}

	/**
	 * Removing takes back everything it made.
	 *
	 * @covers ::alltfo_demo_remove
	 */
	public function test_removal_takes_it_all_back() {
		$status = alltfo_demo_seed( 15 );

		$removed = alltfo_demo_remove();

		$this->assertSame( 15, $removed['entries'] );
		$this->assertSame( 1, $removed['forms'] );

		$this->assertSame( 0, alltfo_demo_entry_count() );
		$this->assertSame( 0, alltfo_demo_form_id() );
		$this->assertNull( get_post( $status['formId'] ) );
	}

	/**
	 * Removing leaves a real submission to the demo form alone.
	 *
	 * The demo form is a working form with a working shortcode, so somebody will
	 * eventually answer it for real. Deleting "every entry on the demo form" would
	 * be the obvious implementation and would take that with it.
	 *
	 * @covers ::alltfo_demo_remove
	 */
	public function test_removal_spares_a_real_entry_on_the_demo_form() {
		$status = alltfo_demo_seed( 5 );

		// A genuine submission, stored the ordinary way and carrying no marker.
		$real = alltfo_store_entry(
			$status['formId'],
			alltfo_get_form_schema( $status['formId'] ),
			array( 'team' => 'Engineering' )
		);

		$this->assertNotWPError( $real );

		alltfo_demo_remove();

		$this->assertNotNull( get_post( $real ), 'A real submission was deleted with the demo data.' );
	}

	/**
	 * Removing leaves other forms and their entries alone.
	 *
	 * @covers ::alltfo_demo_remove
	 */
	public function test_removal_spares_other_forms() {
		$other = alltfo_test_form(
			array(
				'fields' => array(
					array(
						'id'   => 'f1',
						'type' => 'text',
					),
				),
			)
		);

		$entry = alltfo_store_entry( $other, alltfo_get_form_schema( $other ), array( 'f1' => 'hello' ) );

		alltfo_demo_seed( 5 );
		alltfo_demo_remove();

		$this->assertNotNull( get_post( $other ) );
		$this->assertNotNull( get_post( $entry ) );
	}

	/**
	 * Seeding twice does not make a second survey.
	 *
	 * @covers ::alltfo_demo_create_form
	 */
	public function test_seeding_reuses_the_form() {
		$first  = alltfo_demo_seed( 5 );
		$second = alltfo_demo_seed( 5 );

		$this->assertSame( $first['formId'], $second['formId'] );
		$this->assertSame( 10, $second['entries'] );
	}

	/**
	 * A finished batch stops rather than overshooting.
	 *
	 * The client loops until nothing is left, so a seeder that always made
	 * something would never terminate.
	 *
	 * @covers ::alltfo_demo_seed
	 */
	public function test_seeding_stops_at_the_target() {
		$form_id = alltfo_demo_create_form( 1 );

		update_post_meta( $form_id, ALLTFO_META_DEMO . '_target', 3 );

		alltfo_demo_seed( 25 );
		$status = alltfo_demo_seed( 25 );

		$this->assertSame( 3, $status['entries'] );
		$this->assertSame( 0, $status['remaining'] );
	}

	/**
	 * Without developer mode nothing runs.
	 *
	 * @covers ::alltfo_demo_seed
	 * @covers ::alltfo_demo_remove
	 */
	public function test_the_gate_refuses() {
		remove_filter( 'alltfo_developer_mode', '__return_true' );

		$this->assertWPError( alltfo_demo_seed( 1 ) );
		$this->assertWPError( alltfo_demo_remove() );

		add_filter( 'alltfo_developer_mode', '__return_true' );
	}

	/**
	 * Developer mode alone is not permission.
	 *
	 * The preference says "show me these"; the capability says "you may use
	 * them". A preference is stored in user meta, so treating it as authorisation
	 * would mean anybody who can write their own meta could seed a database.
	 *
	 * @covers ::alltfo_can_use_developer_tools
	 */
	public function test_developer_mode_is_not_a_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertTrue( alltfo_developer_mode(), 'The preference is on for this test.' );
		$this->assertFalse( alltfo_can_use_developer_tools(), 'A subscriber must not be able to seed.' );
		$this->assertWPError( alltfo_demo_seed( 1 ) );
	}
}

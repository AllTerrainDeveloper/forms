<?php
/**
 * Archiving.
 *
 * The promise under test is a round trip: archiving a form takes the form,
 * its entries and its stats out of every list in one move, and unarchiving
 * puts every one of them back *exactly as it was* — a draft comes back a
 * draft, a read entry comes back read, and the stats never move at all.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * Archiving.
 *
 * @group allterrain-forms
 */
class ATF_Test_Archive extends WP_UnitTestCase {

	/**
	 * Entry queries and REST lists are capability-gated.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		atf_add_capabilities();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * A published form with two entries — one deliberately already read, so
	 * the round trip can prove it stays read.
	 *
	 * @return array { form_id: int, entry_ids: int[] }
	 */
	private function seed() {
		$form_id = atf_test_form(
			array(
				'fields' => array(
					array(
						'id'    => 'f1',
						'type'  => 'text',
						'label' => 'Name',
					),
				),
			)
		);

		$schema    = atf_get_form_schema( $form_id );
		$entry_ids = array(
			atf_store_entry( $form_id, $schema, array( 'f1' => 'Ada' ) ),
			atf_store_entry( $form_id, $schema, array( 'f1' => 'Grace' ) ),
		);

		atf_set_entry_status( $entry_ids[1], ATF_STATUS_READ );

		return compact( 'form_id', 'entry_ids' );
	}

	/**
	 * The status round-trips, publish to publish and draft to draft.
	 *
	 * @covers ::atf_archive_form
	 * @covers ::atf_unarchive_form
	 */
	public function test_status_round_trips() {
		$seeded = $this->seed();

		$this->assertTrue( atf_archive_form( $seeded['form_id'] ) );
		$this->assertSame( ATF_STATUS_ARCHIVED, get_post_status( $seeded['form_id'] ) );
		$this->assertTrue( atf_form_is_archived( $seeded['form_id'] ) );

		$this->assertTrue( atf_unarchive_form( $seeded['form_id'] ) );
		$this->assertSame( 'publish', get_post_status( $seeded['form_id'] ) );

		// A draft goes in a draft and comes out a draft — unarchiving must
		// never quietly publish something nobody finished.
		wp_update_post(
			array(
				'ID'          => $seeded['form_id'],
				'post_status' => 'draft',
			)
		);

		atf_archive_form( $seeded['form_id'] );
		atf_unarchive_form( $seeded['form_id'] );

		$this->assertSame( 'draft', get_post_status( $seeded['form_id'] ) );
	}

	/**
	 * The entries leave the all-forms list and come back — with their own
	 * read/unread statuses untouched by the trip.
	 *
	 * @covers ::atf_mark_form_entries_archived
	 */
	public function test_entries_leave_every_global_list_and_return_intact() {
		$seeded = $this->seed();

		// A second, unarchived form proves the filter takes only what it should.
		$other_id    = atf_test_form(
			array(
				'fields' => array(
					array(
						'id'   => 'f1',
						'type' => 'text',
					),
				),
			)
		);
		$other_entry = atf_store_entry( $other_id, atf_get_form_schema( $other_id ), array( 'f1' => 'Alan' ) );

		atf_archive_form( $seeded['form_id'] );

		$all = atf_query_entries( array( 'per_page' => 200 ) );
		$ids = wp_list_pluck( $all['entries'], 'id' );

		$this->assertContains( $other_entry, $ids, 'The other form\'s entry vanished with the wrong archive.' );
		$this->assertNotContains( $seeded['entry_ids'][0], $ids );
		$this->assertNotContains( $seeded['entry_ids'][1], $ids );

		atf_unarchive_form( $seeded['form_id'] );

		$back = wp_list_pluck( atf_query_entries( array( 'per_page' => 200 ) )['entries'], 'id' );

		$this->assertContains( $seeded['entry_ids'][0], $back );
		$this->assertContains( $seeded['entry_ids'][1], $back );

		// The statuses are facts about the entries and must survive the trip.
		$this->assertSame( ATF_STATUS_UNREAD, get_post_status( $seeded['entry_ids'][0] ) );
		$this->assertSame( ATF_STATUS_READ, get_post_status( $seeded['entry_ids'][1] ) );
	}

	/**
	 * The stats never move: they hang off the form and go where it goes.
	 *
	 * @covers ::atf_archive_form
	 */
	public function test_stats_survive_untouched() {
		$seeded = $this->seed();

		atf_bump_stat( $seeded['form_id'], 'views', 120 );
		atf_bump_stat( $seeded['form_id'], 'submissions', 40 );

		$before = atf_get_stats( $seeded['form_id'] );

		atf_archive_form( $seeded['form_id'] );
		atf_unarchive_form( $seeded['form_id'] );

		$this->assertSame( $before, atf_get_stats( $seeded['form_id'] ) );
	}

	/**
	 * An archived form is closed to everyone — the editor bypass included,
	 * because the way past the archive is unarchiving, not previewing.
	 *
	 * @covers ::atf_form_availability
	 */
	public function test_archived_form_is_closed_to_everyone() {
		$seeded = $this->seed();

		atf_archive_form( $seeded['form_id'] );

		$availability = atf_form_availability( $seeded['form_id'] );

		$this->assertFalse( $availability['open'] );
		$this->assertSame( 'archived', $availability['reason'] );

		// The shortcode shows the closed notice, never the form.
		$html = atf_render_form( $seeded['form_id'] );

		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringContainsString( 'archived', $html );

		// And a POST straight at the pipeline is refused the same way.
		$result = atf_process_submission( $seeded['form_id'], array( 'atf' => array( 'f1' => 'sneaky' ) ) );

		$this->assertFalse( $result['success'] );
	}

	/**
	 * The REST list keeps the two sides of the archive apart.
	 *
	 * @covers ::atf_rest_list_forms
	 */
	public function test_rest_list_separates_the_archive() {
		$seeded = $this->seed();
		$other  = atf_test_form(
			array(
				'fields' => array(
					array(
						'id'   => 'f1',
						'type' => 'text',
					),
				),
			),
			'Still active'
		);

		atf_archive_form( $seeded['form_id'] );

		$active = atf_rest_list_forms( new WP_REST_Request() )->get_data();
		$ids    = wp_list_pluck( $active, 'id' );

		$this->assertContains( $other, $ids );
		$this->assertNotContains( $seeded['form_id'], $ids );

		$request = new WP_REST_Request();
		$request->set_param( 'archived', '1' );

		$archived = atf_rest_list_forms( $request )->get_data();

		$this->assertSame( array( $seeded['form_id'] ), wp_list_pluck( $archived, 'id' ) );

		// The archived row still knows what it holds — the entry and view
		// counts are what the restore dialog shows.
		$this->assertSame( 2, $archived[0]['entries'] );
		$this->assertSame( ATF_STATUS_ARCHIVED, $archived[0]['status'] );
	}

	/**
	 * The failure modes refuse loudly instead of half-moving things.
	 *
	 * @covers ::atf_archive_form
	 * @covers ::atf_unarchive_form
	 */
	public function test_wrong_moves_are_refused() {
		$seeded = $this->seed();

		$this->assertWPError( atf_archive_form( 999999 ), 'A missing form cannot be archived.' );
		$this->assertWPError( atf_unarchive_form( $seeded['form_id'] ), 'An active form cannot be unarchived.' );

		atf_archive_form( $seeded['form_id'] );

		$this->assertWPError( atf_archive_form( $seeded['form_id'] ), 'Archiving twice is a refusal, not a no-op.' );
	}
}

<?php
/**
 * Deleting a form.
 *
 * Deletion is the trash, on purpose: a wrong click is a support request, not a
 * loss. What must hold is that a deleted form is *gone* everywhere a visitor
 * looks — the working set, the rendered page, the submission pipeline — while
 * its entries stay, because they are the visitors' words rather than the
 * form's.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * The delete endpoint and what deletion means.
 *
 * @group allterrain-forms
 */
class ALLTFO_Test_Delete_Form extends WP_UnitTestCase {

	/**
	 * A form with one stored entry.
	 *
	 * @return array { form_id: int, entry_id: int }
	 */
	private function seed() {
		$form_id = alltfo_test_form(
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

		$entry_id = alltfo_store_entry(
			$form_id,
			alltfo_get_form_schema( $form_id ),
			array( 'f1' => 'Ada Lovelace' )
		);

		return compact( 'form_id', 'entry_id' );
	}

	/**
	 * Deleting trashes the form and says so.
	 *
	 * @covers ::alltfo_rest_delete_form
	 */
	public function test_delete_trashes_the_form() {
		$seeded = $this->seed();

		$request = new WP_REST_Request( 'DELETE', '/' . ALLTFO_REST_NAMESPACE . '/forms/' . $seeded['form_id'] );
		$request->set_param( 'id', $seeded['form_id'] );

		$fired    = did_action( 'alltfo_form_deleted' );
		$response = alltfo_rest_delete_form( $request );

		$this->assertTrue( $response->get_data()['deleted'] );
		$this->assertSame( 'trash', get_post_status( $seeded['form_id'] ), 'Deletion must be the trash, not a hard delete.' );
		$this->assertSame( $fired + 1, did_action( 'alltfo_form_deleted' ), 'The documented action must fire.' );
	}

	/**
	 * A deleted form leaves the working set but its entries stay.
	 *
	 * @covers ::alltfo_rest_delete_form
	 */
	public function test_entries_survive_their_form() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		alltfo_add_capabilities();

		$seeded = $this->seed();

		$request = new WP_REST_Request( 'DELETE', '/' . ALLTFO_REST_NAMESPACE . '/forms/' . $seeded['form_id'] );
		$request->set_param( 'id', $seeded['form_id'] );

		alltfo_rest_delete_form( $request );

		$listed = alltfo_rest_list_forms( new WP_REST_Request() )->get_data();

		$this->assertNotContains(
			$seeded['form_id'],
			wp_list_pluck( $listed, 'id' ),
			'A deleted form must leave the working set.'
		);

		$this->assertSame(
			ALLTFO_ENTRY_TYPE,
			get_post_type( $seeded['entry_id'] ),
			'The entry must survive its form.'
		);
		$this->assertNotSame( 'trash', get_post_status( $seeded['entry_id'] ) );
	}

	/**
	 * The form type rides the desktop's Recycle Bin; the entry type does not.
	 *
	 * Forms are headless (`show_ui => false`), so the bin only tracks them
	 * because we opt them in — without it, a deleted form is in the trash
	 * with no door back out. Entries stay out on purpose: retention trashes
	 * them in bulk, and a bin full of expired entries buries the one form
	 * somebody actually wants back.
	 *
	 * @covers ::alltfo_recycle_bin_post_types
	 */
	public function test_forms_ride_the_recycle_bin_and_entries_do_not() {
		$types = alltfo_recycle_bin_post_types( array( 'post', 'page' ) );

		$this->assertContains( ALLTFO_FORM_TYPE, $types );
		$this->assertNotContains( ALLTFO_ENTRY_TYPE, $types );
		$this->assertContains( 'post', $types, 'The opt-in must add, never replace.' );
	}

	/**
	 * WordPress's own meta caps resolve on a trashed form.
	 *
	 * The plugin's REST routes check `alltfo_edit_forms` and never ask
	 * `current_user_can( 'edit_post', … )` — but everything outside the
	 * plugin does, the desktop's Recycle Bin first among them. A form
	 * trashed from `publish` resolves `edit_post` to
	 * `edit_published_alltfo_forms` and `delete_post` to
	 * `delete_published_alltfo_forms`; if the capability map stops granting
	 * them, the bin lists trashed forms to nobody and this is the test that
	 * says so.
	 *
	 * @covers ::alltfo_add_capabilities
	 */
	public function test_standard_meta_caps_resolve_on_a_trashed_form() {
		alltfo_add_capabilities();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$seeded = $this->seed();

		wp_trash_post( $seeded['form_id'] );

		$this->assertTrue( current_user_can( 'edit_post', $seeded['form_id'] ), 'The bin lists what you may edit.' );
		$this->assertTrue( current_user_can( 'delete_post', $seeded['form_id'] ), 'Restore and purge check delete_post.' );
	}

	/**
	 * Deleting what does not exist is a 404, not a shrug.
	 *
	 * @covers ::alltfo_rest_delete_form
	 */
	public function test_deleting_nothing_is_a_404() {
		$request = new WP_REST_Request( 'DELETE', '/' . ALLTFO_REST_NAMESPACE . '/forms/999999' );
		$request->set_param( 'id', 999999 );

		$response = alltfo_rest_delete_form( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'alltfo_form_missing', $response->get_error_code() );
	}
}

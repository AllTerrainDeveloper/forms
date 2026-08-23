<?php
/**
 * The Explorer integration: the Forms section, its tiles, its buttons.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * Section registration, capability gating, tile vitals and preview actions.
 *
 * @group allterrain-forms
 */
class ALLTFO_Test_Openstation_Explorer extends WP_UnitTestCase {

	/**
	 * The Forms section appears for somebody with a stake in forms.
	 *
	 * @covers ::alltfo_explorer_entities
	 */
	public function test_section_is_added_for_editors() {
		alltfo_add_capabilities();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$entities = alltfo_explorer_entities( array() );

		$this->assertCount( 1, $entities );
		$this->assertSame( 'alltfo-forms', $entities[0]['id'] );
		$this->assertSame( 'wp/v2/alltfo-forms', $entities[0]['restPath'] );
		$this->assertFalse( $entities[0]['thumbnails'] );
	}

	/**
	 * Somebody with no forms capability gets no section at all.
	 *
	 * @covers ::alltfo_explorer_entities
	 */
	public function test_section_is_withheld_without_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( array(), alltfo_explorer_entities( array() ) );
	}

	/**
	 * Three buttons, each pointing at its own surface and capability.
	 *
	 * @covers ::alltfo_explorer_preview_actions
	 */
	public function test_preview_actions_cover_the_surfaces() {
		$actions = alltfo_explorer_preview_actions( array() );
		$by_id   = wp_list_pluck( $actions, 'capability', 'id' );

		$this->assertSame( 'alltfo_edit_forms', $by_id['allterrain-forms/open-builder'] );
		$this->assertSame( 'alltfo_read_entries', $by_id['allterrain-forms/open-entries'] );
		$this->assertSame( 'alltfo_read_entries', $by_id['allterrain-forms/open-analytics'] );

		foreach ( $actions as $action ) {
			// Scoped by section id AND post type slug, per the Explorer's
			// matching rules — the type matches wherever its section came from.
			$this->assertSame( array( 'alltfo-forms', ALLTFO_FORM_TYPE ), $action['sections'] );
			$this->assertSame( 'allterrain-forms-dock', $action['script'] );
		}
	}

	/**
	 * A form's tile excerpt states its vitals, not its absence of prose.
	 *
	 * @covers ::alltfo_explorer_form_excerpt
	 */
	public function test_tile_excerpt_is_the_vitals() {
		alltfo_add_capabilities();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$form_id = alltfo_create_form_from_template( 'blank', 'Explorer test' );
		$post    = get_post( $form_id );

		$response = new WP_REST_Response(
			array( 'excerpt' => array( 'rendered' => '' ) )
		);

		$filtered = alltfo_explorer_form_excerpt( $response, $post );

		$this->assertStringContainsString( 'question', $filtered->data['excerpt']['rendered'] );
		$this->assertStringContainsString( '0 entries', $filtered->data['excerpt']['rendered'] );
	}
}

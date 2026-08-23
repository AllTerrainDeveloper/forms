<?php
/**
 * Finding the forms, and offering to bring them over.
 *
 * The offer is the part that can go wrong quietly: a notice that keeps asking
 * after being told not to, or one that asks on a site with nothing to import,
 * is the kind of thing people install other plugins to silence. Every clause of
 * the decision is asserted from both sides.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * Detection, the one-click import, and the notice's decision.
 *
 * @group allterrain-forms
 */
class ALLTFO_Test_Import_Notice extends WP_UnitTestCase {

	/**
	 * An administrator, and no memory of a previous survey.
	 */
	public function set_up() {
		parent::set_up();

		alltfo_add_capabilities();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		alltfo_forget_importable_forms();
		delete_option( ALLTFO_IMPORTED_OPTION );
	}

	/**
	 * Leaves nothing cached for the next test.
	 */
	public function tear_down() {
		alltfo_forget_importable_forms();

		parent::tear_down();
	}

	/**
	 * Creates a Contact Form 7 form.
	 *
	 * @param string $title Its title.
	 * @return int The post id.
	 */
	private function cf7_form( $title ) {
		$id = self::factory()->post->create(
			array(
				'post_type'  => 'wpcf7_contact_form',
				'post_title' => $title,
			)
		);

		update_post_meta( $id, '_form', '[text* your-name] [submit "Send"]' );

		return $id;
	}

	/**
	 * Creates a WPForms form.
	 *
	 * @param string $title Its title.
	 * @return int The post id.
	 */
	private function wpforms_form( $title ) {
		return self::factory()->post->create(
			array(
				'post_type'    => 'wpforms',
				'post_title'   => $title,
				'post_content' => wp_slash(
					wp_json_encode(
						array(
							'fields' => array(
								array(
									'id'    => '0',
									'type'  => 'email',
									'label' => 'Email',
								),
							),
						)
					)
				),
			)
		);
	}

	/**
	 * Nothing to import is the answer on an ordinary site.
	 *
	 * @covers ::alltfo_importable_forms
	 * @covers ::alltfo_should_show_import_notice
	 */
	public function test_a_site_with_no_other_forms_is_not_offered_an_import() {
		$this->assertSame( array(), alltfo_importable_forms() );
		$this->assertSame( 0, alltfo_importable_count() );
		$this->assertFalse( alltfo_should_show_import_notice() );
	}

	/**
	 * Each source is counted, and named.
	 *
	 * @covers ::alltfo_importable_forms
	 * @covers ::alltfo_importable_count
	 */
	public function test_every_source_is_found_and_counted() {
		$this->cf7_form( 'Contact' );
		$this->cf7_form( 'Careers' );
		$this->wpforms_form( 'Newsletter' );

		$found = alltfo_importable_forms();

		$this->assertSame( array( 'contact-form-7', 'wpforms' ), array_keys( $found ) );
		$this->assertSame( 2, $found['contact-form-7']['count'] );
		$this->assertSame( 'Contact Form 7', $found['contact-form-7']['label'] );
		$this->assertSame( 1, $found['wpforms']['count'] );
		$this->assertSame( 3, alltfo_importable_count() );
	}

	/**
	 * The survey is cached, and forgotten when it could be wrong.
	 *
	 * @covers ::alltfo_importable_forms
	 * @covers ::alltfo_forget_importable_forms
	 */
	public function test_the_survey_is_cached_until_something_changes() {
		$this->cf7_form( 'Contact' );

		$this->assertSame( 1, alltfo_importable_count() );

		// A second form does not appear while the answer is still cached --
		// which is the point of the cache, and why anything that could change
		// the answer has to drop it.
		$this->cf7_form( 'Careers' );
		$this->assertSame( 1, alltfo_importable_count() );

		alltfo_forget_importable_forms();
		$this->assertSame( 2, alltfo_importable_count() );
	}

	/**
	 * One click imports every form from every source.
	 *
	 * @covers ::alltfo_import_all
	 */
	public function test_import_all_takes_everything() {
		$this->cf7_form( 'Contact' );
		$this->cf7_form( 'Careers' );
		$this->wpforms_form( 'Newsletter' );

		$result = alltfo_import_all();

		$this->assertSame( 3, $result['imported'] );
		$this->assertSame( 0, $result['failed'] );

		$titles = wp_list_pluck(
			get_posts(
				array(
					'post_type'      => ALLTFO_FORM_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => 10,
				)
			),
			'post_title'
		);

		sort( $titles );

		$this->assertSame( array( 'Careers', 'Contact', 'Newsletter' ), $titles );

		// The sources are left exactly as they were.
		$this->assertCount( 2, get_posts( array( 'post_type' => 'wpcf7_contact_form' ) ) );
		$this->assertCount( 1, get_posts( array( 'post_type' => 'wpforms' ) ) );
	}

	/**
	 * The offer is made when there is something to offer.
	 *
	 * @covers ::alltfo_should_show_import_notice
	 */
	public function test_the_offer_is_made_when_forms_are_found() {
		$this->cf7_form( 'Contact' );

		$this->assertTrue( alltfo_should_show_import_notice() );
	}

	/**
	 * "Not now" is remembered, and only for the person who said it.
	 *
	 * @covers ::alltfo_should_show_import_notice
	 */
	public function test_not_now_is_remembered_per_user() {
		$this->cf7_form( 'Contact' );

		update_user_meta( get_current_user_id(), ALLTFO_IMPORT_NOTICE_META, '1' );
		$this->assertFalse( alltfo_should_show_import_notice() );

		// Somebody else's dismissal is not this person's answer.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( alltfo_should_show_import_notice() );
	}

	/**
	 * Once anything has been imported the plugin stops asking.
	 *
	 * @covers ::alltfo_should_show_import_notice
	 * @covers ::alltfo_remember_import
	 */
	public function test_it_stops_asking_after_the_first_import() {
		$this->cf7_form( 'Contact' );
		$this->cf7_form( 'Careers' );

		$this->assertTrue( alltfo_should_show_import_notice() );

		alltfo_import_source_form( 'contact-form-7', (string) $this->cf7_form( 'Another' ) );

		// A form is left unimported, and it still stops -- the notice exists to
		// introduce the importer, and the Import page does the rest.
		$this->assertNotEmpty( get_option( ALLTFO_IMPORTED_OPTION ) );
		$this->assertFalse( alltfo_should_show_import_notice() );
	}

	/**
	 * Somebody who cannot build forms is never offered the import.
	 *
	 * @covers ::alltfo_should_show_import_notice
	 */
	public function test_the_offer_needs_the_capability() {
		$this->cf7_form( 'Contact' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertFalse( alltfo_should_show_import_notice() );
	}

	/**
	 * A site can turn the offer off for everybody, once.
	 *
	 * @covers ::alltfo_should_show_import_notice
	 */
	public function test_a_filter_can_switch_the_offer_off() {
		$this->cf7_form( 'Contact' );

		add_filter( 'alltfo_show_import_notice', '__return_false' );

		$this->assertFalse( alltfo_should_show_import_notice() );
	}

	/**
	 * The notice names one source, and counts out several.
	 *
	 * "4 forms in other plugins" leaves somebody wondering which, and which is
	 * the reason they would click.
	 *
	 * @covers ::alltfo_render_import_notice
	 */
	public function test_it_names_a_single_source_and_itemises_several() {
		// The renderer bails on a screen it does not belong to, so put it on
		// one it does.
		$_GET['page'] = 'allterrain-forms';

		$this->cf7_form( 'Contact' );
		$this->cf7_form( 'Careers' );

		ob_start();
		alltfo_render_import_notice();
		$one = wp_strip_all_tags( ob_get_clean() );

		$this->assertStringContainsString( 'found 2 forms in Contact Form 7', $one );
		$this->assertStringNotContainsString( 'other plugins', $one );

		$this->wpforms_form( 'Newsletter' );
		alltfo_forget_importable_forms();

		ob_start();
		alltfo_render_import_notice();
		$several = wp_strip_all_tags( ob_get_clean() );

		$this->assertStringContainsString( 'found 3 forms in other plugins', $several );
		$this->assertStringContainsString( '2 from Contact Form 7', $several );
		$this->assertStringContainsString( '1 from WPForms', $several );
		$this->assertStringContainsString( 'Import all 3 forms', $several );

		unset( $_GET['page'] );
	}

	/**
	 * The notice keeps off screens it has no business on.
	 *
	 * @covers ::alltfo_import_notice_screen
	 */
	public function test_it_only_appears_where_it_belongs() {
		$GLOBALS['hook_suffix'] = 'plugins.php';
		set_current_screen( 'plugins' );
		$this->assertTrue( alltfo_import_notice_screen(), 'Where an activation lands.' );

		set_current_screen( 'edit-post' );
		$this->assertFalse( alltfo_import_notice_screen(), 'An unrelated screen.' );

		$_GET['page'] = 'allterrain-forms';
		$this->assertTrue( alltfo_import_notice_screen(), 'The builder.' );

		$_GET['page'] = 'allterrain-forms-import';
		$this->assertFalse( alltfo_import_notice_screen(), 'The page it would point at.' );

		unset( $_GET['page'] );
	}
}

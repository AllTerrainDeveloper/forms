<?php
/**
 * Portable form packages: complete configuration, sparse themes, and atomic import.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Portable form behavior.
 *
 * @group allterrain-forms
 */
class ALLTFO_Test_Form_Packages extends WP_UnitTestCase {
	/** Sets up an editor with the plugin's real capabilities. */
	public function set_up() {
		parent::set_up();
		alltfo_add_capabilities();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/** A representative form including every registered field type. */
	private function source() {
		$fields = array();
		foreach ( alltfo_get_field_types() as $type => $definition ) {
			$fields[] = array(
				'id'    => 'f' . count( $fields ),
				'type'  => $type,
				'label' => $definition['label'],
			);
		}
		return alltfo_test_form(
			array(
				'fields'        => $fields,
				'settings'      => array(
					'themeOverrides' => array(
						'accent'      => '#123456',
						'shadow-card' => '0 8px 20px rgba(0,0,0,.2)',
					),
				),
				'notifications' => array(
					array(
						'id'      => 'n1',
						'to'      => 'hello@example.org',
						'message' => "<p>Hello {field:f0}</p>\n<p>Thank you.</p>",
					),
				),
				'confirmations' => array(
					array(
						'id'      => 'c1',
						'message' => 'Done',
						'success' => array( 'style' => 'confetti' ),
					),
				),
				'actions'       => array(
					array(
						'id'       => 'a1',
						'type'     => 'webhook',
						'settings' => array(
							'url'    => 'https://example.org/hook',
							'nested' => array( 'keep' => true ),
						),
					),
				),
			)
		);
	}

	/**
	 * Verifies the portable form contract.
	 *
	 * @covers ::alltfo_export_form_package
	 * @covers ::alltfo_import_form_package
	 */
	public function test_complete_round_trip_without_changing_source() {
		$id      = $this->source();
		$before  = alltfo_get_form_schema( $id );
		$package = alltfo_export_form_package( $id );
		$this->assertNotWPError( $package );
		$this->assertSame( array(), $package['theme']['tokens'] );
		$this->assertSame( $before['settings']['themeOverrides'], $package['form']['schema']['settings']['themeOverrides'] );
		$result = alltfo_import_form_package( $package );
		$this->assertNotWPError( $result );
		$copy = alltfo_get_form_schema( $result['formId'] );
		$this->assertSame( 'draft', get_post_status( $result['formId'] ) );
		$this->assertNotSame( $before['settings']['theme'], $copy['settings']['theme'] );
		$this->assertSame( alltfo_resolve_tokens( 'clean', $before['settings']['themeOverrides'] ), alltfo_resolve_tokens( $copy['settings']['theme'], $copy['settings']['themeOverrides'] ) );
		$copy['settings']['theme'] = 'clean';
		$this->assertSame( $before, $copy );
		$this->assertSame( $before, alltfo_get_form_schema( $id ) );
		$this->assertNotEmpty( $result['warnings'] );
	}

	/**
	 * Verifies the portable form contract.
	 *
	 * @covers ::alltfo_package_theme
	 * @covers ::alltfo_save_theme
	 */
	public function test_every_builtin_uses_sparse_tokens_and_retains_dark_hint() {
		foreach ( alltfo_builtin_themes() as $slug => $theme ) {
			$id      = alltfo_test_form( array( 'settings' => array( 'theme' => $slug ) ) );
			$package = alltfo_export_form_package( $id );
			$this->assertNotWPError( $package );
			$this->assertSame( $slug, $package['theme']['base'] );
			$this->assertSame( array(), $package['theme']['tokens'] );
			$result = alltfo_import_form_package( $package );
			$this->assertNotWPError( $result );
			$copy = alltfo_get_form_schema( $result['formId'] );
			$this->assertSame( alltfo_resolve_tokens( $slug ), alltfo_resolve_tokens( $copy['settings']['theme'] ) );
			$this->assertSame( ! empty( $theme['dark'] ), alltfo_get_theme( $copy['settings']['theme'] )['dark'] );
		}
	}

	/**
	 * Verifies the portable form contract.
	 *
	 * @covers ::alltfo_package_theme
	 * @covers ::alltfo_export_form_package
	 */
	public function test_custom_theme_only_carries_changes_and_survives_source_deletion() {
		$theme   = alltfo_save_theme(
			array(
				'label'  => 'Custom',
				'tokens' => array( 'accent' => '#987654' ),
				'dark'   => true,
			)
		);
		$id      = alltfo_test_form(
			array(
				'settings' => array(
					'theme'          => $theme['slug'],
					'themeOverrides' => array(
						'accent'       => '#987654',
						'radius-field' => '12px',
					),
				),
			)
		);
		$package = alltfo_export_form_package( $id );
		$this->assertNotWPError( $package );
		$this->assertSame( array( 'accent' => '#987654' ), $package['theme']['tokens'] );
		$this->assertSame( array( 'radius-field' => '12px' ), $package['form']['schema']['settings']['themeOverrides'] );
		wp_delete_post( $theme['id'], true );
		$result = alltfo_import_form_package( $package );
		$this->assertNotWPError( $result );
		$copy = alltfo_get_form_schema( $result['formId'] );
		$this->assertSame( '#987654', alltfo_resolve_tokens( $copy['settings']['theme'] )['accent'] );
		$this->assertTrue( alltfo_get_theme( $copy['settings']['theme'] )['dark'] );
	}

	/**
	 * Verifies the portable form contract.
	 *
	 * @covers ::alltfo_validate_form_package
	 * @covers ::alltfo_package_validate_node
	 * @covers ::alltfo_package_check_preserved
	 */
	public function test_invalid_packages_do_not_write_any_posts() {
		$package = alltfo_export_form_package( $this->source() );
		$this->assertNotWPError( $package );
		$mutations = array(
			static function ( &$p ) {
				$p['formatVersion'] = 99; },
			static function ( &$p ) {
				$p['form']['schema']['settings']['ajax'] = 'false'; },
			static function ( &$p ) {
				$p['form']['schema']['fields'][1]['id'] = $p['form']['schema']['fields'][0]['id']; },
			static function ( &$p ) {
				$p['form']['schema']['fields'][0]['type'] = 'not-installed'; },
			static function ( &$p ) {
				$p['form']['schema']['fields'][0]['lable'] = 'Typo'; },
			static function ( &$p ) {
				$p['form']['schema']['settings']['submitLable'] = 'Typo'; },
			static function ( &$p ) {
				$p['theme']['tokens']['accent'] = 'red;display:none'; },
			static function ( &$p ) {
				$p['theme']['tokens']['unknown'] = 'red'; },
			static function ( &$p ) {
				$p['form']['schema']['fields'][0]['logic']['rules'][] = array(
					'field'    => 'missing',
					'operator' => 'is',
					'value'    => '',
				); },
			static function ( &$p ) {
				$p['form']['schema']['notifications'][0]['message'] = '<script>alert(1)</script>'; },
			static function ( &$p ) {
				$p['form']['schema']['fields'][0]['choices'][] = array(
					'label' => 'Image',
					'value' => 'img',
					'image' => 999,
				); },
			static function ( &$p ) {
				$p['theme']['base'] = 'not-installed'; },
			static function ( &$p ) {
				$p['form']['schema']['version'] = 20; },
			static function ( &$p ) {
				$p['form']['schema']['actions'][0]['settings']['constructor'] = array(); },
		);
		$before    = wp_count_posts( ALLTFO_FORM_TYPE );
		$themes    = wp_count_posts( ALLTFO_THEME_TYPE );
		foreach ( $mutations as $mutate ) {
			$bad = $package;
			$mutate( $bad );
			$this->assertWPError( alltfo_import_form_package( $bad ) );
			$this->assertEquals( $before, wp_count_posts( ALLTFO_FORM_TYPE ) );
			$this->assertEquals( $themes, wp_count_posts( ALLTFO_THEME_TYPE ) );
		}
	}

	/**
	 * Verifies the portable form contract.
	 *
	 * @covers ::alltfo_package_walk_fields
	 * @covers ::alltfo_package_validate_fields
	 * @covers ::alltfo_import_form_package
	 */
	public function test_nested_image_choice_is_embedded_and_remapped() {
		$id      = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$form    = alltfo_test_form(
			array(
				'fields' => array(
					array(
						'id'     => 'rows',
						'type'   => 'repeater',
						'fields' => array(
							array(
								'id'      => 'photo',
								'type'    => 'image_choice',
								'choices' => array(
									array(
										'label' => 'Canola',
										'value' => 'canola',
										'image' => $id,
									),
								),
							),
						),
					),
				),
			)
		);
		$package = alltfo_export_form_package( $form );
		$this->assertNotWPError( $package );
		$this->assertCount( 1, $package['assets'] );
		wp_delete_attachment( $id, true );
		$result = alltfo_import_form_package( $package );
		$this->assertNotWPError( $result );
		$schema = alltfo_get_form_schema( $result['formId'] );
		$new_id = $schema['fields'][0]['fields'][0]['choices'][0]['image'];
		$this->assertNotSame( $id, $new_id );
		$this->assertFileExists( get_attached_file( $new_id ) );
		$this->assertSame( $package['assets'][0]['data'], base64_encode( file_get_contents( get_attached_file( $new_id ) ) ) );
		wp_delete_attachment( $new_id, true );
	}

	/**
	 * Verifies the portable form contract.
	 *
	 * @covers ::alltfo_import_form_package
	 */
	public function test_rolls_back_theme_when_form_creation_fails() {
		$package = alltfo_export_form_package( $this->source() );
		$before  = count( alltfo_get_custom_themes() );
		$fail    = static function ( $is_empty, $post ) {
			return ALLTFO_FORM_TYPE === $post['post_type'] || $is_empty;
		};
		add_filter( 'wp_insert_post_empty_content', $fail, 10, 2 );
		$result = alltfo_import_form_package( $package );
		remove_filter( 'wp_insert_post_empty_content', $fail, 10 );
		$this->assertWPError( $result );
		$this->assertCount( $before, alltfo_get_custom_themes() );
	}

	/**
	 * Verifies the portable form contract.
	 *
	 * @covers ::alltfo_rest_validate_form_package
	 * @covers ::alltfo_rest_import_form_package
	 * @covers ::alltfo_rest_export_form_package
	 */
	public function test_rest_dry_run_and_permissions() {
		$id      = $this->source();
		$package = alltfo_export_form_package( $id );
		$before  = wp_count_posts( ALLTFO_FORM_TYPE );
		$request = new WP_REST_Request( 'POST', '/' . ALLTFO_REST_NAMESPACE . '/form-packages/validate' );
		$request->set_param( 'package', $package );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['valid'] );
		$this->assertEquals( $before, wp_count_posts( ALLTFO_FORM_TYPE ) );
		$export = new WP_REST_Request( 'POST', '/' . ALLTFO_REST_NAMESPACE . '/forms/' . $id . '/export' );
		$export->set_param( 'title', 'Unsaved title' );
		$this->assertSame( 'Unsaved title', rest_do_request( $export )->get_data()['form']->title );
		foreach ( array( 'subscriber', null ) as $role ) {
			wp_set_current_user( $role ? self::factory()->user->create( array( 'role' => $role ) ) : 0 );
			$this->assertContains( rest_do_request( $request )->get_status(), array( 401, 403 ) );
			$this->assertContains( rest_do_request( $export )->get_status(), array( 401, 403 ) );
			$import = new WP_REST_Request( 'POST', '/' . ALLTFO_REST_NAMESPACE . '/form-packages/import' );
			$import->set_param( 'package', $package );
			$this->assertContains( rest_do_request( $import )->get_status(), array( 401, 403 ) );
		}
	}
	/**
	 * Hooks can extend export, reject validation, and observe committed imports.
	 *
	 * @covers ::alltfo_validate_form_package
	 * @covers ::alltfo_export_form_package
	 * @covers ::alltfo_import_form_package
	 * @covers ::alltfo_package_object_maps
	 */
	public function test_hooks_and_wire_maps() {
		$id     = alltfo_test_form();
		$filter = static function ( $package, $form_id ) use ( $id ) {
			if ( $form_id === $id ) {
				$package['form']['title'] = 'Filtered export';
			}
			return $package;
		};
		add_filter( 'alltfo_form_package_export', $filter, 10, 2 );
		$package = alltfo_export_form_package( $id );
		remove_filter( 'alltfo_form_package_export', $filter );
		$this->assertSame( 'Filtered export', $package['form']['title'] );
		$wire = alltfo_package_object_maps( $package );
		$this->assertIsObject( $wire->theme->tokens );
		$this->assertIsArray( $wire->form->schema->fields );
		$this->assertNotWPError( alltfo_validate_form_package( $wire ) );
		$seen    = null;
		$observe = static function ( $form_id, $original, $media ) use ( &$seen ) {
			$seen = array( $form_id, $original, $media );
		};
		add_action( 'alltfo_form_package_imported', $observe, 10, 3 );
		$result = alltfo_import_form_package( $package );
		remove_action( 'alltfo_form_package_imported', $observe );
		$this->assertSame( $result['formId'], $seen[0] );
		$this->assertSame( $package, $seen[1] );
		$this->assertSame( array(), $seen[2] );
		$reject = static function () {
			return new WP_Error( 'missing_integration', 'Install integration first.' );
		};
		add_filter( 'alltfo_form_package_validated', $reject );
		$this->assertSame( 'missing_integration', alltfo_import_form_package( $package )->get_error_code() );
		remove_filter( 'alltfo_form_package_validated', $reject );
	}

	/**
	 * Page references cannot accidentally target an unrelated local page ID.
	 *
	 * @covers ::alltfo_import_form_package
	 */
	public function test_foreign_page_confirmation_becomes_original_url_redirect() {
		$id      = alltfo_test_form();
		$package = alltfo_export_form_package( $id );
		$package['form']['schema']['confirmations'] = array(
			array(
				'id'     => 'c1',
				'type'   => 'page',
				'pageId' => 42,
				'query'  => 'name={field:name}',
			),
		);
		$package['pages']                           = array(
			array(
				'id'  => 42,
				'url' => 'https://source.example.org/thanks/',
			),
		);
		$result                                     = alltfo_import_form_package( $package );
		$this->assertNotWPError( $result );
		$copy = alltfo_get_form_schema( $result['formId'] );
		$this->assertSame( 'redirect', $copy['confirmations'][0]['type'] );
		$this->assertSame( 'https://source.example.org/thanks/', $copy['confirmations'][0]['url'] );
		$this->assertSame( 'name={field:name}', $copy['confirmations'][0]['query'] );
	}
}

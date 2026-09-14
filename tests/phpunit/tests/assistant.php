<?php
/**
 * Private MIO editor definition validation and writes.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Assistant REST behavior.
 *
 * @group allterrain-forms
 */
class ALLTFO_Test_Assistant extends WP_UnitTestCase {
	/** Initializes an editor and REST routes. */
	public function set_up() {
		parent::set_up();
		alltfo_add_capabilities();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	/** A conditional editor definition. */
	private function draft() {
		return array(
			'title'  => 'Conditional contact',
			'schema' => array(
				'version'       => 1,
				'fields'        => array(
					array(
						'id'      => 'heard',
						'type'    => 'select',
						'label'   => 'How did you hear about us?',
						'choices' => array(
							array(
								'value' => 'other',
								'label' => 'Other',
							),
							array(
								'value' => 'friend',
								'label' => 'Friend',
							),
						),
					),
					array(
						'id'       => 'details',
						'type'     => 'textarea',
						'required' => true,
						'logic'    => array(
							'enabled' => true,
							'action'  => 'show',
							'match'   => 'all',
							'rules'   => array(
								array(
									'field'    => 'heard',
									'operator' => 'is',
									'value'    => 'other',
								),
							),
						),
					),
				),
				'settings'      => array(
					'theme'          => 'clean',
					'themeOverrides' => array( 'accent' => '#123456' ),
				),
				'notifications' => array(),
				'confirmations' => array(),
				'actions'       => array(),
			),
		);
	}

	/**
	 * Dispatches an authenticated JSON request through actual routes.
	 *
	 * @param string $path Route suffix.
	 * @param array  $data JSON body.
	 * @param string $method HTTP method.
	 * @return WP_REST_Response
	 */
	private function request( $path, $data = array(), $method = 'POST' ) {
		$request = new WP_REST_Request( $method, '/' . ALLTFO_REST_NAMESPACE . '/assistant/' . $path );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $data ) );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Verifies assistant behavior.
	 *
	 * @covers ::alltfo_rest_assistant_validate
	 */
	public function test_validation_is_read_only_and_preserves_conditional_requirement() {
		$before   = (array) wp_count_posts( ALLTFO_FORM_TYPE );
		$response = $this->request( 'validate', array( 'draft' => $this->draft() ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( $before, (array) wp_count_posts( ALLTFO_FORM_TYPE ) );
		$valid = alltfo_validate_assistant_draft( $this->draft() );
		$field = $valid['schema']['fields'][1];
		$this->assertTrue( $field['required'] );
		$this->assertTrue( alltfo_logic_passes( $field['logic'], array( 'heard' => 'other' ), $valid['schema'] ) );
		$this->assertFalse( alltfo_logic_passes( $field['logic'], array( 'heard' => 'friend' ), $valid['schema'] ) );
	}

	/**
	 * Verifies assistant behavior.
	 *
	 * @covers ::alltfo_assistant_error
	 */
	public function test_invalid_boolean_returns_a_location_and_repair() {
		$draft                                    = $this->draft();
		$draft['schema']['fields'][1]['required'] = 'true';
		$response                                 = $this->request( 'validate', array( 'draft' => $draft ) );
		$this->assertSame( 400, $response->get_status() );
		$error = $response->get_data()['data'];
		$this->assertTrue( $error['retryable'] );
		$this->assertStringContainsString( 'required', $error['errors'][0]['path'] );
		$this->assertNotEmpty( $error['errors'][0]['suggestion'] );
	}

	/**
	 * Verifies assistant behavior.
	 *
	 * @covers ::alltfo_validate_assistant_draft
	 */
	public function test_missing_reference_and_unknown_theme_are_rejected() {
		$draft = $this->draft();
		$draft['schema']['fields'][1]['logic']['rules'][0]['field'] = 'does_not_exist';
		$this->assertWPError( alltfo_validate_assistant_draft( $draft ) );
		$draft                                = $this->draft();
		$draft['schema']['settings']['theme'] = 'invented-theme';
		$this->assertWPError( alltfo_validate_assistant_draft( $draft ) );
		$draft = $this->draft();
		$draft['schema']['settings']['themeOverrides']['invented-token'] = '12px';
		$this->assertWPError( alltfo_validate_assistant_draft( $draft ) );
	}

	/**
	 * Verifies assistant behavior.
	 *
	 * @covers ::alltfo_validate_assistant_draft
	 */
	public function test_duplicate_ids_and_dropped_properties_are_rejected() {
		$draft                              = $this->draft();
		$draft['schema']['fields'][1]['id'] = 'heard';
		$this->assertWPError( alltfo_validate_assistant_draft( $draft ) );
		$draft                                        = $this->draft();
		$draft['schema']['fields'][1]['requiredTypo'] = true;
		$this->assertWPError( alltfo_validate_assistant_draft( $draft ) );
	}

	/**
	 * Verifies assistant behavior.
	 *
	 * @covers ::alltfo_rest_assistant_apply
	 */
	public function test_create_draft_and_update_preserve_status_and_other_configuration() {
		$response = $this->request(
			'apply',
			array(
				'draft'    => $this->draft(),
				'formId'   => 0,
				'revision' => '',
			)
		);
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$form = $response->get_data();
		$this->assertSame( 'draft', $form['status'] );
		$id = $form['id'];
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		$draft    = array(
			'title'  => 'Updated title',
			'schema' => alltfo_get_form_schema( $id ),
		);
		$revision = $this->request( 'forms/' . $id, array(), 'GET' )->get_data()['revision'];
		$response = $this->request(
			'apply',
			array(
				'draft'    => $draft,
				'formId'   => $id,
				'revision' => $revision,
			)
		);
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertSame( $draft['schema'], alltfo_get_form_schema( $id ) );
		$this->assertSame( 'Updated title', get_post( $id )->post_title );
	}

	/**
	 * Verifies assistant behavior.
	 *
	 * @covers ::alltfo_assistant_revision
	 */
	public function test_stale_revision_does_not_overwrite_a_newer_form() {
		$id       = alltfo_test_form( $this->draft()['schema'] );
		$revision = alltfo_assistant_revision( $id );
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Someone else edited',
			)
		);
		$response = $this->request(
			'apply',
			array(
				'draft'    => $this->draft(),
				'formId'   => $id,
				'revision' => $revision,
			)
		);
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'Someone else edited', get_post( $id )->post_title );
	}

	/**
	 * Verifies assistant behavior.
	 *
	 * @covers ::alltfo_register_assistant_routes
	 */
	public function test_routes_require_permissions_and_reject_malformed_target_ids() {
		foreach ( array( -1, 'nonsense' ) as $id ) {
			$this->assertSame(
				400,
				$this->request(
					'apply',
					array(
						'draft'    => $this->draft(),
						'formId'   => $id,
						'revision' => '',
					)
				)->get_status()
			);
		}
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'validate', array( 'draft' => $this->draft() ) )->get_status() );
		$this->assertSame(
			401,
			$this->request(
				'apply',
				array(
					'draft'    => $this->draft(),
					'formId'   => 0,
					'revision' => '',
				)
			)->get_status()
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->request( 'validate', array( 'draft' => $this->draft() ) )->get_status() );
	}
	/**
	 * Accepts disabled optional flags, rejects oversized input and honors extension gates.
	 *
	 * @covers ::alltfo_validate_assistant_draft
	 * @covers ::alltfo_package_check_preserved
	 */
	public function test_normalization_equivalence_size_and_extension_gate() {
		$draft                                  = $this->draft();
		$draft['schema']['fields'][0]['inline'] = false;
		$this->assertNotWPError( alltfo_validate_assistant_draft( $draft ) );
		$large          = $draft;
		$large['title'] = str_repeat( 'x', 60001 );
		$this->assertSame( 413, alltfo_validate_assistant_draft( $large )->get_error_data()['status'] );
		$reject = static function () {
			return new WP_Error( 'extension_missing', 'Configure the integration first.', array( 'status' => 400 ) );
		};
		add_filter( 'alltfo_assistant_draft_validated', $reject );
		try {
			$this->assertSame( 'extension_missing', alltfo_validate_assistant_draft( $draft )->get_error_code() );
		} finally {
			remove_filter( 'alltfo_assistant_draft_validated', $reject );
		}
	}
	/**
	 * A confirmed logical operation is durable, user-scoped and cannot duplicate a draft.
	 *
	 * @covers ::alltfo_rest_assistant_apply
	 * @covers ::alltfo_rest_assistant_operation
	 * @covers ::alltfo_assistant_operation_name
	 */
	public function test_durable_operation_replay_payload_binding_and_user_scope() {
		$key   = wp_generate_uuid4() . ':1';
		$body  = array(
			'draft'        => $this->draft(),
			'formId'       => 0,
			'revision'     => '',
			'operationKey' => $key,
		);
		$first = $this->request( 'apply', $body );
		$this->assertSame( 200, $first->get_status(), wp_json_encode( $first->get_data() ) );
		$data = $first->get_data();
		$this->assertNotEmpty( $data['operation']['receipt'] );
		$before = (array) wp_count_posts( ALLTFO_FORM_TYPE );
		$second = $this->request( 'apply', $body );
		$this->assertSame( 200, $second->get_status(), wp_json_encode( $second->get_data() ) );
		$this->assertSame( $data['id'], $second->get_data()['id'] );
		$this->assertSame( $data['operation'], $second->get_data()['operation'] );
		$this->assertSame( $before, (array) wp_count_posts( ALLTFO_FORM_TYPE ) );
		$status = $this->request( 'operations/' . $key, array(), 'GET' )->get_data();
		$this->assertSame( 'confirmed', $status['status'] );
		$this->assertSame( $data['operation']['receipt'], $status['receipt'] );
		$this->assertSame( $data['id'], $status['data']['formId'] );
		$this->assertArrayNotHasKey( 'schema', $status['data'] );
		$body['draft']['title'] = 'Different payload';
		$this->assertSame( 409, $this->request( 'apply', $body )->get_status() );
		$this->assertSame( $before, (array) wp_count_posts( ALLTFO_FORM_TYPE ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 'unknown', $this->request( 'operations/' . $key, array(), 'GET' )->get_data()['status'] );
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'operations/' . $key, array(), 'GET' )->get_status() );
	}

	/**
	 * A running request is never replayed, and stale writes record a no-effect rejection.
	 *
	 * @covers ::alltfo_rest_assistant_apply
	 * @covers ::alltfo_rest_assistant_operation
	 */
	public function test_inflight_operation_and_conflict_status() {
		$key = wp_generate_uuid4() . ':1';
		add_option(
			alltfo_assistant_operation_name( $key ),
			array(
				'status'  => 'running',
				'payload' => 'hash',
			),
			'',
			false
		);
		$body = array(
			'draft'        => $this->draft(),
			'formId'       => 0,
			'revision'     => '',
			'operationKey' => $key,
		);
		$this->assertSame( 409, $this->request( 'apply', $body )->get_status() );
		$this->assertSame( 'unknown', $this->request( 'operations/' . $key, array(), 'GET' )->get_data()['status'] );
		$id                   = alltfo_test_form( $this->draft()['schema'] );
		$key                  = wp_generate_uuid4() . ':2';
		$body['operationKey'] = $key;
		$body['formId']       = $id;
		$body['revision']     = 'outdated';
		$this->assertSame( 409, $this->request( 'apply', $body )->get_status() );
		$status = $this->request( 'operations/' . $key, array(), 'GET' )->get_data();
		$this->assertSame( 'none', $status['effect'] );
		$this->assertSame( 'rejected', $status['status'] );
	}

	/**
	 * Cleanup only deletes names from the assistant namespace.
	 *
	 * @covers ::alltfo_expire_assistant_operation
	 */
	public function test_operation_expiry_is_scoped() {
		$name = alltfo_assistant_operation_name( 'expiry-test' );
		add_option( $name, array( 'status' => 'running' ), '', false );
		add_option( 'unrelated_test_option', 'keep' );
		alltfo_expire_assistant_operation( $name );
		alltfo_expire_assistant_operation( 'unrelated_test_option' );
		$this->assertFalse( get_option( $name ) );
		$this->assertSame( 'keep', get_option( 'unrelated_test_option' ) );
	}
}

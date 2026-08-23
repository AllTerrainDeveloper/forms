<?php
/**
 * The MailPoet bridge.
 *
 * MailPoet is not installed in the test environment, which is itself the first
 * case worth proving: every surface must degrade to a calm "not here" rather
 * than a fatal. The subscribe path is then exercised against a recording stub
 * that answers to the same API the real plugin exposes, because what this
 * plugin must get right — mapping, consent, the already-subscribed fallback —
 * is all on our side of the API line.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * The action, the helpers and the REST payload.
 *
 * @group allterrain-forms
 */
class ALLTFO_Test_MailPoet extends WP_UnitTestCase {

	/**
	 * Loads the stub MailPoet API once for the class.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		require_once __DIR__ . '/../stubs/class-alltfo-mailpoet-stub.php';
	}

	/**
	 * Resets the stub's recordings and behaviour.
	 */
	public function set_up() {
		parent::set_up();

		ALLTFO_MailPoet_Stub::reset();
	}

	/**
	 * The presence gate answers to the class the stub defines.
	 *
	 * @covers ::alltfo_mailpoet_active
	 */
	public function test_mailpoet_reads_as_active_with_the_stub_present() {
		$this->assertTrue( alltfo_mailpoet_active() );
	}

	/**
	 * MailPoet's users/customers segments are not subscribe targets.
	 *
	 * @covers ::alltfo_mailpoet_lists
	 */
	public function test_lists_offers_default_lists_only() {
		ALLTFO_MailPoet_Stub::$lists = array(
			array(
				'id'   => 3,
				'name' => 'Newsletter',
				'type' => 'default',
			),
			array(
				'id'   => 1,
				'name' => 'WordPress Users',
				'type' => 'wp_users',
			),
		);

		$lists = alltfo_mailpoet_lists();

		$this->assertSame(
			array(
				array(
					'id'   => 3,
					'name' => 'Newsletter',
				),
			),
			$lists
		);
	}

	/**
	 * The mapped answers become the subscriber, on the chosen lists.
	 *
	 * @covers ::alltfo_action_mailpoet
	 */
	public function test_subscribes_with_mapped_email_and_names() {
		ALLTFO_MailPoet_Stub::$lists = array(
			array(
				'id'   => 3,
				'name' => 'Newsletter',
				'type' => 'default',
			),
		);

		$result = alltfo_action_mailpoet(
			array(
				'settings' => array(
					'lists'            => array( 3 ),
					'email_field'      => 'email',
					'first_name_field' => 'first',
					'last_name_field'  => 'last',
				),
			),
			array(
				'email' => 'ana@example.com',
				'first' => 'Ana',
				'last'  => 'Torres',
			)
		);

		$this->assertTrue( $result );
		$this->assertCount( 1, ALLTFO_MailPoet_Stub::$added );
		$this->assertSame(
			array(
				'email'      => 'ana@example.com',
				'first_name' => 'Ana',
				'last_name'  => 'Torres',
			),
			ALLTFO_MailPoet_Stub::$added[0]['subscriber']
		);
		$this->assertSame( array( 3 ), ALLTFO_MailPoet_Stub::$added[0]['lists'] );
	}

	/**
	 * An address MailPoet already knows is subscribed to the chosen lists
	 * instead of being refused — the visitor asked to be on the list, and
	 * already having an account is not a reason to say no.
	 *
	 * @covers ::alltfo_action_mailpoet
	 */
	public function test_existing_subscriber_falls_back_to_subscribe_to_lists() {
		ALLTFO_MailPoet_Stub::$add_throws = 'This subscriber already exists.';

		$result = alltfo_action_mailpoet(
			array(
				'settings' => array(
					'lists'       => array( 3 ),
					'email_field' => 'email',
				),
			),
			array( 'email' => 'ana@example.com' )
		);

		$this->assertTrue( $result );
		$this->assertSame( array( 'ana@example.com', array( 3 ) ), ALLTFO_MailPoet_Stub::$subscribed[0] );
	}

	/**
	 * "Already subscribed to these lists" is the visitor asking for something
	 * that is already true — an outcome, not an error to stamp on the entry.
	 *
	 * @covers ::alltfo_action_mailpoet
	 */
	public function test_already_on_the_lists_reads_as_success() {
		ALLTFO_MailPoet_Stub::$add_throws       = 'This subscriber already exists.';
		ALLTFO_MailPoet_Stub::$subscribe_throws = 'This subscriber is already subscribed to these lists.';

		$result = alltfo_action_mailpoet(
			array(
				'settings' => array(
					'lists'       => array( 3 ),
					'email_field' => 'email',
				),
			),
			array( 'email' => 'ana@example.com' )
		);

		$this->assertTrue( $result );
	}

	/**
	 * A submission with no usable address is refused cleanly.
	 *
	 * @covers ::alltfo_action_mailpoet
	 */
	public function test_missing_email_is_an_error_not_a_fatal() {
		$result = alltfo_action_mailpoet(
			array(
				'settings' => array(
					'lists'       => array( 3 ),
					'email_field' => 'email',
				),
			),
			array( 'email' => 'not-an-address' )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'alltfo_mailpoet_no_email', $result->get_error_code() );
		$this->assertCount( 0, ALLTFO_MailPoet_Stub::$added );
	}

	/**
	 * A subscription with nowhere to land is refused cleanly.
	 *
	 * @covers ::alltfo_action_mailpoet
	 */
	public function test_no_lists_selected_is_an_error() {
		$result = alltfo_action_mailpoet(
			array( 'settings' => array( 'email_field' => 'email' ) ),
			array( 'email' => 'ana@example.com' )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'alltfo_mailpoet_no_lists', $result->get_error_code() );
	}

	/**
	 * A genuine refusal is recorded, not swallowed.
	 *
	 * @covers ::alltfo_action_mailpoet
	 */
	public function test_a_real_refusal_surfaces_as_an_error() {
		ALLTFO_MailPoet_Stub::$add_throws       = 'The subscriber could not be created.';
		ALLTFO_MailPoet_Stub::$subscribe_throws = 'The subscriber could not be created.';

		$result = alltfo_action_mailpoet(
			array(
				'settings' => array(
					'lists'       => array( 3 ),
					'email_field' => 'email',
				),
			),
			array( 'email' => 'ana@example.com' )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'alltfo_mailpoet_refused', $result->get_error_code() );
	}

	/**
	 * The whole pipeline: a form with a consent-gated mailpoet action
	 * subscribes when the box is ticked and stays silent when it is not.
	 *
	 * @covers ::alltfo_run_actions
	 */
	public function test_the_action_respects_its_consent_condition() {
		$schema = alltfo_normalize_schema(
			array(
				'fields'  => array(
					array(
						'id'    => 'email',
						'type'  => 'email',
						'label' => 'Email',
					),
					array(
						'id'      => 'optin',
						'type'    => 'checkboxes',
						'label'   => 'Keep me posted',
						'choices' => array(
							array(
								'label' => 'Yes please',
								'value' => 'yes',
							),
						),
					),
				),
				'actions' => array(
					array(
						'id'       => 'mailpoet',
						'type'     => 'mailpoet',
						'enabled'  => true,
						'logic'    => array(
							'enabled' => true,
							'match'   => 'all',
							'rules'   => array(
								array(
									'field'    => 'optin',
									'operator' => 'not_empty',
								),
							),
						),
						'settings' => array(
							'lists'       => array( 3 ),
							'email_field' => 'email',
						),
					),
				),
			)
		);

		alltfo_run_actions(
			$schema,
			array(
				'email' => 'ana@example.com',
				'optin' => array(),
			),
			0,
			0
		);
		$this->assertCount( 0, ALLTFO_MailPoet_Stub::$added, 'An unticked box must not subscribe anyone.' );

		alltfo_run_actions(
			$schema,
			array(
				'email' => 'ana@example.com',
				'optin' => array( 'yes' ),
			),
			0,
			0
		);
		$this->assertCount( 1, ALLTFO_MailPoet_Stub::$added );
	}

	/**
	 * The window boots from this one payload.
	 *
	 * @covers ::alltfo_rest_mailpoet
	 */
	public function test_rest_payload_carries_state_and_lists() {
		ALLTFO_MailPoet_Stub::$lists = array(
			array(
				'id'   => 3,
				'name' => 'Newsletter',
				'type' => 'default',
			),
		);

		$data = alltfo_rest_mailpoet()->get_data();

		$this->assertTrue( $data['active'] );
		$this->assertSame(
			array(
				array(
					'id'   => 3,
					'name' => 'Newsletter',
				),
			),
			$data['lists']
		);
		$this->assertArrayHasKey( 'adminUrl', $data );
		$this->assertArrayHasKey( 'logo', $data );
	}
}

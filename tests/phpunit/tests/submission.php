<?php
/**
 * The submission pipeline, end to end.
 *
 * Everything a form does between "somebody pressed Send" and "there is an entry"
 * — availability, spam screening, storage, notifications and confirmations —
 * goes through `atf_process_submission()`, so this is where the plugin's actual
 * behaviour is pinned.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

class ATF_Test_Submission extends WP_UnitTestCase {

	/**
	 * A form with one required text field and one email field.
	 *
	 * @var int
	 */
	private $form_id;

	/**
	 * Sets up a form and a signed, non-spammy request for each test.
	 */
	public function set_up() {
		parent::set_up();

		$this->form_id = atf_test_form(
			array(
				'fields' => array(
					array(
						'id'       => 'f1',
						'type'     => 'text',
						'label'    => 'Name',
						'required' => true,
					),
					array(
						'id'    => 'f2',
						'type'  => 'email',
						'label' => 'Email',
					),
				),
			)
		);
	}

	/**
	 * A request that passes the spam checks.
	 *
	 * The timestamp is backdated past the time trap and signed, which is what a
	 * real form that somebody actually filled in looks like.
	 *
	 * @param array $values Field values.
	 * @return array A request body.
	 */
	private function request( $values ) {
		$issued = time() - 30;

		return array(
			'atf_form_id' => $this->form_id,
			'atf_nonce'   => wp_create_nonce( 'atf_submit_' . $this->form_id ),
			'atf_t'       => $issued,
			'atf_ts'      => atf_sign_timestamp( $this->form_id, $issued ),
			'atf'         => $values,
		);
	}

	/**
	 * A good submission is stored, with its values.
	 *
	 * @covers ::atf_process_submission
	 * @covers ::atf_store_entry
	 */
	public function test_a_good_submission_is_stored() {
		$result = atf_process_submission(
			$this->form_id,
			$this->request(
				array(
					'f1' => 'Ada Lovelace',
					'f2' => 'ada@example.com',
				)
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( array(), $result['errors'] );
		$this->assertGreaterThan( 0, $result['entry_id'] );

		$entry = get_post( $result['entry_id'] );

		$this->assertSame( ATF_ENTRY_TYPE, $entry->post_type );
		$this->assertSame( ATF_STATUS_UNREAD, $entry->post_status );
		$this->assertSame( $this->form_id, (int) get_post_meta( $entry->ID, ATF_META_FORM, true ) );

		$values = json_decode( get_post_meta( $entry->ID, ATF_META_VALUES, true ), true );

		$this->assertSame( 'Ada Lovelace', $values['f1'] );
		$this->assertSame( 'ada@example.com', $values['f2'] );
	}

	/**
	 * A submission missing a required field is refused, and nothing is stored.
	 *
	 * @covers ::atf_process_submission
	 */
	public function test_validation_failure_stores_nothing() {
		$before = wp_count_posts( ATF_ENTRY_TYPE );

		$result = atf_process_submission( $this->form_id, $this->request( array( 'f1' => '' ) ) );

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'f1', $result['errors'] );
		$this->assertSame( 0, $result['entry_id'] );

		$after = wp_count_posts( ATF_ENTRY_TYPE );

		$this->assertEquals( $before->{ATF_STATUS_UNREAD} ?? 0, $after->{ATF_STATUS_UNREAD} ?? 0 );
	}

	/**
	 * The entry title is built from a meaningful answer.
	 *
	 * @covers ::atf_entry_title
	 */
	public function test_entry_title_is_readable() {
		$result = atf_process_submission( $this->form_id, $this->request( array( 'f1' => 'Grace Hopper' ) ) );

		$this->assertStringContainsString( 'Grace Hopper', get_the_title( $result['entry_id'] ) );
	}

	/**
	 * A submission works through the REST route, not just the function.
	 *
	 * Every other test here calls `atf_process_submission()` directly, which
	 * skips WordPress's own argument validation — and that is exactly where this
	 * plugin once had a bug that no unit test could see: the route declared
	 * `form_id` as required while the form posts `atf_form_id`, so WordPress
	 * rejected every real submission with "Missing parameter(s)" before the
	 * callback ever ran.
	 *
	 * This test goes through `rest_do_request()` with the parameters the rendered
	 * form actually posts, so the route's contract and the markup's contract are
	 * checked against each other.
	 *
	 * @covers ::atf_rest_submit
	 */
	public function test_submitting_through_the_rest_route() {
		$issued = time() - 30;

		$request = new WP_REST_Request( 'POST', '/' . ATF_REST_NAMESPACE . '/submit' );

		$request->set_body_params(
			array(
				'atf_form_id' => $this->form_id,
				'atf_nonce'   => wp_create_nonce( 'atf_submit_' . $this->form_id ),
				'atf_t'       => $issued,
				'atf_ts'      => atf_sign_timestamp( $this->form_id, $issued ),
				'atf'         => array(
					'f1' => 'Through REST',
					'f2' => 'rest@example.com',
				),
			)
		);

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status(), 'The route rejected a submission the form would really send.' );

		$data = $response->get_data();

		$this->assertTrue( $data['success'], wp_json_encode( $data ) );
		$this->assertGreaterThan( 0, $data['entry_id'] );
		$this->assertSame( ATF_STATUS_UNREAD, get_post_status( $data['entry_id'] ) );
	}

	/**
	 * The route's required parameter is the one the renderer emits.
	 *
	 * The other half of the bug above: a rendered form must carry a hidden input
	 * named exactly what `/submit` insists on, or the two drift apart again the
	 * next time either is edited.
	 *
	 * @covers ::atf_render_hidden_fields
	 */
	public function test_the_form_posts_what_the_route_requires() {
		$routes = rest_get_server()->get_routes();
		$route  = $routes[ '/' . ATF_REST_NAMESPACE . '/submit' ][0];

		$required = array();

		foreach ( $route['args'] as $name => $arg ) {
			if ( ! empty( $arg['required'] ) ) {
				$required[] = $name;
			}
		}

		$this->assertNotEmpty( $required );

		$html = atf_render_form( $this->form_id );

		foreach ( $required as $name ) {
			$this->assertStringContainsString(
				sprintf( 'name="%s"', $name ),
				$html,
				sprintf( '/submit requires "%s" but no rendered form posts it.', $name )
			);
		}
	}

	/* ------------------------------------------------------------------ Spam */

	/**
	 * A filled honeypot is spam.
	 *
	 * @covers ::atf_screen_for_spam
	 */
	public function test_honeypot_catches_a_bot() {
		$request                = $this->request( array( 'f1' => 'Bot' ) );
		$request['atf_website'] = 'https://spam.example';

		$result = atf_process_submission( $this->form_id, $request );

		// The visitor is told it worked. Telling a spammer they were caught is
		// how they learn to get past it, and telling a false positive they
		// failed loses a real enquiry twice.
		$this->assertTrue( $result['success'] );
		$this->assertSame( ATF_STATUS_SPAM, get_post_status( $result['entry_id'] ) );
	}

	/**
	 * A submission faster than a human is spam.
	 *
	 * @covers ::atf_screen_for_spam
	 */
	public function test_time_trap_catches_an_instant_submission() {
		$issued = time();

		$result = atf_process_submission(
			$this->form_id,
			array(
				'atf_form_id' => $this->form_id,
				'atf_nonce'   => wp_create_nonce( 'atf_submit_' . $this->form_id ),
				'atf_t'       => $issued,
				'atf_ts'      => atf_sign_timestamp( $this->form_id, $issued ),
				'atf'         => array( 'f1' => 'Fast' ),
			)
		);

		$this->assertSame( ATF_STATUS_SPAM, get_post_status( $result['entry_id'] ) );
	}

	/**
	 * A request with no timestamp at all — a bare field list — is spam.
	 *
	 * @covers ::atf_submission_elapsed
	 */
	public function test_missing_timestamp_is_spam() {
		$result = atf_process_submission(
			$this->form_id,
			array(
				'atf_form_id' => $this->form_id,
				'atf_nonce'   => wp_create_nonce( 'atf_submit_' . $this->form_id ),
				'atf'         => array( 'f1' => 'Scripted' ),
			)
		);

		$this->assertSame( ATF_STATUS_SPAM, get_post_status( $result['entry_id'] ) );
	}

	/**
	 * A forged timestamp cannot defeat the time trap.
	 *
	 * @covers ::atf_submission_elapsed
	 */
	public function test_forged_timestamp_is_rejected() {
		$result = atf_process_submission(
			$this->form_id,
			array(
				'atf_form_id' => $this->form_id,
				'atf_nonce'   => wp_create_nonce( 'atf_submit_' . $this->form_id ),
				// Claims the form was served an hour ago, but the signature is
				// nonsense, so the claim is not believed.
				'atf_t'       => time() - 3600,
				'atf_ts'      => 'made-up-signature',
				'atf'         => array( 'f1' => 'Forged' ),
			)
		);

		$this->assertSame( ATF_STATUS_SPAM, get_post_status( $result['entry_id'] ) );
	}

	/**
	 * A blocked word files the submission as spam.
	 *
	 * @covers ::atf_blocklist_hit
	 */
	public function test_blocklist() {
		$form_id = atf_test_form(
			array(
				'fields'   => array(
					array(
						'id'   => 'f1',
						'type' => 'textarea',
					),
				),
				'settings' => array(
					'spam' => array( 'blocklist' => "casino\ncrypto" ),
				),
			)
		);

		$issued = time() - 30;

		$result = atf_process_submission(
			$form_id,
			array(
				'atf_form_id' => $form_id,
				'atf_nonce'   => wp_create_nonce( 'atf_submit_' . $form_id ),
				'atf_t'       => $issued,
				'atf_ts'      => atf_sign_timestamp( $form_id, $issued ),
				'atf'         => array( 'f1' => 'Buy CRYPTO now' ),
			)
		);

		$this->assertSame( ATF_STATUS_SPAM, get_post_status( $result['entry_id'] ) );
	}

	/**
	 * The arithmetic challenge accepts a right answer and refuses a wrong one.
	 *
	 * @covers ::atf_challenge_answered
	 */
	public function test_challenge() {
		$form_id = atf_test_form(
			array(
				'fields'   => array(
					array(
						'id'   => 'f1',
						'type' => 'text',
					),
				),
				'settings' => array(
					'spam' => array( 'challenge' => true ),
				),
			)
		);

		$issued = time() - 30;

		$send = function ( $answer, $signature ) use ( $form_id, $issued ) {
			return atf_process_submission(
				$form_id,
				array(
					'atf_form_id'       => $form_id,
					'atf_nonce'         => wp_create_nonce( 'atf_submit_' . $form_id ),
					'atf_t'             => $issued,
					'atf_ts'            => atf_sign_timestamp( $form_id, $issued ),
					'atf_challenge'     => $answer,
					'atf_challenge_sig' => $signature,
					'atf'               => array( 'f1' => 'Human' ),
				)
			);
		};

		$right = $send( '12', atf_sign_challenge( $form_id, 12 ) );

		$this->assertSame( ATF_STATUS_UNREAD, get_post_status( $right['entry_id'] ) );

		// A wrong answer, correctly signed for a *different* number.
		$wrong = $send( '11', atf_sign_challenge( $form_id, 12 ) );

		$this->assertSame( ATF_STATUS_SPAM, get_post_status( $wrong['entry_id'] ) );

		// No answer at all, which is what a script that never read the question
		// sends.
		$absent = $send( '', '' );

		$this->assertSame( ATF_STATUS_SPAM, get_post_status( $absent['entry_id'] ) );
	}

	/**
	 * A challenge signature cannot be replayed on another form.
	 *
	 * @covers ::atf_sign_challenge
	 */
	public function test_challenge_signature_is_per_form() {
		$this->assertNotSame(
			atf_sign_challenge( 1, 12 ),
			atf_sign_challenge( 2, 12 ),
			'A signature valid on one form must not be valid on another.'
		);
	}

	/**
	 * The challenge never sends its own answer to the browser.
	 *
	 * A challenge whose expected answer travels alongside the question is
	 * decoration.
	 *
	 * @covers ::atf_render_challenge
	 */
	public function test_challenge_does_not_leak_its_answer() {
		$form_id = atf_test_form(
			array(
				'fields'   => array(
					array(
						'id'   => 'f1',
						'type' => 'text',
					),
				),
				'settings' => array(
					'spam' => array( 'challenge' => true ),
				),
			)
		);

		$html = atf_render_form( $form_id );

		$this->assertStringContainsString( 'atf_challenge', $html );
		$this->assertStringContainsString( 'atf_challenge_sig', $html );

		// The question is "What is A plus B?"; the answer must appear nowhere in
		// the markup as a value.
		preg_match( '/What is (\d+) plus (\d+)\?/', $html, $matches );

		$this->assertNotEmpty( $matches, 'The challenge question was not rendered.' );

		$answer = (int) $matches[1] + (int) $matches[2];

		$this->assertStringNotContainsString(
			sprintf( 'value="%d"', $answer ),
			$html,
			'The challenge printed its own answer.'
		);
	}

	/* ------------------------------------------------------------ Gate-keeping */

	/**
	 * A closed form refuses submissions, not just renders.
	 *
	 * A closed form that still accepts a POST is a form that is not closed.
	 *
	 * @covers ::atf_form_availability
	 */
	public function test_a_closed_form_refuses_a_post() {
		// A user who cannot edit forms, because anyone who can is deliberately
		// never locked out by a schedule.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$form_id = atf_test_form(
			array(
				'fields'   => array(
					array(
						'id'   => 'f1',
						'type' => 'text',
					),
				),
				'settings' => array(
					'schedule' => array(
						'end'     => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
						'message' => 'This has closed.',
					),
				),
			)
		);

		$issued = time() - 30;

		$result = atf_process_submission(
			$form_id,
			array(
				'atf_form_id' => $form_id,
				'atf_nonce'   => wp_create_nonce( 'atf_submit_' . $form_id ),
				'atf_t'       => $issued,
				'atf_ts'      => atf_sign_timestamp( $form_id, $issued ),
				'atf'         => array( 'f1' => 'Too late' ),
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'This has closed.', $result['message'] );
		$this->assertSame( 0, $result['entry_id'] );
	}

	/**
	 * A login-only form refuses a logged-out visitor.
	 *
	 * @covers ::atf_form_availability
	 */
	public function test_login_required() {
		wp_set_current_user( 0 );

		$form_id = atf_test_form(
			array(
				'fields'   => array(
					array(
						'id'   => 'f1',
						'type' => 'text',
					),
				),
				'settings' => array( 'requireLogin' => true ),
			)
		);

		$availability = atf_form_availability( $form_id );

		$this->assertFalse( $availability['open'] );
		$this->assertSame( 'login', $availability['reason'] );
	}

	/**
	 * Somebody who can edit forms is never locked out by a schedule.
	 *
	 * They are the person who needs to test the form, and a closed notice with
	 * no way past it is how a scheduling bug survives to launch day.
	 *
	 * @covers ::atf_form_availability
	 */
	public function test_editors_can_always_reach_a_closed_form() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		atf_add_capabilities();

		$form_id = atf_test_form(
			array(
				'settings' => array(
					'schedule' => array( 'end' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ),
				),
			)
		);

		$this->assertTrue( atf_form_availability( $form_id )['open'] );
	}

	/**
	 * A submission limit closes the form once it is reached.
	 *
	 * @covers ::atf_form_availability
	 */
	public function test_total_submission_limit() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$form_id = atf_test_form(
			array(
				'fields'   => array(
					array(
						'id'   => 'f1',
						'type' => 'text',
					),
				),
				'settings' => array(
					'limit' => array(
						'total'   => 1,
						'message' => 'All full.',
					),
				),
			)
		);

		$send = function () use ( $form_id ) {
			$issued = time() - 30;

			return atf_process_submission(
				$form_id,
				array(
					'atf_form_id' => $form_id,
					'atf_nonce'   => wp_create_nonce( 'atf_submit_' . $form_id ),
					'atf_t'       => $issued,
					'atf_ts'      => atf_sign_timestamp( $form_id, $issued ),
					'atf'         => array( 'f1' => 'Someone' ),
				)
			);
		};

		$this->assertTrue( $send()['success'] );

		$second = $send();

		$this->assertFalse( $second['success'] );
		$this->assertSame( 'All full.', $second['message'] );
	}

	/* ------------------------------------------------------------- Behaviour */

	/**
	 * Storage can be switched off entirely.
	 *
	 * @covers ::atf_process_submission
	 */
	public function test_storage_can_be_off() {
		$form_id = atf_test_form(
			array(
				'fields'   => array(
					array(
						'id'   => 'f1',
						'type' => 'text',
					),
				),
				'settings' => array(
					'storage' => array( 'entries' => false ),
				),
			)
		);

		$issued = time() - 30;

		$result = atf_process_submission(
			$form_id,
			array(
				'atf_form_id' => $form_id,
				'atf_nonce'   => wp_create_nonce( 'atf_submit_' . $form_id ),
				'atf_t'       => $issued,
				'atf_ts'      => atf_sign_timestamp( $form_id, $issued ),
				'atf'         => array( 'f1' => 'Not kept' ),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['entry_id'] );
	}

	/**
	 * A password is never written into an entry.
	 *
	 * @covers ::atf_store_entry
	 */
	public function test_passwords_are_never_stored() {
		$form_id = atf_test_form(
			array(
				'fields' => array(
					array(
						'id'   => 'u',
						'type' => 'text',
					),
					array(
						'id'   => 'p',
						'type' => 'password',
					),
				),
			)
		);

		$issued = time() - 30;

		$result = atf_process_submission(
			$form_id,
			array(
				'atf_form_id' => $form_id,
				'atf_nonce'   => wp_create_nonce( 'atf_submit_' . $form_id ),
				'atf_t'       => $issued,
				'atf_ts'      => atf_sign_timestamp( $form_id, $issued ),
				'atf'         => array(
					'u' => 'ada',
					'p' => 'correct horse battery staple',
				),
			)
		);

		$stored = get_post_meta( $result['entry_id'], ATF_META_VALUES, true );

		$this->assertStringNotContainsString( 'correct horse battery staple', $stored );
		$this->assertArrayNotHasKey( 'p', json_decode( $stored, true ) );
	}

	/**
	 * `atf_entry_created` fires with the entry and the values.
	 *
	 * @covers ::atf_process_submission
	 */
	public function test_entry_created_action_fires() {
		$seen = array();

		add_action(
			'atf_entry_created',
			static function ( $entry_id, $form_id, $values ) use ( &$seen ) {
				$seen = compact( 'entry_id', 'form_id', 'values' );
			},
			10,
			3
		);

		$result = atf_process_submission( $this->form_id, $this->request( array( 'f1' => 'Hooked' ) ) );

		$this->assertSame( $result['entry_id'], $seen['entry_id'] );
		$this->assertSame( $this->form_id, $seen['form_id'] );
		$this->assertSame( 'Hooked', $seen['values']['f1'] );
	}

	/**
	 * A confirmation is resolved and returned.
	 *
	 * @covers ::atf_resolve_confirmation
	 */
	public function test_default_confirmation() {
		$result = atf_process_submission( $this->form_id, $this->request( array( 'f1' => 'Ada' ) ) );

		$this->assertSame( 'message', $result['confirmation']['type'] );
		$this->assertStringContainsString( 'Thank you', $result['confirmation']['message'] );
	}

	/**
	 * Conditional confirmations pick the first that matches.
	 *
	 * @covers ::atf_resolve_confirmation
	 */
	public function test_conditional_confirmation() {
		$form_id = atf_test_form(
			array(
				'fields'        => array(
					array(
						'id'      => 'why',
						'type'    => 'select',
						'choices' => array(
							array( 'label' => 'Support', 'value' => 'support' ),
							array( 'label' => 'Sales', 'value' => 'sales' ),
						),
					),
				),
				'confirmations' => array(
					array(
						'id'      => 'c1',
						'type'    => 'message',
						'message' => 'Support will be in touch.',
						'logic'   => array(
							'enabled' => true,
							'action'  => 'show',
							'match'   => 'all',
							'rules'   => array(
								array(
									'field'    => 'why',
									'operator' => 'is',
									'value'    => 'support',
								),
							),
						),
					),
					array(
						'id'      => 'c2',
						'type'    => 'message',
						'message' => 'Sales will be in touch.',
					),
				),
			)
		);

		$send = function ( $value ) use ( $form_id ) {
			$issued = time() - 30;

			return atf_process_submission(
				$form_id,
				array(
					'atf_form_id' => $form_id,
					'atf_nonce'   => wp_create_nonce( 'atf_submit_' . $form_id ),
					'atf_t'       => $issued,
					'atf_ts'      => atf_sign_timestamp( $form_id, $issued ),
					'atf'         => array( 'why' => $value ),
				)
			);
		};

		$this->assertStringContainsString( 'Support', $send( 'support' )['confirmation']['message'] );
		$this->assertStringContainsString( 'Sales', $send( 'sales' )['confirmation']['message'] );
	}

	/**
	 * A notification is sent, with the answers in it.
	 *
	 * @covers ::atf_send_notifications
	 */
	public function test_notification_is_sent() {
		reset_phpmailer_instance();

		atf_process_submission(
			$this->form_id,
			$this->request(
				array(
					'f1' => 'Ada Lovelace',
					'f2' => 'ada@example.com',
				)
			)
		);

		$mailer = tests_retrieve_phpmailer_instance();
		$sent   = $mailer->get_sent();

		$this->assertNotFalse( $sent, 'A form with no notifications configured must still email the administrator.' );
		$this->assertStringContainsString( 'Ada Lovelace', $sent->body );
		$this->assertStringContainsString( get_option( 'admin_email' ), $sent->header . $sent->to[0][0] );
	}

	/**
	 * Spam never reaches anybody's inbox.
	 *
	 * @covers ::atf_process_submission
	 */
	public function test_spam_is_not_emailed() {
		reset_phpmailer_instance();

		$request                = $this->request( array( 'f1' => 'Bot' ) );
		$request['atf_website'] = 'https://spam.example';

		atf_process_submission( $this->form_id, $request );

		$this->assertFalse(
			tests_retrieve_phpmailer_instance()->get_sent(),
			'A submission filed as spam must not be emailed.'
		);
	}

	/**
	 * Analytics count a submission but not a builder's own preview.
	 *
	 * @covers ::atf_record_submission
	 */
	public function test_submission_is_counted() {
		$before = atf_get_stats( $this->form_id )['submissions'];

		atf_process_submission( $this->form_id, $this->request( array( 'f1' => 'Counted' ) ) );

		$this->assertSame( $before + 1, atf_get_stats( $this->form_id )['submissions'] );
	}

	/**
	 * A preview runs the whole pipeline and stores nothing.
	 *
	 * @covers ::atf_process_submission
	 */
	public function test_preview_stores_nothing() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		atf_add_capabilities();

		$request                = $this->request( array( 'f1' => 'Previewing' ) );
		$request['atf_preview'] = '1';

		$result = atf_process_submission( $this->form_id, $request );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['entry_id'] );
		$this->assertTrue( $result['preview'] );
	}

	/**
	 * A preview still validates, so what it shows is what a visitor would get.
	 *
	 * @covers ::atf_process_submission
	 */
	public function test_preview_still_validates() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		atf_add_capabilities();

		$request                = $this->request( array( 'f1' => '' ) );
		$request['atf_preview'] = '1';

		$result = atf_process_submission( $this->form_id, $request );

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'f1', $result['errors'] );
	}
}

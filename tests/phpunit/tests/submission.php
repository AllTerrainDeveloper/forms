<?php
/**
 * The submission pipeline, end to end.
 *
 * Everything a form does between "somebody pressed Send" and "there is an entry"
 * — availability, spam screening, storage, notifications and confirmations —
 * goes through `alltfo_process_submission()`, so this is where the plugin's actual
 * behaviour is pinned.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * The submission pipeline.
 *
 * @group allterrain-forms
 */
class ALLTFO_Test_Submission extends WP_UnitTestCase {

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

		$this->form_id = alltfo_test_form(
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
			'alltfo_form_id' => $this->form_id,
			'alltfo_nonce'   => wp_create_nonce( 'alltfo_submit_' . $this->form_id ),
			'alltfo_t'       => $issued,
			'alltfo_ts'      => alltfo_sign_timestamp( $this->form_id, $issued ),
			'atf'         => $values,
		);
	}

	/**
	 * A good submission is stored, with its values.
	 *
	 * @covers ::alltfo_process_submission
	 * @covers ::alltfo_store_entry
	 */
	public function test_a_good_submission_is_stored() {
		$result = alltfo_process_submission(
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

		$this->assertSame( ALLTFO_ENTRY_TYPE, $entry->post_type );
		$this->assertSame( ALLTFO_STATUS_UNREAD, $entry->post_status );
		$this->assertSame( $this->form_id, (int) get_post_meta( $entry->ID, ALLTFO_META_FORM, true ) );

		$values = json_decode( get_post_meta( $entry->ID, ALLTFO_META_VALUES, true ), true );

		$this->assertSame( 'Ada Lovelace', $values['f1'] );
		$this->assertSame( 'ada@example.com', $values['f2'] );
	}

	/**
	 * A submission missing a required field is refused, and nothing is stored.
	 *
	 * @covers ::alltfo_process_submission
	 */
	public function test_validation_failure_stores_nothing() {
		$before = wp_count_posts( ALLTFO_ENTRY_TYPE );

		$result = alltfo_process_submission( $this->form_id, $this->request( array( 'f1' => '' ) ) );

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'f1', $result['errors'] );
		$this->assertSame( 0, $result['entry_id'] );

		$after = wp_count_posts( ALLTFO_ENTRY_TYPE );

		$this->assertEquals( $before->{ALLTFO_STATUS_UNREAD} ?? 0, $after->{ALLTFO_STATUS_UNREAD} ?? 0 );
	}

	/**
	 * The entry title is built from a meaningful answer.
	 *
	 * @covers ::alltfo_entry_title
	 */
	public function test_entry_title_is_readable() {
		$result = alltfo_process_submission( $this->form_id, $this->request( array( 'f1' => 'Grace Hopper' ) ) );

		$this->assertStringContainsString( 'Grace Hopper', get_the_title( $result['entry_id'] ) );
	}

	/**
	 * A submission works through the REST route, not just the function.
	 *
	 * Every other test here calls `alltfo_process_submission()` directly, which
	 * skips WordPress's own argument validation — and that is exactly where this
	 * plugin once had a bug that no unit test could see: the route declared
	 * `form_id` as required while the form posts `alltfo_form_id`, so WordPress
	 * rejected every real submission with "Missing parameter(s)" before the
	 * callback ever ran.
	 *
	 * This test goes through `rest_do_request()` with the parameters the rendered
	 * form actually posts, so the route's contract and the markup's contract are
	 * checked against each other.
	 *
	 * @covers ::alltfo_rest_submit
	 */
	public function test_submitting_through_the_rest_route() {
		$issued = time() - 30;

		$request = new WP_REST_Request( 'POST', '/' . ALLTFO_REST_NAMESPACE . '/submit' );

		$request->set_body_params(
			array(
				'alltfo_form_id' => $this->form_id,
				'alltfo_nonce'   => wp_create_nonce( 'alltfo_submit_' . $this->form_id ),
				'alltfo_t'       => $issued,
				'alltfo_ts'      => alltfo_sign_timestamp( $this->form_id, $issued ),
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
		$this->assertSame( ALLTFO_STATUS_UNREAD, get_post_status( $data['entry_id'] ) );
	}

	/**
	 * The route's required parameter is the one the renderer emits.
	 *
	 * The other half of the bug above: a rendered form must carry a hidden input
	 * named exactly what `/submit` insists on, or the two drift apart again the
	 * next time either is edited.
	 *
	 * @covers ::alltfo_render_hidden_fields
	 */
	public function test_the_form_posts_what_the_route_requires() {
		$routes = rest_get_server()->get_routes();
		$route  = $routes[ '/' . ALLTFO_REST_NAMESPACE . '/submit' ][0];

		$required = array();

		foreach ( $route['args'] as $name => $arg ) {
			if ( ! empty( $arg['required'] ) ) {
				$required[] = $name;
			}
		}

		$this->assertNotEmpty( $required );

		$html = alltfo_render_form( $this->form_id );

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
	 * @covers ::alltfo_screen_for_spam
	 */
	public function test_honeypot_catches_a_bot() {
		$request                = $this->request( array( 'f1' => 'Bot' ) );
		$request['alltfo_website'] = 'https://spam.example';

		$result = alltfo_process_submission( $this->form_id, $request );

		// The visitor is told it worked. Telling a spammer they were caught is
		// how they learn to get past it, and telling a false positive they
		// failed loses a real enquiry twice.
		$this->assertTrue( $result['success'] );
		$this->assertSame( ALLTFO_STATUS_SPAM, get_post_status( $result['entry_id'] ) );
	}

	/**
	 * A submission faster than a human is spam.
	 *
	 * @covers ::alltfo_screen_for_spam
	 */
	public function test_time_trap_catches_an_instant_submission() {
		$issued = time();

		$result = alltfo_process_submission(
			$this->form_id,
			array(
				'alltfo_form_id' => $this->form_id,
				'alltfo_nonce'   => wp_create_nonce( 'alltfo_submit_' . $this->form_id ),
				'alltfo_t'       => $issued,
				'alltfo_ts'      => alltfo_sign_timestamp( $this->form_id, $issued ),
				'atf'         => array( 'f1' => 'Fast' ),
			)
		);

		$this->assertSame( ALLTFO_STATUS_SPAM, get_post_status( $result['entry_id'] ) );
	}

	/**
	 * A request with no timestamp at all — a bare field list — is spam.
	 *
	 * @covers ::alltfo_submission_elapsed
	 */
	public function test_missing_timestamp_is_spam() {
		$result = alltfo_process_submission(
			$this->form_id,
			array(
				'alltfo_form_id' => $this->form_id,
				'alltfo_nonce'   => wp_create_nonce( 'alltfo_submit_' . $this->form_id ),
				'atf'         => array( 'f1' => 'Scripted' ),
			)
		);

		$this->assertSame( ALLTFO_STATUS_SPAM, get_post_status( $result['entry_id'] ) );
	}

	/**
	 * A forged timestamp cannot defeat the time trap.
	 *
	 * @covers ::alltfo_submission_elapsed
	 */
	public function test_forged_timestamp_is_rejected() {
		$result = alltfo_process_submission(
			$this->form_id,
			array(
				'alltfo_form_id' => $this->form_id,
				'alltfo_nonce'   => wp_create_nonce( 'alltfo_submit_' . $this->form_id ),
				// Claims the form was served an hour ago, but the signature is
				// nonsense, so the claim is not believed.
				'alltfo_t'       => time() - 3600,
				'alltfo_ts'      => 'made-up-signature',
				'atf'         => array( 'f1' => 'Forged' ),
			)
		);

		$this->assertSame( ALLTFO_STATUS_SPAM, get_post_status( $result['entry_id'] ) );
	}

	/**
	 * A blocked word files the submission as spam.
	 *
	 * @covers ::alltfo_blocklist_hit
	 */
	public function test_blocklist() {
		$form_id = alltfo_test_form(
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

		$result = alltfo_process_submission(
			$form_id,
			array(
				'alltfo_form_id' => $form_id,
				'alltfo_nonce'   => wp_create_nonce( 'alltfo_submit_' . $form_id ),
				'alltfo_t'       => $issued,
				'alltfo_ts'      => alltfo_sign_timestamp( $form_id, $issued ),
				'atf'         => array( 'f1' => 'Buy CRYPTO now' ),
			)
		);

		$this->assertSame( ALLTFO_STATUS_SPAM, get_post_status( $result['entry_id'] ) );
	}

	/**
	 * The arithmetic challenge accepts a right answer and refuses a wrong one.
	 *
	 * @covers ::alltfo_challenge_answered
	 */
	public function test_challenge() {
		$form_id = alltfo_test_form(
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
			return alltfo_process_submission(
				$form_id,
				array(
					'alltfo_form_id'       => $form_id,
					'alltfo_nonce'         => wp_create_nonce( 'alltfo_submit_' . $form_id ),
					'alltfo_t'             => $issued,
					'alltfo_ts'            => alltfo_sign_timestamp( $form_id, $issued ),
					'alltfo_challenge'     => $answer,
					'alltfo_challenge_sig' => $signature,
					'atf'               => array( 'f1' => 'Human' ),
				)
			);
		};

		$right = $send( '12', alltfo_sign_challenge( $form_id, 12 ) );

		$this->assertSame( ALLTFO_STATUS_UNREAD, get_post_status( $right['entry_id'] ) );

		// A wrong answer, correctly signed for a *different* number.
		$wrong = $send( '11', alltfo_sign_challenge( $form_id, 12 ) );

		$this->assertSame( ALLTFO_STATUS_SPAM, get_post_status( $wrong['entry_id'] ) );

		// No answer at all, which is what a script that never read the question
		// sends.
		$absent = $send( '', '' );

		$this->assertSame( ALLTFO_STATUS_SPAM, get_post_status( $absent['entry_id'] ) );
	}

	/**
	 * A challenge signature expires with its hour bucket.
	 *
	 * The signed material carries the hour it was issued in, and the verifier
	 * accepts only the current and the previous hour -- so a captured
	 * (answer, signature) pair stops replaying within two hours instead of
	 * working forever.
	 *
	 * @covers ::alltfo_challenge_answered
	 * @covers ::alltfo_sign_challenge
	 */
	public function test_challenge_signature_expires() {
		$request = static function ( $signature ) {
			return array(
				'alltfo_form_id'       => 7,
				'alltfo_challenge'     => '12',
				'alltfo_challenge_sig' => $signature,
			);
		};

		$this->assertTrue(
			alltfo_challenge_answered( $request( alltfo_sign_challenge( 7, 12 ) ) ),
			'A signature from the current hour must verify.'
		);

		$this->assertTrue(
			alltfo_challenge_answered( $request( alltfo_sign_challenge( 7, 12, gmdate( 'YmdH', time() - HOUR_IN_SECONDS ) ) ) ),
			'A form rendered just before the hour rolled over must still submit.'
		);

		$this->assertFalse(
			alltfo_challenge_answered( $request( alltfo_sign_challenge( 7, 12, gmdate( 'YmdH', time() - 2 * HOUR_IN_SECONDS ) ) ) ),
			'A signature from two hours ago must have expired.'
		);
	}

	/**
	 * A challenge signature cannot be replayed on another form.
	 *
	 * @covers ::alltfo_sign_challenge
	 */
	public function test_challenge_signature_is_per_form() {
		$this->assertNotSame(
			alltfo_sign_challenge( 1, 12 ),
			alltfo_sign_challenge( 2, 12 ),
			'A signature valid on one form must not be valid on another.'
		);
	}

	/**
	 * The challenge never sends its own answer to the browser.
	 *
	 * A challenge whose expected answer travels alongside the question is
	 * decoration.
	 *
	 * @covers ::alltfo_render_challenge
	 */
	public function test_challenge_does_not_leak_its_answer() {
		$form_id = alltfo_test_form(
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

		$html = alltfo_render_form( $form_id );

		$this->assertStringContainsString( 'alltfo_challenge', $html );
		$this->assertStringContainsString( 'alltfo_challenge_sig', $html );

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
	 * @covers ::alltfo_form_availability
	 */
	public function test_a_closed_form_refuses_a_post() {
		// A user who cannot edit forms, because anyone who can is deliberately
		// never locked out by a schedule.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$form_id = alltfo_test_form(
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

		$result = alltfo_process_submission(
			$form_id,
			array(
				'alltfo_form_id' => $form_id,
				'alltfo_nonce'   => wp_create_nonce( 'alltfo_submit_' . $form_id ),
				'alltfo_t'       => $issued,
				'alltfo_ts'      => alltfo_sign_timestamp( $form_id, $issued ),
				'atf'         => array( 'f1' => 'Too late' ),
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'This has closed.', $result['message'] );
		$this->assertSame( 0, $result['entry_id'] );
	}

	/**
	 * A deleted form refuses a POST, even one built while it was alive.
	 *
	 * A visitor's tab can outlive the form on it: the nonce and timestamp were
	 * issued honestly, and only the trash status says the form is gone.
	 *
	 * @covers ::alltfo_process_submission
	 */
	public function test_a_deleted_form_refuses_a_post() {
		$form_id = alltfo_test_form(
			array(
				'fields' => array(
					array(
						'id'   => 'f1',
						'type' => 'text',
					),
				),
			)
		);

		$issued  = time() - 30;
		$request = array(
			'alltfo_form_id' => $form_id,
			'alltfo_nonce'   => wp_create_nonce( 'alltfo_submit_' . $form_id ),
			'alltfo_t'       => $issued,
			'alltfo_ts'      => alltfo_sign_timestamp( $form_id, $issued ),
			'atf'            => array( 'f1' => 'Posted into the void' ),
		);

		wp_trash_post( $form_id );

		$result = alltfo_process_submission( $form_id, $request );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['entry_id'] );
	}

	/**
	 * A login-only form refuses a logged-out visitor.
	 *
	 * @covers ::alltfo_form_availability
	 */
	public function test_login_required() {
		wp_set_current_user( 0 );

		$form_id = alltfo_test_form(
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

		$availability = alltfo_form_availability( $form_id );

		$this->assertFalse( $availability['open'] );
		$this->assertSame( 'login', $availability['reason'] );
	}

	/**
	 * A role-restricted form is closed to a logged-out visitor.
	 *
	 * Roles can be set without `requireLogin`, and a visitor with no account
	 * holds no role at all -- logging out must not be the way past the role
	 * gate.
	 *
	 * @covers ::alltfo_form_availability
	 */
	public function test_role_restriction_closes_the_form_to_logged_out_visitors() {
		wp_set_current_user( 0 );

		$form_id = alltfo_test_form(
			array(
				'fields'   => array(
					array(
						'id'   => 'f1',
						'type' => 'text',
					),
				),
				'settings' => array( 'roles' => array( 'editor' ) ),
			)
		);

		$availability = alltfo_form_availability( $form_id );

		$this->assertFalse( $availability['open'] );
		$this->assertSame( 'role', $availability['reason'] );

		// The gate closes on the missing role, not on everyone: a visitor who
		// holds the role gets through.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertTrue( alltfo_form_availability( $form_id )['open'] );
	}

	/**
	 * Somebody who can edit forms is never locked out by a schedule.
	 *
	 * They are the person who needs to test the form, and a closed notice with
	 * no way past it is how a scheduling bug survives to launch day.
	 *
	 * @covers ::alltfo_form_availability
	 */
	public function test_editors_can_always_reach_a_closed_form() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		alltfo_add_capabilities();

		$form_id = alltfo_test_form(
			array(
				'settings' => array(
					'schedule' => array( 'end' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ),
				),
			)
		);

		$this->assertTrue( alltfo_form_availability( $form_id )['open'] );
	}

	/**
	 * A submission limit closes the form once it is reached.
	 *
	 * @covers ::alltfo_form_availability
	 */
	public function test_total_submission_limit() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$form_id = alltfo_test_form(
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

			return alltfo_process_submission(
				$form_id,
				array(
					'alltfo_form_id' => $form_id,
					'alltfo_nonce'   => wp_create_nonce( 'alltfo_submit_' . $form_id ),
					'alltfo_t'       => $issued,
					'alltfo_ts'      => alltfo_sign_timestamp( $form_id, $issued ),
					'atf'         => array( 'f1' => 'Someone' ),
				)
			);
		};

		$this->assertTrue( $send()['success'] );

		$second = $send();

		$this->assertFalse( $second['success'] );
		$this->assertSame( 'All full.', $second['message'] );
	}

	/* --------------------------------------------------------------- Uploads */

	/**
	 * A `$_FILES`-shaped entry backed by a real temporary file.
	 *
	 * @param string $name     The client-side file name.
	 * @param string $contents The file's bytes.
	 * @return array One `$_FILES` entry.
	 */
	private function fake_upload( $name, $contents ) {
		$tmp = tempnam( get_temp_dir(), 'atf' );

		file_put_contents( $tmp, $contents );

		return array(
			'name'     => $name,
			'type'     => '',
			'tmp_name' => $tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $contents ),
		);
	}

	/**
	 * Lets `wp_handle_upload()` accept a file the CLI created.
	 *
	 * `move_uploaded_file()` refuses anything that did not arrive over HTTP,
	 * which no file a test creates can. The sideload action moves with `copy`
	 * instead, and everything else in the pipeline runs unchanged.
	 */
	private function allow_cli_uploads() {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		add_filter(
			'alltfo_upload_overrides',
			static function ( $overrides ) {
				$overrides['action'] = 'wp_handle_sideload';

				return $overrides;
			}
		);
	}

	/**
	 * A submission that fails validation does not keep the files it uploaded.
	 *
	 * Uploads become attachments before validation runs -- a broken file fails
	 * a submission too -- so a refused submission has to delete them, or every
	 * failed attempt leaves an orphan on disk forever.
	 *
	 * @covers ::alltfo_process_submission
	 * @covers ::alltfo_delete_upload_attachments
	 */
	public function test_a_submission_that_fails_validation_deletes_its_uploads() {
		$this->allow_cli_uploads();

		$form_id = alltfo_test_form(
			array(
				'fields' => array(
					array(
						'id'       => 'f1',
						'type'     => 'text',
						'label'    => 'Name',
						'required' => true,
					),
					array(
						'id'        => 'f9',
						'type'      => 'file',
						'label'     => 'Attachment',
						'filetypes' => array( 'txt' ),
					),
				),
			)
		);

		$uploaded = 0;

		add_action(
			'alltfo_file_uploaded',
			static function ( $attachment_id ) use ( &$uploaded ) {
				$uploaded = $attachment_id;
			}
		);

		$issued = time() - 30;

		// The required text field is empty, so validation refuses the
		// submission after the upload has already been stored.
		$result = alltfo_process_submission(
			$form_id,
			array(
				'alltfo_form_id' => $form_id,
				'alltfo_nonce'   => wp_create_nonce( 'alltfo_submit_' . $form_id ),
				'alltfo_t'       => $issued,
				'alltfo_ts'      => alltfo_sign_timestamp( $form_id, $issued ),
				'atf'         => array( 'f1' => '' ),
			),
			array( 'alltfo_file_f9' => $this->fake_upload( 'notes.txt', 'Plain text.' ) )
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'f1', $result['errors'] );
		$this->assertGreaterThan( 0, $uploaded, 'The upload itself must succeed for this test to prove anything.' );
		$this->assertNull( get_post( $uploaded ), 'A rejected submission orphaned its upload.' );
	}

	/**
	 * One field's refused upload takes another field's stored upload with it.
	 *
	 * The whole submission fails, so the attachments the successful field
	 * already created must not stay behind.
	 *
	 * @covers ::alltfo_process_submission
	 * @covers ::alltfo_delete_upload_attachments
	 */
	public function test_an_upload_error_deletes_the_other_fields_uploads() {
		$this->allow_cli_uploads();

		$form_id = alltfo_test_form(
			array(
				'fields' => array(
					array(
						'id'        => 'ok',
						'type'      => 'file',
						'label'     => 'Fine',
						'filetypes' => array( 'txt' ),
					),
					array(
						'id'    => 'bad',
						'type'  => 'file',
						'label' => 'Refused',
					),
				),
			)
		);

		$uploaded = 0;

		add_action(
			'alltfo_file_uploaded',
			static function ( $attachment_id ) use ( &$uploaded ) {
				$uploaded = $attachment_id;
			}
		);

		$issued = time() - 30;

		// The second field's file wears a forbidden extension, so it is refused
		// before it touches disk -- after the first field's file was stored.
		$result = alltfo_process_submission(
			$form_id,
			array(
				'alltfo_form_id' => $form_id,
				'alltfo_nonce'   => wp_create_nonce( 'alltfo_submit_' . $form_id ),
				'alltfo_t'       => $issued,
				'alltfo_ts'      => alltfo_sign_timestamp( $form_id, $issued ),
				'atf'         => array(),
			),
			array(
				'alltfo_file_ok'  => $this->fake_upload( 'notes.txt', 'Plain text.' ),
				'alltfo_file_bad' => $this->fake_upload( 'evil.php', '<?php' ),
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'bad', $result['errors'] );
		$this->assertGreaterThan( 0, $uploaded, 'The first field\'s upload must succeed for this test to prove anything.' );
		$this->assertNull( get_post( $uploaded ), 'A failed sibling upload orphaned the stored one.' );
	}

	/* ------------------------------------------------------------- Behaviour */

	/**
	 * Storage can be switched off entirely.
	 *
	 * @covers ::alltfo_process_submission
	 */
	public function test_storage_can_be_off() {
		$form_id = alltfo_test_form(
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

		$result = alltfo_process_submission(
			$form_id,
			array(
				'alltfo_form_id' => $form_id,
				'alltfo_nonce'   => wp_create_nonce( 'alltfo_submit_' . $form_id ),
				'alltfo_t'       => $issued,
				'alltfo_ts'      => alltfo_sign_timestamp( $form_id, $issued ),
				'atf'         => array( 'f1' => 'Not kept' ),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['entry_id'] );
	}

	/**
	 * A password is never written into an entry.
	 *
	 * @covers ::alltfo_store_entry
	 */
	public function test_passwords_are_never_stored() {
		$form_id = alltfo_test_form(
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

		$result = alltfo_process_submission(
			$form_id,
			array(
				'alltfo_form_id' => $form_id,
				'alltfo_nonce'   => wp_create_nonce( 'alltfo_submit_' . $form_id ),
				'alltfo_t'       => $issued,
				'alltfo_ts'      => alltfo_sign_timestamp( $form_id, $issued ),
				'atf'         => array(
					'u' => 'ada',
					'p' => 'correct horse battery staple',
				),
			)
		);

		$stored = get_post_meta( $result['entry_id'], ALLTFO_META_VALUES, true );

		$this->assertStringNotContainsString( 'correct horse battery staple', $stored );
		$this->assertArrayNotHasKey( 'p', json_decode( $stored, true ) );
	}

	/**
	 * `alltfo_entry_created` fires with the entry and the values.
	 *
	 * @covers ::alltfo_process_submission
	 */
	public function test_entry_created_action_fires() {
		$seen = array();

		add_action(
			'alltfo_entry_created',
			static function ( $entry_id, $form_id, $values ) use ( &$seen ) {
				$seen = compact( 'entry_id', 'form_id', 'values' );
			},
			10,
			3
		);

		$result = alltfo_process_submission( $this->form_id, $this->request( array( 'f1' => 'Hooked' ) ) );

		$this->assertSame( $result['entry_id'], $seen['entry_id'] );
		$this->assertSame( $this->form_id, $seen['form_id'] );
		$this->assertSame( 'Hooked', $seen['values']['f1'] );
	}

	/**
	 * A confirmation is resolved and returned.
	 *
	 * @covers ::alltfo_resolve_confirmation
	 */
	public function test_default_confirmation() {
		$result = alltfo_process_submission( $this->form_id, $this->request( array( 'f1' => 'Ada' ) ) );

		$this->assertSame( 'message', $result['confirmation']['type'] );
		$this->assertStringContainsString( 'Thank you', $result['confirmation']['message'] );
	}

	/**
	 * A redirect query only carries scalar values.
	 *
	 * `parse_str()` builds nested arrays from bracketed keys, and the query is
	 * built from merge tags -- visitor text -- so an array is reachable from
	 * outside. `rawurlencode()` on one is a fatal on PHP 8; the array is
	 * dropped and the rest of the query survives.
	 *
	 * @covers ::alltfo_confirmation_url
	 */
	public function test_confirmation_query_drops_nested_values() {
		$confirmation = array_merge(
			alltfo_default_confirmation(),
			array(
				'type'  => 'redirect',
				'url'   => 'https://example.com/thanks',
				'query' => 'a[b]=nested&plain=ok',
			)
		);

		$url = alltfo_confirmation_url( $confirmation, array() );

		$this->assertStringContainsString( 'plain=ok', $url );
		$this->assertStringNotContainsString( 'nested', $url, 'A nested value has no defensible flattening and is dropped.' );
	}

	/**
	 * Conditional confirmations pick the first that matches.
	 *
	 * @covers ::alltfo_resolve_confirmation
	 */
	public function test_conditional_confirmation() {
		$form_id = alltfo_test_form(
			array(
				'fields'        => array(
					array(
						'id'      => 'why',
						'type'    => 'select',
						'choices' => array(
							array(
								'label' => 'Support',
								'value' => 'support',
							),
							array(
								'label' => 'Sales',
								'value' => 'sales',
							),
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

			return alltfo_process_submission(
				$form_id,
				array(
					'alltfo_form_id' => $form_id,
					'alltfo_nonce'   => wp_create_nonce( 'alltfo_submit_' . $form_id ),
					'alltfo_t'       => $issued,
					'alltfo_ts'      => alltfo_sign_timestamp( $form_id, $issued ),
					'atf'         => array( 'why' => $value ),
				)
			);
		};

		$this->assertStringContainsString( 'Support', $send( 'support' )['confirmation']['message'] );
		$this->assertStringContainsString( 'Sales', $send( 'sales' )['confirmation']['message'] );
	}

	/**
	 * Captures what the plugin asks WordPress to send, without sending it.
	 *
	 * `pre_wp_mail` short-circuits before PHPMailer is involved at all, which is
	 * what makes these assertions about *this plugin* rather than about the
	 * machine running them.
	 *
	 * The mock mailer was the obvious tool and the wrong one. It records what
	 * PHPMailer accepted, so it records nothing when PHPMailer refuses the
	 * message for a reason that has nothing to do with us — and the default From
	 * address is built from the site's own domain, which differs between a
	 * WordPress develop checkout and a `wp-env` container. The test passed on one
	 * and failed on the other with identical plugin code.
	 *
	 * Worse was the mirror image: `test_spam_is_not_emailed()` asserts that
	 * *nothing* was sent, so in an environment where nothing can ever be sent it
	 * passed while proving nothing. A test that cannot fail is not protecting the
	 * behaviour it names.
	 *
	 * Returns an `ArrayObject` rather than an array, and that is load-bearing: an
	 * array returned from here is a *copy*, so the closure would go on appending
	 * to this function's own local while the caller inspected an empty one — and
	 * a capture that always reports nothing makes the negative test pass for free
	 * and the positive test fail for no reason. An object is a handle.
	 *
	 * @return ArrayObject Collected `wp_mail()` argument arrays, filled as they are sent.
	 */
	private function capture_mail() {
		$sent = new ArrayObject();

		add_filter(
			'pre_wp_mail',
			static function ( $short_circuit, $atts ) use ( $sent ) {
				$sent[] = $atts;

				// True, not null: the caller is told the mail was handled, so
				// nothing further runs and no transport is touched.
				return true;
			},
			10,
			2
		);

		return $sent;
	}

	/**
	 * A notification is sent, with the answers in it.
	 *
	 * @covers ::alltfo_send_notifications
	 */
	public function test_notification_is_sent() {
		$sent = $this->capture_mail();

		alltfo_process_submission(
			$this->form_id,
			$this->request(
				array(
					'f1' => 'Ada Lovelace',
					'f2' => 'ada@example.com',
				)
			)
		);

		$this->assertCount( 1, $sent, 'A form with no notifications configured must still email the administrator.' );

		$mail = $sent[0];
		$to   = is_array( $mail['to'] ) ? implode( ',', $mail['to'] ) : (string) $mail['to'];

		$this->assertStringContainsString( get_option( 'admin_email' ), $to );
		$this->assertStringContainsString( 'Ada Lovelace', $mail['message'] );
		$this->assertNotEmpty( $mail['subject'], 'A notification with no subject is a notification nobody opens.' );
	}

	/**
	 * Spam never reaches anybody's inbox.
	 *
	 * @covers ::alltfo_process_submission
	 */
	public function test_spam_is_not_emailed() {
		$sent = $this->capture_mail();

		$request                = $this->request( array( 'f1' => 'Bot' ) );
		$request['alltfo_website'] = 'https://spam.example';

		alltfo_process_submission( $this->form_id, $request );

		// Captured through `pre_wp_mail` rather than read off the mock mailer,
		// so this asserts that nothing was *attempted* — in an environment where
		// nothing can be delivered, the mock is empty either way and the test
		// would pass without the plugin doing anything right.
		$this->assertSame( array(), $sent->getArrayCopy(), 'A submission filed as spam must not be emailed.' );
	}

	/**
	 * Analytics count a submission but not a builder's own preview.
	 *
	 * @covers ::alltfo_record_submission
	 */
	public function test_submission_is_counted() {
		$before = alltfo_get_stats( $this->form_id )['submissions'];

		alltfo_process_submission( $this->form_id, $this->request( array( 'f1' => 'Counted' ) ) );

		$this->assertSame( $before + 1, alltfo_get_stats( $this->form_id )['submissions'] );
	}

	/**
	 * A preview runs the whole pipeline and stores nothing.
	 *
	 * @covers ::alltfo_process_submission
	 */
	public function test_preview_stores_nothing() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		alltfo_add_capabilities();

		$request                = $this->request( array( 'f1' => 'Previewing' ) );
		$request['alltfo_preview'] = '1';

		$result = alltfo_process_submission( $this->form_id, $request );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['entry_id'] );
		$this->assertTrue( $result['preview'] );
	}

	/**
	 * A preview still validates, so what it shows is what a visitor would get.
	 *
	 * @covers ::alltfo_process_submission
	 */
	public function test_preview_still_validates() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		alltfo_add_capabilities();

		$request                = $this->request( array( 'f1' => '' ) );
		$request['alltfo_preview'] = '1';

		$result = alltfo_process_submission( $this->form_id, $request );

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'f1', $result['errors'] );
	}
}

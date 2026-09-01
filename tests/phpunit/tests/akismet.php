<?php
/**
 * Akismet is opt-in per form, on every code path.
 *
 * The WordPress.org guidelines call unrequested off-site traffic "phoning home",
 * and a form's Akismet switch is the only thing that may open that door. These
 * tests hold both doors shut: the check on submission and the spam/ham
 * correction from the entries screen. A form that never opted in must produce
 * zero requests to Akismet, however the Akismet plugin itself is configured.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * Akismet requests, counted.
 *
 * @group allterrain-forms
 */
class ALLTFO_Test_Akismet extends WP_UnitTestCase {

	/**
	 * Loads the recording stand-in for the Akismet plugin once.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		require_once __DIR__ . '/../stubs/class-akismet.php';
	}

	/**
	 * A blank recorder, an administrator, and an Akismet plugin that *is*
	 * configured -- so the only thing standing between an entry and Akismet's
	 * servers is the form's own switch.
	 */
	public function set_up() {
		parent::set_up();

		Akismet::reset();
		Akismet::$api_key = 'test-key';

		alltfo_add_capabilities();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Leaves the stand-in unconfigured for whichever test file runs next.
	 */
	public function tear_down() {
		Akismet::reset();

		parent::tear_down();
	}

	/**
	 * A form with one stored entry.
	 *
	 * Every other spam check is switched off so that, when Akismet is on, the
	 * screening reaches it rather than stopping at the honeypot or time trap.
	 *
	 * @param bool $akismet Whether the form opts in to Akismet.
	 * @return array { form_id: int, schema: array, entry_id: int }
	 */
	private function seed( $akismet ) {
		$form_id = alltfo_test_form(
			array(
				'fields'   => array(
					array(
						'id'    => 'f1',
						'type'  => 'text',
						'label' => 'Name',
					),
					array(
						'id'    => 'f2',
						'type'  => 'email',
						'label' => 'Email',
					),
				),
				'settings' => array(
					'spam' => array(
						'honeypot'  => false,
						'timeTrap'  => 0,
						'rateLimit' => 0,
						'blocklist' => '',
						'challenge' => false,
						'akismet'   => $akismet,
					),
				),
			)
		);

		$schema   = alltfo_get_form_schema( $form_id );
		$entry_id = alltfo_store_entry(
			$form_id,
			$schema,
			array(
				'f1' => 'Ada Lovelace',
				'f2' => 'ada@example.com',
			)
		);

		return compact( 'form_id', 'schema', 'entry_id' );
	}

	/**
	 * The paths of every request made so far.
	 *
	 * @return string[]
	 */
	private function paths() {
		return wp_list_pluck( Akismet::$calls, 'path' );
	}

	/**
	 * The gate reads the form's switch and nothing else.
	 *
	 * @covers ::alltfo_form_uses_akismet
	 */
	public function test_the_gate_is_the_form_switch() {
		$this->assertFalse( alltfo_form_uses_akismet( alltfo_default_schema() ), 'A fresh form has Akismet off.' );
		$this->assertFalse( alltfo_form_uses_akismet( array() ), 'A schema with no spam settings at all has Akismet off.' );
		$this->assertTrue( alltfo_form_uses_akismet( $this->seed( true )['schema'] ) );
		$this->assertFalse( alltfo_form_uses_akismet( $this->seed( false )['schema'] ) );
	}

	/**
	 * Marking an entry spam, then not-spam, on a form that never opted in.
	 *
	 * This is the case the directory review flagged: the correction used to
	 * check only whether Akismet was installed, so a click in the entries
	 * screen shipped the entry's answers, IP and user agent off-site for a form
	 * whose owner had never agreed to that.
	 *
	 * @covers ::alltfo_akismet_submit_correction
	 * @covers ::alltfo_set_entry_status
	 */
	public function test_corrections_send_nothing_when_the_form_has_akismet_off() {
		$seeded = $this->seed( false );

		$this->assertTrue( alltfo_set_entry_status( $seeded['entry_id'], ALLTFO_STATUS_SPAM ) );
		$this->assertSame( ALLTFO_STATUS_SPAM, get_post_status( $seeded['entry_id'] ), 'The status still changes locally.' );

		$this->assertTrue( alltfo_set_entry_status( $seeded['entry_id'], ALLTFO_STATUS_READ ) );
		$this->assertSame( ALLTFO_STATUS_READ, get_post_status( $seeded['entry_id'] ) );

		$this->assertSame( array(), Akismet::$calls, 'A form with Akismet off must never contact Akismet, even when the plugin is configured.' );
		$this->assertFalse( alltfo_akismet_submit_correction( $seeded['entry_id'], 'spam' ), 'Called directly, the correction reports that nothing was sent.' );
		$this->assertSame( array(), Akismet::$calls );
	}

	/**
	 * The same two clicks on a form that did opt in reach Akismet, once each.
	 *
	 * @covers ::alltfo_akismet_submit_correction
	 * @covers ::alltfo_set_entry_status
	 */
	public function test_corrections_are_sent_when_the_form_has_akismet_on() {
		$seeded = $this->seed( true );

		alltfo_set_entry_status( $seeded['entry_id'], ALLTFO_STATUS_SPAM );
		$this->assertSame( array( 'submit-spam' ), $this->paths() );

		alltfo_set_entry_status( $seeded['entry_id'], ALLTFO_STATUS_SPAM );
		$this->assertSame( array( 'submit-spam' ), $this->paths(), 'Marking spam twice is not two corrections.' );

		alltfo_set_entry_status( $seeded['entry_id'], ALLTFO_STATUS_READ );
		$this->assertSame( array( 'submit-spam', 'submit-ham' ), $this->paths() );

		$this->assertSame( 'contact-form', Akismet::$calls[0]['request']['comment_type'] );
		$this->assertStringContainsString( 'Ada Lovelace', Akismet::$calls[0]['request']['comment_content'] );
	}

	/**
	 * The correction consults the form before it consults Akismet.
	 *
	 * With the form opted in but Akismet unconfigured, nothing is sent either --
	 * and a correction for an entry with no form (not an entry at all) is a
	 * quiet no-op rather than a request.
	 *
	 * @covers ::alltfo_akismet_submit_correction
	 */
	public function test_corrections_need_both_the_form_and_a_configured_akismet() {
		$seeded = $this->seed( true );

		Akismet::$api_key = '';

		$this->assertFalse( alltfo_akismet_submit_correction( $seeded['entry_id'], 'spam' ) );

		Akismet::$api_key = 'test-key';

		$this->assertFalse( alltfo_akismet_submit_correction( self::factory()->post->create(), 'spam' ), 'A post that is not an entry has no form, so no opt-in.' );
		$this->assertSame( array(), Akismet::$calls );
	}

	/**
	 * Screening a submission respects the same switch.
	 *
	 * @covers ::alltfo_screen_for_spam
	 * @covers ::alltfo_akismet_says_spam
	 */
	public function test_screening_asks_akismet_only_when_the_form_opts_in() {
		$values  = array(
			'f1' => 'Ada Lovelace',
			'f2' => 'ada@example.com',
		);
		$request = array();

		$off = $this->seed( false );

		$verdict = alltfo_screen_for_spam( $off['schema'], $values, $request );

		$this->assertFalse( $verdict['spam'] );
		$this->assertSame( array(), Akismet::$calls, 'Akismet off: no comment-check request.' );

		Akismet::$body = 'true';

		$on = $this->seed( true );

		$verdict = alltfo_screen_for_spam( $on['schema'], $values, $request );

		$this->assertSame( array( 'comment-check' ), $this->paths(), 'Akismet on: exactly one comment-check request.' );
		$this->assertTrue( $verdict['spam'] );
		$this->assertSame( 'akismet', $verdict['reason'] );
		$this->assertSame( 'ada@example.com', Akismet::$calls[0]['request']['comment_author_email'] );
	}
}

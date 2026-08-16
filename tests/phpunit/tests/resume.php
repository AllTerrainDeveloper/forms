<?php
/**
 * Save and continue later.
 *
 * The resume token is a bearer credential — anyone holding it can read the
 * half-finished answers behind it — so most of these tests are about the ways it
 * must not be guessable, reusable past its expiry, or left lying around after it
 * has served its purpose.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * Save and continue later.
 *
 * @group allterrain-forms
 */
class ATF_Test_Resume extends WP_UnitTestCase {

	/**
	 * A form with saving switched on.
	 *
	 * @param array $settings Extra resume settings.
	 * @return int The form id.
	 */
	private function resumable_form( $settings = array() ) {
		return atf_test_form(
			array(
				'fields'   => array(
					array(
						'id'       => 'f1',
						'type'     => 'text',
						'label'    => 'Name',
						'required' => true,
					),
					array(
						'id'    => 'f2',
						'type'  => 'textarea',
						'label' => 'Story',
					),
				),
				'settings' => array(
					'resume' => array_merge(
						array(
							'enabled' => true,
							'days'    => 30,
						),
						$settings
					),
				),
			)
		);
	}

	/**
	 * A half-finished form saves, without being validated.
	 *
	 * That is the whole point: a partial is by definition missing required
	 * answers, and refusing it because of that would make the feature useless.
	 *
	 * @covers ::atf_save_partial
	 */
	public function test_a_partial_saves_without_validating() {
		$form_id = $this->resumable_form();

		$saved = atf_save_partial( $form_id, array( 'f2' => 'Half a story' ) );

		$this->assertNotWPError( $saved );
		$this->assertNotEmpty( $saved['token'] );
		$this->assertStringContainsString( $saved['token'], $saved['url'] );
	}

	/**
	 * A form that does not offer saving refuses to.
	 *
	 * @covers ::atf_save_partial
	 */
	public function test_saving_is_refused_when_switched_off() {
		$form_id = atf_test_form(
			array(
				'fields' => array(
					array(
						'id'   => 'f1',
						'type' => 'text',
					),
				),
			)
		);

		$this->assertWPError( atf_save_partial( $form_id, array( 'f1' => 'x' ) ) );
	}

	/**
	 * A closed form does not accept partials either.
	 *
	 * Otherwise "save for later" is a way to keep writing into a form that has
	 * shut.
	 *
	 * @covers ::atf_save_partial
	 */
	public function test_a_closed_form_refuses_a_partial() {
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
					'resume'   => array( 'enabled' => true ),
					'schedule' => array( 'end' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ),
				),
			)
		);

		$this->assertWPError( atf_save_partial( $form_id, array( 'f1' => 'x' ) ) );
	}

	/**
	 * The saved values come back.
	 *
	 * @covers ::atf_resume_values
	 */
	public function test_a_partial_can_be_resumed() {
		$form_id = $this->resumable_form();

		$saved = atf_save_partial(
			$form_id,
			array(
				'f1' => 'Ada',
				'f2' => 'Half a story',
			)
		);

		$resumed = atf_resume_values( $saved['token'] );

		$this->assertSame( $form_id, $resumed['form_id'] );
		$this->assertSame( 'Ada', $resumed['values']['f1'] );
		$this->assertSame( 'Half a story', $resumed['values']['f2'] );
	}

	/**
	 * Values are sanitised on the way in, even though they are not validated.
	 *
	 * They are about to be stored and later echoed back into a page.
	 *
	 * @covers ::atf_save_partial
	 */
	public function test_partial_values_are_sanitised() {
		$form_id = $this->resumable_form();

		$saved = atf_save_partial( $form_id, array( 'f1' => '<script>alert(1)</script>Ada' ) );

		$resumed = atf_resume_values( $saved['token'] );

		$this->assertStringNotContainsString( '<script', $resumed['values']['f1'] );
	}

	/**
	 * Saving twice updates one partial rather than leaving a trail.
	 *
	 * @covers ::atf_save_partial
	 */
	public function test_saving_twice_updates_in_place() {
		$form_id = $this->resumable_form();

		$first = atf_save_partial( $form_id, array( 'f1' => 'First' ) );
		$again = atf_save_partial( $form_id, array( 'f1' => 'Second' ), $first['token'] );

		$this->assertSame( $first['token'], $again['token'] );

		$partials = get_posts(
			array(
				'post_type'   => ATF_ENTRY_TYPE,
				'post_status' => ATF_STATUS_PARTIAL,
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);

		$this->assertCount( 1, $partials );
		$this->assertSame( 'Second', atf_resume_values( $first['token'] )['values']['f1'] );
	}

	/**
	 * An update writes back the stored token, not the raw request's copy of it.
	 *
	 * The lookup tolerates decoration around a token -- whitespace, a stray
	 * quote -- that must never be written into the resume meta, because a
	 * corrupted stored token fails every later lookup.
	 *
	 * @covers ::atf_save_partial
	 */
	public function test_an_update_keeps_the_stored_token_intact() {
		$form_id = $this->resumable_form();
		$saved   = atf_save_partial( $form_id, array( 'f1' => 'First' ) );

		$again = atf_save_partial( $form_id, array( 'f1' => 'Second' ), ' ' . $saved['token'] . '"' );

		$this->assertNotWPError( $again );
		$this->assertSame( $saved['token'], $again['token'] );
		$this->assertNotNull( atf_find_partial( $saved['token'] ), 'The stored token was corrupted by the update.' );
		$this->assertSame( 'Second', atf_resume_values( $saved['token'] )['values']['f1'] );
	}

	/**
	 * A token minted on one form cannot overwrite another form's partial.
	 *
	 * The mismatched token is treated as no token at all: the save succeeds as
	 * a fresh partial for its own form, and the other form's answers survive.
	 *
	 * @covers ::atf_save_partial
	 */
	public function test_a_foreign_token_does_not_update_another_forms_partial() {
		$first  = $this->resumable_form();
		$second = $this->resumable_form();

		$saved = atf_save_partial( $first, array( 'f1' => 'First form' ) );
		$cross = atf_save_partial( $second, array( 'f1' => 'Second form' ), $saved['token'] );

		$this->assertNotWPError( $cross );
		$this->assertNotSame( $saved['token'], $cross['token'], 'The save must mint a fresh token rather than reuse the other form\'s.' );
		$this->assertSame( 'First form', atf_resume_values( $saved['token'] )['values']['f1'], 'The first form\'s partial was overwritten.' );
		$this->assertSame( $second, atf_resume_values( $cross['token'] )['form_id'] );
	}

	/**
	 * Tokenless saves are capped per IP, because each one writes a row.
	 *
	 * The `/resume` route is public, so without a cap a script can grow the
	 * entries table without bound. Updates are exempt: they need a token, and
	 * the save that minted it already spent a slot.
	 *
	 * @covers ::atf_save_partial
	 */
	public function test_partial_creation_is_rate_limited() {
		$previous_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : null;

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		wp_set_current_user( 0 );

		add_filter(
			'atf_partial_rate_limit',
			static function () {
				return 2;
			}
		);

		$form_id = $this->resumable_form();

		$this->assertNotWPError( atf_save_partial( $form_id, array( 'f1' => 'one' ) ) );

		$second = atf_save_partial( $form_id, array( 'f1' => 'two' ) );

		$this->assertNotWPError( $second );

		$third = atf_save_partial( $form_id, array( 'f1' => 'three' ) );

		$this->assertWPError( $third );
		$this->assertSame( 'atf_partial_rate_limited', $third->get_error_code() );

		// A visitor at the cap can still keep saving the partial they hold.
		$this->assertNotWPError( atf_save_partial( $form_id, array( 'f1' => 'still saving' ), $second['token'] ) );

		if ( null === $previous_ip ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $previous_ip;
		}
	}

	/**
	 * A token nobody issued resolves to nothing.
	 *
	 * @dataProvider data_bad_tokens
	 * @covers ::atf_find_partial
	 *
	 * @param string $token A token that must not resolve.
	 */
	public function test_bad_tokens_resolve_to_nothing( $token ) {
		$this->resumable_form();

		$this->assertNull( atf_find_partial( $token ) );
		$this->assertSame( array(), atf_resume_values( $token ) );
	}

	/**
	 * Tokens that must not work.
	 *
	 * @return array[]
	 */
	public function data_bad_tokens() {
		return array(
			'empty'      => array( '' ),
			'too short'  => array( 'abc' ),
			'made up'    => array( 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' ),
			'sql-ish'    => array( "' OR 1=1 --" ),
			'wildcard'   => array( '%' ),
			'percent'    => array( str_repeat( '%', 32 ) ),
			'underscore' => array( str_repeat( '_', 32 ) ),
		);
	}

	/**
	 * A `LIKE` wildcard cannot be used to fish for somebody else's partial.
	 *
	 * The lookup narrows with a `LIKE`, so the final comparison has to be exact
	 * or a token of all-percent signs would match the first partial in the table.
	 *
	 * @covers ::atf_find_partial
	 */
	public function test_wildcards_cannot_match_a_real_token() {
		$form_id = $this->resumable_form();

		atf_save_partial( $form_id, array( 'f1' => 'Private' ) );

		$this->assertNull( atf_find_partial( str_repeat( '%', 32 ) ) );
		$this->assertNull( atf_find_partial( str_repeat( '_', 32 ) ) );
	}

	/**
	 * Two tokens are never the same.
	 *
	 * @covers ::atf_save_partial
	 */
	public function test_tokens_are_unique_and_long() {
		$form_id = $this->resumable_form();
		$tokens  = array();

		for ( $i = 0; $i < 20; $i++ ) {
			$saved    = atf_save_partial( $form_id, array( 'f1' => 'x' ) );
			$tokens[] = $saved['token'];

			$this->assertSame( 32, strlen( $saved['token'] ) );
		}

		$this->assertSame( $tokens, array_unique( $tokens ) );
	}

	/**
	 * An expired token stops working.
	 *
	 * @covers ::atf_find_partial
	 */
	public function test_an_expired_token_is_refused() {
		$form_id = $this->resumable_form();
		$saved   = atf_save_partial( $form_id, array( 'f1' => 'Ada' ) );
		$partial = atf_find_partial( $saved['token'] );

		update_post_meta(
			$partial->ID,
			ATF_META_RESUME,
			wp_slash(
				wp_json_encode(
					array(
						'token'   => $saved['token'],
						'expires' => time() - 10,
					)
				)
			)
		);

		$this->assertNull( atf_find_partial( $saved['token'] ) );
	}

	/**
	 * The expiry sweep removes expired partials and keeps live ones.
	 *
	 * @covers ::atf_expire_partials
	 */
	public function test_expiry_sweep() {
		$form_id = $this->resumable_form();

		$live     = atf_save_partial( $form_id, array( 'f1' => 'Live' ) );
		$stale    = atf_save_partial( $form_id, array( 'f1' => 'Stale' ) );
		$stale_id = atf_find_partial( $stale['token'] )->ID;

		update_post_meta(
			$stale_id,
			ATF_META_RESUME,
			wp_slash(
				wp_json_encode(
					array(
						'token'   => $stale['token'],
						'expires' => time() - 10,
					)
				)
			)
		);

		atf_expire_partials();

		$this->assertNull( get_post( $stale_id ) );
		$this->assertNotNull( atf_find_partial( $live['token'] ) );
	}

	/**
	 * A partial does not count towards a form's submission limit.
	 *
	 * Somebody saving a draft three times must not use up the quota.
	 *
	 * @covers ::atf_count_entries
	 */
	public function test_partials_do_not_count_as_submissions() {
		$form_id = $this->resumable_form();

		atf_save_partial( $form_id, array( 'f1' => 'a' ) );
		atf_save_partial( $form_id, array( 'f1' => 'b' ) );

		$this->assertSame( 0, atf_count_entries( $form_id ) );
	}

	/**
	 * A partial is not in the entries list.
	 *
	 * @covers ::atf_query_entries
	 */
	public function test_partials_are_not_in_the_entries_list() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		atf_add_capabilities();

		$form_id = $this->resumable_form();

		atf_save_partial( $form_id, array( 'f1' => 'Half done' ) );

		$this->assertSame( 0, atf_query_entries( array( 'form_id' => $form_id ) )['total'] );
	}

	/**
	 * Completing a form deletes the partial it came from.
	 *
	 * @covers ::atf_clear_partial_on_submit
	 */
	public function test_finishing_clears_the_partial() {
		$form_id = $this->resumable_form();
		$saved   = atf_save_partial( $form_id, array( 'f1' => 'Ada' ) );

		$this->assertNotNull( atf_find_partial( $saved['token'] ) );

		// The token travels in the finishing submission, which is the only thing
		// tying the two together.
		$_POST[ ATF_RESUME_QUERY ] = $saved['token'];

		$issued = time() - 30;

		atf_process_submission(
			$form_id,
			array(
				'atf_form_id' => $form_id,
				'atf_nonce'   => wp_create_nonce( 'atf_submit_' . $form_id ),
				'atf_t'       => $issued,
				'atf_ts'      => atf_sign_timestamp( $form_id, $issued ),
				'atf'         => array( 'f1' => 'Ada Lovelace' ),
			)
		);

		unset( $_POST[ ATF_RESUME_QUERY ] );

		$this->assertNull( atf_find_partial( $saved['token'] ), 'The partial outlived the submission that finished it.' );
	}

	/**
	 * A REST submission with a JSON body still clears the partial.
	 *
	 * A JSON request never populates `$_POST`, so the token has to be read
	 * from the parsed request inside the pipeline rather than only from the
	 * superglobal.
	 *
	 * @covers ::atf_clear_partial
	 */
	public function test_a_json_submission_clears_the_partial() {
		$form_id = $this->resumable_form();
		$saved   = atf_save_partial( $form_id, array( 'f1' => 'Ada' ) );

		$this->assertNotNull( atf_find_partial( $saved['token'] ) );

		$issued = time() - 30;

		// The token rides in the request array only -- `$_POST` stays empty,
		// exactly as it is for a `fetch()` posting JSON to the REST route.
		atf_process_submission(
			$form_id,
			array(
				'atf_form_id'    => $form_id,
				'atf_nonce'      => wp_create_nonce( 'atf_submit_' . $form_id ),
				'atf_t'          => $issued,
				'atf_ts'         => atf_sign_timestamp( $form_id, $issued ),
				ATF_RESUME_QUERY => $saved['token'],
				'atf'            => array( 'f1' => 'Ada Lovelace' ),
			)
		);

		$this->assertNull( atf_find_partial( $saved['token'] ), 'A JSON submission left the partial behind.' );
	}

	/**
	 * A resumed form renders with the saved answers in it.
	 *
	 * @covers ::atf_render_form
	 */
	public function test_a_resumed_form_renders_its_values() {
		$form_id = $this->resumable_form();
		$saved   = atf_save_partial( $form_id, array( 'f1' => 'Ada Lovelace' ) );

		$_GET[ ATF_RESUME_QUERY ] = $saved['token'];

		$html = atf_render_form( $form_id );

		unset( $_GET[ ATF_RESUME_QUERY ] );

		$this->assertStringContainsString( 'Ada Lovelace', $html );
		$this->assertStringContainsString( 'data-atf-resume', $html );
	}

	/**
	 * A token for one form does not resume a different one.
	 *
	 * @covers ::atf_render_form
	 */
	public function test_a_token_only_resumes_its_own_form() {
		$first  = $this->resumable_form();
		$second = $this->resumable_form();

		$saved = atf_save_partial( $first, array( 'f1' => 'Belongs to the first form' ) );

		$_GET[ ATF_RESUME_QUERY ] = $saved['token'];

		$html = atf_render_form( $second );

		unset( $_GET[ ATF_RESUME_QUERY ] );

		$this->assertStringNotContainsString( 'Belongs to the first form', $html );
	}

	/**
	 * The button only appears on a form that offers saving.
	 *
	 * @covers ::atf_render_resume_button
	 */
	public function test_the_button_is_conditional() {
		$off = atf_test_form(
			array(
				'fields' => array(
					array(
						'id'   => 'f1',
						'type' => 'text',
					),
				),
			)
		);

		$this->assertStringNotContainsString( 'data-atf-resume', atf_render_form( $off ) );
		$this->assertStringContainsString( 'data-atf-resume', atf_render_form( $this->resumable_form() ) );
	}
}

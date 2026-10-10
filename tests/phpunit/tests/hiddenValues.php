<?php
/**
 * Values posted for fields that logic hides are not kept.
 *
 * @package AllTerrainForms
 */

/**
 * A field hidden by logic holds no answer once screening is done.
 *
 * Validation has always skipped a hidden field. These pin that the stored entry
 * and the rules that read it (confirmations, notifications, actions) skip it
 * too, and that the spam screening still sees what was posted.
 */
class ALLTFO_Test_Hidden_Values extends WP_UnitTestCase {

	/**
	 * A form: what the visitor wants, and an outlet asked only for press.
	 *
	 * @var int
	 */
	private $form_id;

	/**
	 * Sets up the form for each test.
	 */
	public function set_up() {
		parent::set_up();

		$this->form_id = alltfo_test_form(
			array(
				'fields'        => array(
					array(
						'id'      => 'intent',
						'type'    => 'select',
						'label'   => 'What is this about?',
						'choices' => array(
							array(
								'label' => 'Press',
								'value' => 'press',
							),
							array(
								'label' => 'Something else',
								'value' => 'other',
							),
						),
					),
					array(
						'id'    => 'outlet',
						'type'  => 'text',
						'label' => 'Outlet',
						'logic' => array(
							'enabled' => true,
							'action'  => 'show',
							'match'   => 'all',
							'rules'   => array(
								array(
									'field'    => 'intent',
									'operator' => 'is',
									'value'    => 'press',
								),
							),
						),
					),
					array(
						'id'      => 'source',
						'type'    => 'hidden',
						'label'   => 'Source',
						'default' => 'footer',
					),
				),
				'confirmations' => array(
					array(
						'id'      => 'c-press',
						'type'    => 'message',
						'message' => 'Thanks, press desk.',
						'logic'   => array(
							'enabled' => true,
							'action'  => 'show',
							'match'   => 'all',
							'rules'   => array(
								array(
									'field'    => 'outlet',
									'operator' => 'not_empty',
									'value'    => '',
								),
							),
						),
					),
					array(
						'id'      => 'c-default',
						'type'    => 'message',
						'message' => 'Thanks, we will be in touch.',
					),
				),
			)
		);
	}

	/**
	 * A signed request that passes the time trap.
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
			'atf'            => $values,
		);
	}

	/**
	 * The stored values of an entry.
	 *
	 * @param int $entry_id The entry.
	 * @return array Field id => value.
	 */
	private function stored( $entry_id ) {
		return json_decode( get_post_meta( $entry_id, ALLTFO_META_VALUES, true ), true );
	}

	/**
	 * A value for a hidden field is not stored, and its rule does not fire.
	 *
	 * The same request a browser without the form's script sends, or a client
	 * that never rendered the form: the outlet is posted while intent says it is
	 * not asked.
	 *
	 * @covers ::alltfo_drop_hidden_values
	 * @covers ::alltfo_process_submission
	 */
	public function test_a_hidden_fields_value_is_not_kept() {
		$result = alltfo_process_submission(
			$this->form_id,
			$this->request(
				array(
					'intent' => 'other',
					'outlet' => 'Daily Planet',
				)
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( '', $this->stored( $result['entry_id'] )['outlet'] );
		$this->assertStringContainsString( 'we will be in touch', $result['confirmation']['message'], 'A rule on a hidden field must not fire on a value nobody was asked for.' );
	}

	/**
	 * The same field, shown by its condition, keeps its answer.
	 *
	 * @covers ::alltfo_drop_hidden_values
	 */
	public function test_a_shown_fields_value_is_kept() {
		$result = alltfo_process_submission(
			$this->form_id,
			$this->request(
				array(
					'intent' => 'press',
					'outlet' => 'Daily Planet',
				)
			)
		);

		$this->assertSame( 'Daily Planet', $this->stored( $result['entry_id'] )['outlet'] );
		$this->assertStringContainsString( 'press desk', $result['confirmation']['message'] );
	}

	/**
	 * A `hidden` type field is not hidden by logic, so its value is kept.
	 *
	 * @covers ::alltfo_drop_hidden_values
	 */
	public function test_a_hidden_type_field_is_kept() {
		$result = alltfo_process_submission(
			$this->form_id,
			$this->request(
				array(
					'intent' => 'other',
					'source' => 'footer',
				)
			)
		);

		$this->assertSame( 'footer', $this->stored( $result['entry_id'] )['source'] );
	}

	/**
	 * Spam screening still sees what was posted, hidden fields included.
	 *
	 * A plugin judging spam through `alltfo_spam_verdict` can only reason about
	 * an answer to a hidden field if it still receives one.
	 *
	 * @covers ::alltfo_screen_for_spam
	 */
	public function test_spam_screening_sees_the_posted_value() {
		$seen = null;

		$capture = function ( $verdict, $schema, $values ) use ( &$seen ) {
			$seen = $values['outlet'];
			return $verdict;
		};
		add_filter( 'alltfo_spam_verdict', $capture, 10, 3 );

		alltfo_process_submission(
			$this->form_id,
			$this->request(
				array(
					'intent' => 'other',
					'outlet' => 'Daily Planet',
				)
			)
		);

		remove_filter( 'alltfo_spam_verdict', $capture, 10 );

		$this->assertSame( 'Daily Planet', $seen );
	}

	/**
	 * An agent submitting through the ability gets the same result.
	 *
	 * @covers ::alltfo_ability_submit_form
	 */
	public function test_submit_form_ability_drops_hidden_values_too() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		alltfo_add_capabilities();

		$result = wp_get_ability( 'allterrain-forms/submit-form' )->execute(
			array(
				'form_id' => $this->form_id,
				'values'  => array(
					'intent' => 'other',
					'outlet' => 'Daily Planet',
				),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( '', $this->stored( $result['entry_id'] )['outlet'] );
	}
}

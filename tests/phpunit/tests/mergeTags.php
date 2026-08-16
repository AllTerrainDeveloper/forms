<?php
/**
 * The merge-tag catalogue.
 *
 * The catalogue is what the builder's picker shows. It is a *description* of
 * `atf_resolve_merge_tag()`, and a description that has drifted from the thing
 * it describes is worse than none at all: somebody picks a value from a list,
 * their email arrives with `{entry:link}` printed in it verbatim, and nothing
 * anywhere told them the list was wrong.
 *
 * So the tests here are mostly one idea from several angles — everything the
 * picker offers must actually resolve, and everything it says about a form must
 * come from that form.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * The merge-tag catalogue.
 *
 * @group allterrain-forms
 */
class ATF_Test_Merge_Tags extends WP_UnitTestCase {

	/**
	 * A form with one question of each kind the catalogue treats specially.
	 *
	 * @return int The form id.
	 */
	private function catalogued_form() {
		return atf_test_form(
			array(
				'fields' => array(
					array(
						'id'    => 'f1',
						'type'  => 'text',
						'label' => 'Your name',
					),
					array(
						'id'    => 'f2',
						'type'  => 'email',
						'label' => 'Your email',
					),
					array(
						'id'      => 'f3',
						'type'    => 'radio',
						'label'   => 'Can you make it?',
						'choices' => array(
							array(
								'label' => 'Yes, I will be there',
								'value' => 'yes',
							),
							array(
								'label' => 'Sorry, I cannot',
								'value' => 'no',
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Every tag the catalogue lists, flattened.
	 *
	 * @param int $form_id The form to build the catalogue for.
	 * @return string[] Every advertised tag.
	 */
	private function catalogued_tags( $form_id ) {
		$tags = array();

		foreach ( atf_merge_tag_catalogue( $form_id ) as $group ) {
			foreach ( $group['items'] as $item ) {
				$tags[] = $item['tag'];
			}
		}

		return $tags;
	}

	/**
	 * Nothing is advertised that the resolver does not know.
	 *
	 * The bug this pins: the catalogue offered `{entry:link}`, which reads
	 * perfectly well and does not exist — the resolver calls it `{entry:url}`. An
	 * unknown tag returns itself, deliberately, so that a webhook body full of
	 * JSON braces survives; the cost of that choice is that a typo in the
	 * catalogue ships as literal text in somebody's email instead of failing
	 * loudly. This test is the loud failure.
	 *
	 * @covers ::atf_merge_tag_catalogue
	 */
	public function test_every_offered_tag_resolves() {
		$form_id = $this->catalogued_form();
		$context = array(
			'schema'  => atf_get_form_schema( $form_id ),
			'form_id' => $form_id,
			'values'  => array( 'f1' => 'Ada' ),
		);

		$unresolved = array();

		foreach ( $this->catalogued_tags( $form_id ) as $tag ) {
			// An empty result is fine and often correct — `{user:email}` is empty
			// for a visitor who was not logged in. Getting the tag *back* is not:
			// that is the resolver saying it has never heard of it.
			if ( atf_replace_merge_tags( $tag, $context ) === $tag ) {
				$unresolved[] = $tag;
			}
		}

		$this->assertSame(
			array(),
			$unresolved,
			'These tags are offered in the builder but resolve to themselves, so they would '
				. 'print as literal braces in an email: ' . implode( ', ', $unresolved )
		);
	}

	/**
	 * The questions are listed by the label the person wrote.
	 *
	 * The whole point of the picker: `{field:f2}` is not something anybody can be
	 * expected to recognise, and "Your email" is.
	 *
	 * @covers ::atf_merge_tag_answer_group
	 */
	public function test_questions_are_offered_by_their_labels() {
		$form_id = $this->catalogued_form();
		$groups  = atf_merge_tag_catalogue( $form_id );

		$answers = null;

		foreach ( $groups as $group ) {
			if ( 'answers' === $group['id'] ) {
				$answers = $group;
			}
		}

		$this->assertNotNull( $answers, 'The catalogue must have a group for the form’s own questions.' );

		$labels = wp_list_pluck( $answers['items'], 'label' );
		$tags   = wp_list_pluck( $answers['items'], 'tag' );

		$this->assertSame( array( 'Your name', 'Your email', 'Can you make it?' ), $labels );
		$this->assertSame( array( '{field:f1}', '{field:f2}', '{field:f3}' ), $tags );
	}

	/**
	 * The sample for a choice question is one of its own choices.
	 *
	 * A generic "Their answer" teaches nothing about what will actually arrive;
	 * seeing "Yes, I will be there" tells the person composing the email both the
	 * shape and the wording of what they are going to get.
	 *
	 * @covers ::atf_merge_tag_placeholder_for
	 */
	public function test_a_choice_question_samples_its_own_choices() {
		$form_id = $this->catalogued_form();
		$groups  = atf_merge_tag_catalogue( $form_id );

		$sample = '';

		foreach ( $groups as $group ) {
			foreach ( $group['items'] as $item ) {
				if ( '{field:f3}' === $item['tag'] ) {
					$sample = $item['sample'];
				}
			}
		}

		$this->assertSame( 'Yes, I will be there', $sample );
	}

	/**
	 * Samples are keyed on the slugs the registry actually registers.
	 *
	 * The switch used to speak for 'phone', 'website', 'paragraph' and
	 * 'toggle' -- names no field type has ever registered under -- so a phone
	 * field's sample fell through to the generic answer and taught nothing.
	 *
	 * @covers ::atf_merge_tag_placeholder_for
	 */
	public function test_samples_use_registered_type_slugs() {
		$this->assertSame( '+34 600 123 456', atf_merge_tag_placeholder_for( array( 'type' => 'tel' ) ) );
		$this->assertSame( 'https://example.com', atf_merge_tag_placeholder_for( array( 'type' => 'url' ) ) );
		$this->assertSame( 'Yes', atf_merge_tag_placeholder_for( array( 'type' => 'switch' ) ) );
		$this->assertStringContainsString( 'longer answer', atf_merge_tag_placeholder_for( array( 'type' => 'textarea' ) ) );
	}

	/**
	 * Site values in the catalogue are this site's, not invented ones.
	 *
	 * A sample that showed `admin@example.com` when the site's address is
	 * something else would be a plausible-looking lie, and the person would only
	 * find out after sending.
	 *
	 * @covers ::atf_merge_tag_sample
	 */
	public function test_site_samples_come_from_this_site() {
		update_option( 'admin_email', 'someone@allterrain.test' );

		$found = '';

		foreach ( atf_merge_tag_catalogue( 0 ) as $group ) {
			foreach ( $group['items'] as $item ) {
				if ( '{admin_email}' === $item['tag'] ) {
					$found = $item['sample'];
				}
			}
		}

		$this->assertSame( 'someone@allterrain.test', $found );
	}

	/**
	 * A form with no questions says so, rather than showing an empty box.
	 *
	 * @covers ::atf_merge_tag_answer_group
	 */
	public function test_a_form_with_no_questions_explains_itself() {
		$form_id = atf_test_form( array( 'fields' => array() ) );

		$answers = null;

		foreach ( atf_merge_tag_catalogue( $form_id ) as $group ) {
			if ( 'answers' === $group['id'] ) {
				$answers = $group;
			}
		}

		$this->assertSame( array(), $answers['items'] );
		$this->assertNotEmpty( $answers['empty'], 'An empty answers group must say why it is empty.' );
	}

	/**
	 * Every catalogue entry carries the three things the picker renders.
	 *
	 * A missing `label` renders as a blank row and a missing `sample` as a row
	 * with nothing to learn from — both are silent, so a filter that adds a tag
	 * carelessly would degrade the picker without anybody noticing.
	 *
	 * @covers ::atf_merge_tag_catalogue
	 */
	public function test_every_entry_is_complete() {
		$incomplete = array();

		foreach ( atf_merge_tag_catalogue( $this->catalogued_form() ) as $group ) {
			foreach ( $group['items'] as $item ) {
				if ( ! isset( $item['tag'], $item['label'], $item['hint'], $item['sample'] ) || '' === $item['label'] ) {
					$incomplete[] = isset( $item['tag'] ) ? $item['tag'] : '(no tag)';
				}
			}
		}

		$this->assertSame( array(), $incomplete, 'Incomplete catalogue entries: ' . implode( ', ', $incomplete ) );
	}

	/**
	 * A plugin can add its own tag to the picker.
	 *
	 * The pairing that matters: `atf_resolve_merge_tag` makes a tag work and
	 * `atf_merge_tag_catalogue` makes it findable. A plugin that uses only the
	 * first has built something nobody will ever discover.
	 *
	 * @covers ::atf_merge_tag_catalogue
	 */
	public function test_a_plugin_can_advertise_its_own_tag() {
		add_filter(
			'atf_merge_tag_catalogue',
			static function ( $groups ) {
				$groups[] = array(
					'id'    => 'crm',
					'label' => 'Our CRM',
					'items' => array(
						array(
							'tag'    => '{crm_ref}',
							'label'  => 'The CRM reference',
							'hint'   => '',
							'sample' => 'CRM-1234',
						),
					),
				);

				return $groups;
			}
		);

		$this->assertContains( '{crm_ref}', $this->catalogued_tags( $this->catalogued_form() ) );
	}
}

<?php
/**
 * Answer recall: `{field:…}` in text shown inside a form.
 *
 * The builder offers earlier answers in labels, hints, headings and HTML
 * blocks. The server's half is printing a slot for each one and never letting
 * the slot break the markup around it; the form bundle fills it live.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * Answer recall.
 *
 * @group allterrain-forms
 */
class ALLTFO_Test_Recall extends WP_UnitTestCase {

	/**
	 * A form that recalls the visitor's name in later text, normalised.
	 *
	 * @return array The schema.
	 */
	private function schema() {
		return alltfo_get_form_schema( alltfo_test_form( $this->raw_schema() ) );
	}

	/**
	 * The same form as written.
	 *
	 * @return array The schema.
	 */
	private function raw_schema() {
		return array(
			'fields' => array(
				array(
					'id'    => 'name',
					'type'  => 'text',
					'label' => 'Your name',
				),
				array(
					'id'       => 'size',
					'type'     => 'radio',
					'label'    => 'Which size, {field:name}?',
					'hint'     => 'Pick one, {field:name}.',
					'required' => true,
					'choices'  => array(
						array(
							'label' => 'Small',
							'value' => 's',
						),
						array(
							'label' => 'Large',
							'value' => 'l',
						),
					),
				),
				array(
					'id'    => 'secret',
					'type'  => 'password',
					'label' => 'Password',
				),
			),
		);
	}

	/**
	 * A tag in text becomes an empty slot named after the field.
	 *
	 * @covers ::alltfo_recall_markup
	 */
	public function test_markup_prints_a_slot_per_tag() {
		$this->assertSame(
			'Hi <span class="atf-recall" data-atf-recall="name"></span>!',
			alltfo_recall_markup( 'Hi {field:name}!' )
		);
		$this->assertSame( 'No tags {here}', alltfo_recall_markup( 'No tags {here}' ) );
	}

	/**
	 * A tag inside an attribute is left alone, so a link in an HTML block
	 * cannot have a `<span>` written into its `href`.
	 *
	 * @covers ::alltfo_recall_markup
	 */
	public function test_markup_never_touches_attributes() {
		$html = '<a href="https://example.com/?n={field:name}">Hello {field:name}</a>';

		$this->assertSame(
			'<a href="https://example.com/?n={field:name}">Hello <span class="atf-recall" data-atf-recall="name"></span></a>',
			alltfo_recall_markup( $html )
		);
	}

	/**
	 * Labels and hints carry the slots when the form is rendered.
	 *
	 * @covers ::alltfo_render_label
	 * @covers ::alltfo_render_field
	 */
	public function test_rendered_label_and_hint_recall() {
		$html = alltfo_render_form( alltfo_test_form( $this->raw_schema() ) );

		$this->assertSame( 2, substr_count( $html, 'data-atf-recall="name"' ) );
		$this->assertStringContainsString( 'Which size, <span class="atf-recall" data-atf-recall="name"></span>?', $html );
		$this->assertStringContainsString( 'Pick one, <span class="atf-recall" data-atf-recall="name"></span>.', $html );
	}

	/**
	 * Choice labels travel in the client schema, so a recalled choice reads as
	 * what the visitor picked rather than its stored value.
	 *
	 * @covers ::alltfo_client_field
	 */
	public function test_client_schema_carries_choice_labels() {
		$schema = $this->schema();
		$client = alltfo_client_field( $schema['fields'][1] );

		$this->assertSame( 'Large', $client['choices'][1]['label'] );
	}

	/**
	 * Server-side messages fill recalled answers in, using choice labels, and
	 * never echo a password.
	 *
	 * @covers ::alltfo_recall_text
	 */
	public function test_text_resolves_answers() {
		$schema = $this->schema();

		$this->assertSame( 'Hi Ada', alltfo_recall_text( 'Hi {field:name}', $schema, array( 'name' => 'Ada' ) ) );
		$this->assertSame( 'Hi ', alltfo_recall_text( 'Hi {field:name}', $schema, array() ) );
		$this->assertSame( 'Got ', alltfo_recall_text( 'Got {field:secret}', $schema, array( 'secret' => 'hunter2' ) ) );
		$this->assertSame( 'Hi {field:nope}', alltfo_recall_text( 'Hi {field:nope}', $schema, array() ) );
	}

	/**
	 * A required-field error names the field the way the visitor sees it.
	 *
	 * @covers ::alltfo_validate_field
	 */
	public function test_validation_message_reads_the_recalled_label() {
		$errors = alltfo_validate_submission( $this->schema(), array( 'name' => 'Ada' ) );

		$this->assertSame( 'Which size, Ada? is required.', $errors['size'] );
	}
}

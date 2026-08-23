<?php
/**
 * The WPForms importer.
 *
 * Fixtures are shaped exactly as WPForms stores a form — one JSON document in
 * `post_content` — because the source plugin will not be active when the
 * importer runs and its data is the only contract there is.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * The WPForms converter.
 *
 * @group allterrain-forms
 */
class ALLTFO_Test_Importers_WPForms extends WP_UnitTestCase {

	/**
	 * A WPForms document, as Lite's contact template stores one.
	 *
	 * @return array
	 */
	private function contact_document() {
		return array(
			'fields'   => array(
				0 => array(
					'id'       => '0',
					'type'     => 'name',
					'label'    => 'Name',
					'format'   => 'first-last',
					'required' => '1',
				),
				1 => array(
					'id'       => '1',
					'type'     => 'email',
					'label'    => 'Email',
					'required' => '1',
				),
				2 => array(
					'id'          => '2',
					'type'        => 'textarea',
					'label'       => 'Comment or Message',
					'description' => 'Tell us everything.',
				),
			),
			'settings' => array(
				'form_title'          => 'Simple Contact Form',
				'submit_text'         => 'Send it in',
				'notification_enable' => '1',
				'notifications'       => array(
					1 => array(
						'notification_name' => 'Default Notification',
						'email'             => '{admin_email}',
						'subject'           => 'New Entry: Simple Contact Form',
						'sender_name'       => '{site_name}',
						'sender_address'    => '{admin_email}',
						'replyto'           => '{field_id="1"}',
						'message'           => '{all_fields}',
					),
				),
				'confirmations'       => array(
					1 => array(
						'type'    => 'message',
						'message' => '<p>Thanks for contacting us!</p>',
					),
				),
			),
		);
	}

	/**
	 * The Lite contact template converts field for field.
	 *
	 * @covers ::alltfo_wpforms_convert
	 */
	public function test_contact_document_converts() {
		$schema = alltfo_normalize_schema( alltfo_wpforms_convert( $this->contact_document() ) );

		$this->assertSame( array( 'name', 'email', 'textarea' ), wp_list_pluck( $schema['fields'], 'type' ) );
		$this->assertSame( array( 'first', 'last' ), $schema['fields'][0]['parts'] );
		$this->assertTrue( $schema['fields'][0]['required'] );
		$this->assertSame( 'Tell us everything.', $schema['fields'][2]['hint'] );
		$this->assertSame( 'Send it in', $schema['settings']['submitLabel'] );

		$mail = $schema['notifications'][0];

		$this->assertSame( '{admin_email}', $mail['to'] );
		$this->assertSame( '{site}', $mail['fromName'] );
		$this->assertSame( '{field:f2}', $mail['replyTo'] );
		$this->assertSame( '{all_fields}', $mail['message'] );

		$this->assertSame( 'Thanks for contacting us!', trim( wp_strip_all_tags( $schema['confirmations'][0]['message'] ) ) );
	}

	/**
	 * A simple-format name is one box, so it becomes a text field.
	 *
	 * @covers ::alltfo_wpforms_field
	 */
	public function test_simple_name_becomes_text() {
		$fields = alltfo_wpforms_convert(
			array(
				'fields' => array(
					array(
						'id'     => '0',
						'type'   => 'name',
						'label'  => 'Name',
						'format' => 'simple',
					),
					array(
						'id'     => '1',
						'type'   => 'name',
						'label'  => 'Full name',
						'format' => 'first-middle-last',
					),
				),
			)
		)['fields'];

		$this->assertSame( 'text', $fields[0]['type'] );
		$this->assertSame( 'name', $fields[1]['type'] );
		$this->assertSame( array( 'first', 'middle', 'last' ), $fields[1]['parts'] );
	}

	/**
	 * Payment choices keep their prices and the total keeps calculating.
	 *
	 * @covers ::alltfo_wpforms_field
	 * @covers ::alltfo_wpforms_choices
	 */
	public function test_payment_fields_keep_their_prices() {
		$schema = alltfo_wpforms_convert(
			array(
				'fields' => array(
					array(
						'id'      => '3',
						'type'    => 'payment-multiple',
						'label'   => 'Ticket',
						'choices' => array(
							1 => array(
								'label' => 'Standard',
								'value' => '25.00',
							),
							2 => array(
								'label' => 'VIP',
								'value' => '100.00',
							),
						),
					),
					array(
						'id'      => '4',
						'type'    => 'payment-checkbox',
						'label'   => 'Extras',
						'choices' => array(
							1 => array(
								'label' => 'Parking',
								'value' => '$10.00',
							),
						),
					),
					array(
						'id'    => '5',
						'type'  => 'payment-total',
						'label' => 'Total',
					),
				),
			)
		);

		$fields = $schema['fields'];

		$this->assertSame( 'radio', $fields[0]['type'] );
		$this->assertSame( 25.0, $fields[0]['choices'][0]['price'] );
		$this->assertSame( 100.0, $fields[0]['choices'][1]['price'] );

		$this->assertSame( 'checkboxes', $fields[1]['type'] );
		$this->assertSame( 10.0, $fields[1]['choices'][0]['price'] );

		$this->assertSame( 'total', $fields[2]['type'] );
		$this->assertSame( '{f1} + {f2}', $fields[2]['formula'] );

		// The formula computes through the real engine.
		$values = alltfo_apply_calculations(
			alltfo_normalize_schema( $schema ),
			array(
				'f1' => 'VIP',
				'f2' => array( 'Parking' ),
			)
		);

		$this->assertSame( 110.0, (float) $values['f3'] );
	}

	/**
	 * Conditional logic converts, both shapes that translate exactly.
	 *
	 * @covers ::alltfo_wpforms_logic
	 */
	public function test_conditional_logic_converts() {
		$fields = alltfo_wpforms_convert(
			array(
				'fields' => array(
					array(
						'id'    => '0',
						'type'  => 'radio',
						'label' => 'Attending?',
					),
					array(
						'id'                => '1',
						'type'              => 'text',
						'label'             => 'One group, two rules',
						'conditional_logic' => '1',
						'conditional_type'  => 'show',
						'conditionals'      => array(
							array(
								array(
									'field'    => '0',
									'operator' => '==',
									'value'    => 'Yes',
								),
								array(
									'field'    => '0',
									'operator' => '!e',
									'value'    => '',
								),
							),
						),
					),
					array(
						'id'                => '2',
						'type'              => 'text',
						'label'             => 'Two single-rule groups',
						'conditional_logic' => '1',
						'conditional_type'  => 'hide',
						'conditionals'      => array(
							array(
								array(
									'field'    => '0',
									'operator' => 'c',
									'value'    => 'A',
								),
							),
							array(
								array(
									'field'    => '0',
									'operator' => '^',
									'value'    => 'B',
								),
							),
						),
					),
				),
			)
		)['fields'];

		$all = $fields[1]['logic'];

		$this->assertTrue( $all['enabled'] );
		$this->assertSame( 'show', $all['action'] );
		$this->assertSame( 'all', $all['match'] );
		$this->assertSame( array( 'f1', 'f1' ), wp_list_pluck( $all['rules'], 'field' ) );
		$this->assertSame( array( 'is', 'not_empty' ), wp_list_pluck( $all['rules'], 'operator' ) );

		$any = $fields[2]['logic'];

		$this->assertSame( 'hide', $any['action'] );
		$this->assertSame( 'any', $any['match'] );
		$this->assertSame( array( 'contains', 'starts_with' ), wp_list_pluck( $any['rules'], 'operator' ) );
	}

	/**
	 * Pro and add-on fields have equivalents; the unknown stays visible.
	 *
	 * @covers ::alltfo_wpforms_field
	 */
	public function test_pro_field_mapping() {
		$fields = alltfo_wpforms_convert(
			array(
				'fields' => array(
					array(
						'id'    => '0',
						'type'  => 'phone',
						'label' => 'Phone',
					),
					array(
						'id'     => '1',
						'type'   => 'date-time',
						'label'  => 'When',
						'format' => 'date',
					),
					array(
						'id'         => '2',
						'type'       => 'file-upload',
						'label'      => 'CV',
						'extensions' => 'pdf, docx',
					),
					array(
						'id'            => '3',
						'type'          => 'net_promoter_score',
						'label'         => 'How likely…',
						'lowest_label'  => 'Not at all',
						'highest_label' => 'Extremely',
					),
					array(
						'id'    => '4',
						'type'  => 'pagebreak',
						'label' => 'Next',
					),
					array(
						'id'      => '5',
						'type'    => 'likert_scale',
						'label'   => 'Agree?',
						'rows'    => array( 'The docs are clear', 'The API is stable' ),
						'choices' => array(
							1 => array( 'label' => 'Agree' ),
							2 => array( 'label' => 'Disagree' ),
						),
					),
					array(
						'id'    => '6',
						'type'  => 'exotic-addon-widget',
						'label' => 'Mystery',
					),
					array(
						'id'      => '7',
						'type'    => 'gdpr-checkbox',
						'label'   => 'GDPR Agreement',
						'choices' => array(
							1 => array( 'label' => 'I consent to having this website store my submitted information.' ),
						),
					),
				),
			)
		)['fields'];

		$this->assertSame( 'tel', $fields[0]['type'] );
		$this->assertSame( 'date', $fields[1]['type'] );
		$this->assertSame( array( 'pdf', 'docx' ), $fields[2]['filetypes'] );

		$this->assertSame( 'scale', $fields[3]['type'] );
		$this->assertSame( 'Not at all', $fields[3]['endlabels']['low'] );

		$this->assertSame( 'page_break', $fields[4]['type'] );

		$this->assertSame( 'likert', $fields[5]['type'] );
		$this->assertSame( array( 'The docs are clear', 'The API is stable' ), $fields[5]['rows'] );
		$this->assertCount( 2, $fields[5]['choices'] );

		$this->assertSame( 'text', $fields[6]['type'], 'The unknown add-on field stays visible.' );

		$this->assertSame( 'consent', $fields[7]['type'] );
		$this->assertTrue( $fields[7]['required'], 'GDPR consent must not become optional by import.' );
		$this->assertStringContainsString( 'store my submitted information', $fields[7]['consentText'] );
	}

	/**
	 * The whole trip: a wpforms post becomes a working form.
	 *
	 * @covers ::alltfo_wpforms_import
	 */
	public function test_wpforms_import_end_to_end() {
		alltfo_add_capabilities();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$source = self::factory()->post->create(
			array(
				'post_type'    => 'wpforms',
				'post_title'   => 'Simple Contact Form',
				'post_content' => wp_slash( wp_json_encode( $this->contact_document() ) ),
			)
		);

		$form_id = alltfo_import_source_form( 'wpforms', (string) $source );

		$this->assertIsInt( $form_id );
		$this->assertSame( 'Simple Contact Form', get_post( $form_id )->post_title );

		$schema = alltfo_get_form_schema( $form_id );

		$this->assertCount( 3, $schema['fields'] );
		$this->assertSame( '{admin_email}', $schema['notifications'][0]['to'] );

		// The source is untouched.
		$this->assertSame( 'wpforms', get_post( $source )->post_type );

		// A post whose JSON is broken fails cleanly.
		$broken = self::factory()->post->create(
			array(
				'post_type'    => 'wpforms',
				'post_content' => 'not json',
			)
		);

		$this->assertWPError( alltfo_import_source_form( 'wpforms', (string) $broken ) );
	}
}

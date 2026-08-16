<?php
/**
 * The Gravity Forms importer.
 *
 * The converter is pure, so most of this pins conversion rules against fixture
 * arrays shaped exactly as `display_meta` and the notifications and
 * confirmations columns store them. The end-to-end test creates the real
 * tables, because reading another plugin's tables is the part most worth not
 * breaking.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * The Gravity Forms converter.
 *
 * @group allterrain-forms
 */
class ATF_Test_Importers_GravityForms extends WP_UnitTestCase {

	/**
	 * A display_meta document, as Gravity stores a contact form.
	 *
	 * @return array
	 */
	private function contact_display_meta() {
		return array(
			'title'  => 'Contact us',
			'button' => array(
				'type' => 'text',
				'text' => 'Get in touch',
			),
			'fields' => array(
				array(
					'type'       => 'name',
					'id'         => 1,
					'label'      => 'Name',
					'isRequired' => true,
					'inputs'     => array(
						array(
							'id'    => '1.3',
							'label' => 'First',
						),
						array(
							'id'    => '1.6',
							'label' => 'Last',
						),
					),
				),
				array(
					'type'       => 'email',
					'id'         => 2,
					'label'      => 'Email',
					'isRequired' => true,
				),
				array(
					'type'              => 'radio',
					'id'                => 3,
					'label'             => 'Topic',
					'choices'           => array(
						array(
							'text'  => 'Support',
							'value' => 'Support',
						),
						array(
							'text'  => 'Sales',
							'value' => 'Sales',
						),
					),
					'enableOtherChoice' => true,
				),
				array(
					'type'             => 'textarea',
					'id'               => 4,
					'label'            => 'Message',
					'conditionalLogic' => array(
						'actionType' => 'show',
						'logicType'  => 'any',
						'rules'      => array(
							array(
								'fieldId'  => '3',
								'operator' => 'is',
								'value'    => 'Support',
							),
							array(
								'fieldId'  => '3',
								'operator' => 'is',
								'value'    => 'Sales',
							),
						),
					),
				),
				array(
					'type'          => 'consent',
					'id'            => 5,
					'label'         => 'Consent',
					'isRequired'    => true,
					'checkboxLabel' => 'I agree to the privacy policy.',
				),
			),
		);
	}

	/**
	 * The contact form converts field for field.
	 *
	 * @covers ::atf_gf_convert
	 * @covers ::atf_gf_field
	 */
	public function test_contact_display_meta_converts() {
		$schema = atf_normalize_schema( atf_gf_convert( $this->contact_display_meta() ) );

		$this->assertSame( array( 'name', 'email', 'radio', 'textarea', 'consent' ), wp_list_pluck( $schema['fields'], 'type' ) );
		$this->assertSame( array( 'first', 'last' ), $schema['fields'][0]['parts'] );
		$this->assertTrue( $schema['fields'][0]['required'] );
		$this->assertTrue( $schema['fields'][2]['other'], 'The "other" choice comes across.' );
		$this->assertSame( 'I agree to the privacy policy.', $schema['fields'][4]['consentText'] );
		$this->assertSame( 'Get in touch', $schema['settings']['submitLabel'] );

		$logic = $schema['fields'][3]['logic'];

		$this->assertTrue( $logic['enabled'] );
		$this->assertSame( 'any', $logic['match'] );
		$this->assertSame( array( 'f3', 'f3' ), wp_list_pluck( $logic['rules'], 'field' ) );
	}

	/**
	 * Name parts follow Gravity's input numbering, hidden parts excluded.
	 *
	 * @covers ::atf_gf_name_parts
	 */
	public function test_name_parts() {
		$parts = atf_gf_name_parts(
			array(
				'inputs' => array(
					array(
						'id'       => '1.2',
						'isHidden' => true,
					),
					array( 'id' => '1.3' ),
					array( 'id' => '1.4' ),
					array( 'id' => '1.6' ),
					array(
						'id'       => '1.8',
						'isHidden' => false,
					),
				),
			)
		);

		$this->assertSame( array( 'first', 'middle', 'last', 'suffix' ), $parts );
	}

	/**
	 * A List field becomes a repeater whose sub-fields are the columns.
	 *
	 * @covers ::atf_gf_field
	 */
	public function test_list_becomes_a_repeater() {
		$fields = atf_gf_convert(
			array(
				'fields' => array(
					array(
						'type'          => 'list',
						'id'            => 1,
						'label'         => 'Guests',
						'enableColumns' => true,
						'choices'       => array(
							array( 'text' => 'Guest name' ),
							array( 'text' => 'Dietary needs' ),
						),
					),
					array(
						'type'  => 'list',
						'id'    => 2,
						'label' => 'Websites',
					),
				),
			)
		)['fields'];

		$this->assertSame( 'repeater', $fields[0]['type'] );
		$this->assertSame( array( 'Guest name', 'Dietary needs' ), wp_list_pluck( $fields[0]['fields'], 'label' ) );

		// A single-column list keeps its own label as the one sub-field.
		$this->assertCount( 1, $fields[1]['fields'] );
		$this->assertSame( 'Websites', $fields[1]['fields'][0]['label'] );
	}

	/**
	 * Products keep their prices and the total keeps calculating.
	 *
	 * @covers ::atf_gf_field
	 * @covers ::atf_gf_choices
	 * @covers ::atf_gf_price
	 */
	public function test_products_and_total() {
		$schema = atf_gf_convert(
			array(
				'fields' => array(
					array(
						'type'      => 'product',
						'id'        => 1,
						'label'     => 'Ticket',
						'inputType' => 'radio',
						'choices'   => array(
							array(
								'text'  => 'Standard',
								'value' => 'Standard',
								'price' => '$25.00',
							),
							array(
								'text'  => 'VIP',
								'value' => 'VIP',
								'price' => '$100.00',
							),
						),
					),
					array(
						'type'    => 'option',
						'id'      => 2,
						'label'   => 'Extras',
						'choices' => array(
							array(
								'text'  => 'Parking',
								'value' => 'Parking',
								'price' => '10,00 €',
							),
						),
					),
					array(
						'type'  => 'total',
						'id'    => 3,
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

		$this->assertSame( '{f1} + {f2}', $fields[2]['formula'] );

		$values = atf_apply_calculations(
			atf_normalize_schema( $schema ),
			array(
				'f1' => 'VIP',
				'f2' => array( 'Parking' ),
			)
		);

		$this->assertSame( 110.0, (float) $values['f3'] );
	}

	/**
	 * Notifications and confirmations convert, merge tags included.
	 *
	 * @covers ::atf_gf_convert
	 * @covers ::atf_gf_replace_tags
	 */
	public function test_notifications_and_confirmations() {
		$schema = atf_normalize_schema(
			atf_gf_convert(
				$this->contact_display_meta(),
				array(
					array(
						'name'    => 'Admin Notification',
						'event'   => 'form_submission',
						'to'      => '{admin_email}',
						'subject' => 'New submission from {Name (First):1.3}',
						'message' => '{all_fields} — sent from {embed_url}',
						'replyTo' => '{Email:2}',
					),
					array(
						'name'  => 'Payment done',
						'event' => 'complete_payment',
						'to'    => 'x@y.z',
					),
					array(
						'name'   => 'To the visitor',
						'event'  => 'form_submission',
						'to'     => '2',
						'toType' => 'field',
					),
				),
				array(
					array(
						'name'    => 'Default Confirmation',
						'type'    => 'message',
						'message' => 'Thanks {Name (First):1.3}!',
					),
					array(
						'name'        => 'Send them on',
						'type'        => 'redirect',
						'url'         => 'https://example.com/thanks',
						'queryString' => 'topic={Topic:3}',
					),
				)
			)
		);

		$this->assertCount( 2, $schema['notifications'], 'The payment-event notification stays behind.' );

		$admin = $schema['notifications'][0];

		$this->assertSame( '{admin_email}', $admin['to'] );
		$this->assertSame( 'New submission from {field:f1}', $admin['subject'] );
		$this->assertStringContainsString( '{all_fields}', $admin['message'] );
		$this->assertStringContainsString( '{referrer}', $admin['message'] );
		$this->assertSame( '{field:f2}', $admin['replyTo'] );

		$this->assertSame( '{field:f2}', $schema['notifications'][1]['to'], 'A field-addressed mail keeps its address.' );

		$this->assertSame( 'Thanks {field:f1}!', $schema['confirmations'][0]['message'] );
		$this->assertSame( 'redirect', $schema['confirmations'][1]['type'] );
		$this->assertSame( 'topic={field:f3}', $schema['confirmations'][1]['query'] );
	}

	/**
	 * The whole trip, through the real tables.
	 *
	 * @covers ::atf_gf_import
	 * @covers ::atf_gf_forms
	 * @covers ::atf_gf_available
	 */
	public function test_gravityforms_import_end_to_end() {
		global $wpdb;

		atf_add_capabilities();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// Without the tables the importer reports itself unavailable.
		$this->assertFalse( atf_gf_available() );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Creating another plugin's tables is the fixture.
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}gf_form ( id mediumint(8) unsigned NOT NULL auto_increment, title varchar(150) NOT NULL, is_trash tinyint(1) NOT NULL default 0, PRIMARY KEY (id) )" );
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}gf_form_meta ( form_id mediumint(8) unsigned NOT NULL, display_meta longtext, notifications longtext, confirmations longtext, PRIMARY KEY (form_id) )" );

		$wpdb->insert(
			$wpdb->prefix . 'gf_form',
			array(
				'title'    => 'Contact us',
				'is_trash' => 0,
			)
		);
		$form_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$wpdb->prefix . 'gf_form_meta',
			array(
				'form_id'       => $form_id,
				'display_meta'  => wp_json_encode( $this->contact_display_meta() ),
				'notifications' => wp_json_encode(
					array(
						array(
							'name'    => 'Admin Notification',
							'event'   => 'form_submission',
							'to'      => '{admin_email}',
							'subject' => 'New submission',
							'message' => '{all_fields}',
						),
					)
				),
				'confirmations' => wp_json_encode(
					array(
						array(
							'name'    => 'Default Confirmation',
							'type'    => 'message',
							'message' => 'Thanks!',
						),
					)
				),
			)
		);

		$this->assertTrue( atf_gf_available() );
		$this->assertSame( array( (string) $form_id => 'Contact us' ), atf_gf_forms() );

		$new_id = atf_import_source_form( 'gravityforms', (string) $form_id );

		$this->assertIsInt( $new_id );
		$this->assertSame( 'Contact us', get_post( $new_id )->post_title );

		$schema = atf_get_form_schema( $new_id );

		$this->assertCount( 5, $schema['fields'] );
		$this->assertSame( '{admin_email}', $schema['notifications'][0]['to'] );
		$this->assertSame( 'Thanks!', $schema['confirmations'][0]['message'] );

		// The source tables are untouched.
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}gf_form WHERE id = %d", $form_id ) ) );

		$wpdb->query( "DROP TABLE {$wpdb->prefix}gf_form" );
		$wpdb->query( "DROP TABLE {$wpdb->prefix}gf_form_meta" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}
}

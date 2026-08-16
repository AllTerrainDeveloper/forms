<?php
/**
 * The importers — other plugins' forms becoming this plugin's forms.
 *
 * The conversion rules are pinned against fixture data shaped exactly as the
 * source plugin stores it, because the source plugin will not be active when
 * the importer runs and its data is the only contract there is.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * The importer registry and the Contact Form 7 converter.
 *
 * @group allterrain-forms
 */
class ATF_Test_Importers extends WP_UnitTestCase {

	/**
	 * A CF7 form as CF7 itself stores one: the default contact template.
	 *
	 * @var string
	 */
	const CF7_TEMPLATE = '<label> Your name
    [text* your-name autocomplete:name] </label>

<label> Your email
    [email* your-email autocomplete:email] </label>

<label> Subject
    [text* your-subject] </label>

<label> Your message (optional)
    [textarea your-message] </label>

[acceptance your-consent] I agree to the privacy policy. [/acceptance]

[submit "Submit"]';

	/**
	 * Registered importers pass validation; malformed ones are dropped.
	 *
	 * @covers ::atf_importers
	 */
	public function test_registry_drops_malformed_importers() {
		add_filter(
			'atf_importers',
			static function ( $importers ) {
				$importers['no-callbacks'] = array( 'label' => 'Broken' );

				return $importers;
			}
		);

		$importers = atf_importers();

		$this->assertArrayHasKey( 'contact-form-7', $importers );
		$this->assertArrayNotHasKey( 'no-callbacks', $importers );
	}

	/**
	 * The default CF7 contact template converts field for field.
	 *
	 * @covers ::atf_cf7_convert
	 * @covers ::atf_cf7_parse_template
	 */
	public function test_cf7_default_template_converts() {
		$schema = atf_normalize_schema(
			atf_cf7_convert( self::CF7_TEMPLATE, array(), array(), array() )
		);

		$types  = wp_list_pluck( $schema['fields'], 'type' );
		$labels = wp_list_pluck( $schema['fields'], 'label' );

		$this->assertSame( array( 'text', 'email', 'text', 'textarea', 'consent' ), $types );
		$this->assertSame( array( 'Your name', 'Your email', 'Subject', 'Your message (optional)', 'Your consent' ), $labels );

		$this->assertTrue( $schema['fields'][0]['required'], 'The starred tag is required.' );
		$this->assertFalse( $schema['fields'][3]['required'], 'The unstarred tag is optional.' );

		// Acceptance is required by default and carries its content as the
		// consent line.
		$this->assertTrue( $schema['fields'][4]['required'] );
		$this->assertSame( 'I agree to the privacy policy.', $schema['fields'][4]['consentText'] );

		$this->assertSame( 'Submit', $schema['settings']['submitLabel'] );
	}

	/**
	 * Choice tags carry their options across, including the pipe syntax.
	 *
	 * @covers ::atf_cf7_tag_to_field
	 * @covers ::atf_cf7_choices
	 */
	public function test_cf7_choice_tags() {
		$parsed = atf_cf7_parse_template(
			'[select* your-topic "Support" "Sales|sales-team"]
			[checkbox extras "Wrap" "Card"]
			[checkbox one exclusive "A" "B"]
			[radio colour "Red" "Green"]
			[select sizes multiple "S" "M" "L"]'
		);

		$fields = $parsed['fields'];

		$this->assertSame( 'select', $fields[0]['type'] );
		$this->assertSame( 'Sales', $fields[0]['choices'][1]['label'] );
		$this->assertSame( 'sales-team', $fields[0]['choices'][1]['value'] );

		$this->assertSame( 'checkboxes', $fields[1]['type'] );

		// `exclusive` means one answer only, which is a radio group.
		$this->assertSame( 'radio', $fields[2]['type'] );

		$this->assertSame( 'radio', $fields[3]['type'] );

		$this->assertSame( 'multiselect', $fields[4]['type'] );
		$this->assertCount( 3, $fields[4]['choices'] );
	}

	/**
	 * Placeholders, defaults and bounds survive the trip.
	 *
	 * @covers ::atf_cf7_tag_to_field
	 */
	public function test_cf7_options_map_to_settings() {
		$parsed = atf_cf7_parse_template(
			'[text your-town placeholder "Your town"]
			[text your-ref "ABC-1"]
			[number your-age min:18 max:99]
			[file your-cv filetypes:pdf|docx]'
		);

		$fields = $parsed['fields'];

		$this->assertSame( 'Your town', $fields[0]['placeholder'] );
		$this->assertArrayNotHasKey( 'default', $fields[0] );

		$this->assertSame( 'ABC-1', $fields[1]['default'] );

		$this->assertSame( '18', $fields[2]['min'] );
		$this->assertSame( '99', $fields[2]['max'] );

		$this->assertSame( array( 'pdf', 'docx' ), $fields[3]['filetypes'] );
	}

	/**
	 * Anti-spam tags are dropped; unknown add-on tags become visible text
	 * fields rather than vanishing.
	 *
	 * @covers ::atf_cf7_tag_to_field
	 */
	public function test_cf7_unmappable_tags() {
		$parsed = atf_cf7_parse_template( '[quiz your-quiz "1+1?|2"] [captchar your-captcha] [fancy_addon your-thing]' );

		$this->assertCount( 1, $parsed['fields'] );
		$this->assertSame( 'text', $parsed['fields'][0]['type'] );
		$this->assertSame( 'Your thing', $parsed['fields'][0]['label'] );
	}

	/**
	 * The mail block becomes a notification with merge tags for this plugin.
	 *
	 * @covers ::atf_cf7_convert_mail
	 * @covers ::atf_cf7_replace_mail_tags
	 */
	public function test_cf7_mail_becomes_a_notification() {
		$schema = atf_normalize_schema(
			atf_cf7_convert(
				self::CF7_TEMPLATE,
				array(
					'recipient'          => 'you@example.com',
					'sender'             => '[_site_title] <wordpress@example.com>',
					'subject'            => '[_site_title] "[your-subject]"',
					'body'               => "From: [your-name] <[your-email]>\n\n[your-message]\n\n-- \nSent from [_site_url]",
					'additional_headers' => 'Reply-To: [your-email]',
					'attachments'        => '',
				),
				array(),
				array( 'mail_sent_ok' => 'Thank you for your message.' )
			)
		);

		$this->assertCount( 1, $schema['notifications'] );

		$mail = $schema['notifications'][0];

		$this->assertSame( 'you@example.com', $mail['to'] );
		$this->assertSame( '{site} "{field:f3}"', $mail['subject'] );
		$this->assertSame( '{site}', $mail['fromName'] );
		$this->assertSame( 'wordpress@example.com', $mail['fromEmail'] );
		$this->assertSame( '{field:f2}', $mail['replyTo'] );
		// The angle brackets survive as entities — raw `<…>` would be read as
		// a bogus tag by the kses pass on save and the address deleted.
		$this->assertStringContainsString( 'From: {field:f1} &lt;{field:f2}&gt;', $mail['message'] );
		$this->assertStringContainsString( '{field:f4}', $mail['message'] );
		$this->assertStringContainsString( '{site:url}', $mail['message'] );

		$this->assertCount( 1, $schema['confirmations'] );
		$this->assertSame( 'Thank you for your message.', $schema['confirmations'][0]['message'] );
	}

	/**
	 * Mail (2) is only imported when CF7 had it switched on.
	 *
	 * @covers ::atf_cf7_convert
	 */
	public function test_cf7_inactive_mail_2_stays_behind() {
		$mail_2 = array(
			'active'    => false,
			'recipient' => '[your-email]',
			'body'      => 'Thanks!',
		);

		$schema = atf_cf7_convert( self::CF7_TEMPLATE, array( 'recipient' => 'a@b.c' ), $mail_2, array() );
		$this->assertCount( 1, $schema['notifications'] );

		$mail_2['active'] = true;

		$schema = atf_cf7_convert( self::CF7_TEMPLATE, array( 'recipient' => 'a@b.c' ), $mail_2, array() );
		$this->assertCount( 2, $schema['notifications'] );
		$this->assertSame( '{field:f2}', $schema['notifications'][1]['to'] );
	}

	/**
	 * The whole trip: a CF7 post in the database becomes a working form.
	 *
	 * @covers ::atf_cf7_import
	 * @covers ::atf_import_source_form
	 */
	public function test_cf7_import_end_to_end() {
		atf_add_capabilities();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$source = self::factory()->post->create(
			array(
				'post_type'  => 'wpcf7_contact_form',
				'post_title' => 'Contact page',
			)
		);

		update_post_meta( $source, '_form', self::CF7_TEMPLATE );
		update_post_meta(
			$source,
			'_mail',
			array(
				'recipient' => 'owner@example.com',
				'body'      => '[your-message]',
			)
		);
		update_post_meta( $source, '_messages', array( 'mail_sent_ok' => 'Received.' ) );

		$imported_action = 0;
		add_action(
			'atf_form_imported',
			static function () use ( &$imported_action ) {
				++$imported_action;
			}
		);

		$form_id = atf_import_source_form( 'contact-form-7', (string) $source );

		$this->assertIsInt( $form_id );
		$this->assertSame( 'Contact page', get_post( $form_id )->post_title );
		$this->assertSame( ATF_FORM_TYPE, get_post( $form_id )->post_type );
		$this->assertSame( 1, $imported_action );

		$schema = atf_get_form_schema( $form_id );

		$this->assertCount( 5, $schema['fields'] );
		$this->assertSame( 'owner@example.com', $schema['notifications'][0]['to'] );
		$this->assertSame( 'Received.', $schema['confirmations'][0]['message'] );

		// The source is untouched — importing must never be destructive.
		$this->assertSame( 'wpcf7_contact_form', get_post( $source )->post_type );
		$this->assertNotEmpty( get_post_meta( $source, '_form', true ) );
	}

	/**
	 * Importing needs the capability, and a bogus source fails cleanly.
	 *
	 * @covers ::atf_import_source_form
	 */
	public function test_import_is_gated_and_fails_cleanly() {
		wp_set_current_user( 0 );
		$this->assertWPError( atf_import_source_form( 'contact-form-7', '1' ) );

		atf_add_capabilities();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertWPError( atf_import_source_form( 'no-such-importer', '1' ) );
		$this->assertWPError( atf_import_source_form( 'contact-form-7', '999999' ) );
	}
}

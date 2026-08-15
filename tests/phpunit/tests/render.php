<?php
/**
 * The renderer.
 *
 * Two promises are tested here, and both are the sort that erode quietly.
 *
 * **The form works without JavaScript.** It has to be a real `<form>` with a
 * real method and action, and its controls have to have names the server reads.
 *
 * **It is accessible.** Real labels bound by `for`, hints and errors wired with
 * `aria-describedby`, grouped controls in a fieldset with a legend, an error
 * summary that can take focus.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * The renderer, and what it promises.
 *
 * @group allterrain-forms
 */
class ATF_Test_Render extends WP_UnitTestCase {

	/**
	 * Renders a one-field form of a given type.
	 *
	 * @param array $field Field definition, merged over sensible defaults.
	 * @return string The rendered HTML.
	 */
	private function render_field( $field ) {
		$form_id = atf_test_form(
			array(
				'fields' => array(
					array_merge(
						array(
							'id'    => 'f1',
							'label' => 'A question',
						),
						$field
					),
				),
			)
		);

		return atf_render_form( $form_id );
	}

	/**
	 * The form posts, with a method and an action.
	 *
	 * @covers ::atf_render_form
	 */
	public function test_form_is_a_real_form() {
		$html = $this->render_field( array( 'type' => 'text' ) );

		$this->assertStringContainsString( '<form', $html );
		$this->assertStringContainsString( 'method="post"', $html );
		$this->assertStringContainsString( 'action=', $html );
		$this->assertStringContainsString( 'type="submit"', $html );
	}

	/**
	 * Every input carries a name the server reads.
	 *
	 * @dataProvider data_input_types
	 * @covers ::atf_render_field_control
	 *
	 * @param string $type A field type that produces a value.
	 */
	public function test_inputs_are_named( $type ) {
		$html = $this->render_field( array( 'type' => $type ) );

		$this->assertMatchesRegularExpression(
			'/name="atf(_file_f1|\[f1\])/',
			$html,
			sprintf( 'A %s field must post under a name the server reads.', $type )
		);
	}

	/**
	 * Field types that produce a value.
	 *
	 * @return array[]
	 */
	public function data_input_types() {
		$cases = array();

		foreach ( atf_get_field_types() as $slug => $definition ) {
			if ( empty( $definition['input'] ) ) {
				continue;
			}

			$cases[ $slug ] = array( $slug );
		}

		return $cases;
	}

	/**
	 * Every field type renders without a warning and produces markup.
	 *
	 * @dataProvider data_all_types
	 * @covers ::atf_render_field_control
	 *
	 * @param string $type Any registered field type.
	 */
	public function test_every_type_renders( $type ) {
		$html = $this->render_field(
			array(
				'type'    => $type,
				'choices' => array(
					array(
						'label' => 'One',
						'value' => 'one',
					),
					array(
						'label' => 'Two',
						'value' => 'two',
					),
				),
				'rows'    => array(
					array(
						'key'   => 'r1',
						'label' => 'Statement',
					),
				),
				'fields'  => array(
					array(
						'id'    => 'sub',
						'type'  => 'text',
						'label' => 'Sub',
					),
				),
			)
		);

		// A page break is not drawn as a field — it is the seam *between* fields,
		// and the renderer turns it into two `.atf-page` blocks with navigation
		// between them. Asserting it produces a `.atf-field--page_break` asserts
		// the wrong thing about the one type whose whole job is structural.
		if ( 'page_break' === $type ) {
			$this->assertSame( 2, substr_count( $html, 'data-atf-page="' ), 'A page break must split the form in two.' );
			$this->assertStringContainsString( 'data-atf-next', $html, 'A multi-page form needs a Next button.' );

			return;
		}

		$this->assertStringContainsString( 'atf-field--' . $type, $html );
	}

	/**
	 * Every registered field type.
	 *
	 * @return array[]
	 */
	public function data_all_types() {
		$cases = array();

		foreach ( array_keys( atf_get_field_types() ) as $slug ) {
			$cases[ $slug ] = array( $slug );
		}

		return $cases;
	}

	/**
	 * A text field's label is bound to its control by id.
	 *
	 * @covers ::atf_render_label
	 */
	public function test_label_is_bound() {
		$html = $this->render_field( array( 'type' => 'text' ) );

		$this->assertMatchesRegularExpression( '/<label class="atf-label" for="([^"]+)"/', $html );

		preg_match( '/<label class="atf-label" for="([^"]+)"/', $html, $matches );

		$this->assertStringContainsString( 'id="' . $matches[1] . '"', $html, 'The label points at no control.' );
	}

	/**
	 * Grouped controls get a fieldset and a legend, not a label.
	 *
	 * A `<label>` may only point at one control, so a label above six radios is
	 * bound to none of them.
	 *
	 * @dataProvider data_grouped_types
	 * @covers ::atf_render_choice_group
	 *
	 * @param string $type A field type whose control is a group.
	 */
	public function test_grouped_controls_use_a_fieldset( $type ) {
		$html = $this->render_field(
			array(
				'type'    => $type,
				'choices' => array(
					array(
						'label' => 'One',
						'value' => 'one',
					),
				),
				'rows'    => array(
					array(
						'key'   => 'r1',
						'label' => 'Statement',
					),
				),
			)
		);

		$this->assertStringContainsString( '<fieldset', $html );
		$this->assertStringContainsString( '<legend', $html );
	}

	/**
	 * Field types rendered as a group.
	 *
	 * @return array[]
	 */
	public function data_grouped_types() {
		return array(
			'radio'        => array( 'radio' ),
			'checkboxes'   => array( 'checkboxes' ),
			'image_choice' => array( 'image_choice' ),
			'rating'       => array( 'rating' ),
			'scale'        => array( 'scale' ),
			'name'         => array( 'name' ),
			'address'      => array( 'address' ),
		);
	}

	/**
	 * A hint is wired to its control with `aria-describedby`.
	 *
	 * @covers ::atf_render_field
	 */
	public function test_hint_is_described_by() {
		$html = $this->render_field(
			array(
				'type' => 'text',
				'hint' => 'We only use this to reply.',
			)
		);

		preg_match( '/aria-describedby="([^"]+)"/', $html, $matches );

		$this->assertNotEmpty( $matches, 'A field with a hint must reference it.' );
		$this->assertStringContainsString( 'id="' . trim( $matches[1] ) . '"', $html );
	}

	/**
	 * A required field is marked for assistive technology, not just visually.
	 *
	 * @covers ::atf_control_attributes
	 */
	public function test_required_is_announced() {
		$html = $this->render_field(
			array(
				'type'     => 'text',
				'required' => true,
			)
		);

		$this->assertStringContainsString( 'aria-required="true"', $html );
		$this->assertStringContainsString( 'aria-hidden="true">*', $html, 'The asterisk must not be announced.' );
	}

	/**
	 * The error summary can take focus and announces itself.
	 *
	 * @covers ::atf_render_error_summary
	 */
	public function test_error_summary() {
		$form_id = atf_test_form(
			array(
				'fields' => array(
					array(
						'id'       => 'f1',
						'type'     => 'text',
						'label'    => 'Name',
						'required' => true,
					),
				),
			)
		);

		$html = atf_render_form( $form_id, array( 'errors' => array( 'f1' => 'This is required.' ) ) );

		$this->assertStringContainsString( 'role="alert"', $html );
		$this->assertStringContainsString( 'tabindex="-1"', $html );
		$this->assertStringContainsString( 'This is required.', $html );
		$this->assertStringContainsString( 'aria-invalid="true"', $html );
	}

	/**
	 * A field hidden by logic is hidden in the server's own output.
	 *
	 * Without this a conditional field flashes into view before the bundle boots.
	 *
	 * @covers ::atf_render_field
	 */
	public function test_conditional_field_starts_hidden() {
		$form_id = atf_test_form(
			array(
				'fields' => array(
					array(
						'id'   => 'f1',
						'type' => 'text',
					),
					array(
						'id'    => 'f2',
						'type'  => 'text',
						'logic' => array(
							'enabled' => true,
							'action'  => 'show',
							'match'   => 'all',
							'rules'   => array(
								array(
									'field'    => 'f1',
									'operator' => 'is',
									'value'    => 'yes',
								),
							),
						),
					),
				),
			)
		);

		$html = atf_render_form( $form_id );

		$this->assertMatchesRegularExpression( '/data-atf-field="f2"[^>]*hidden/', $html );
		$this->assertStringContainsString( 'data-atf-logic=', $html );
	}

	/**
	 * The honeypot is present, off-screen, and hidden from assistive technology.
	 *
	 * @covers ::atf_render_hidden_fields
	 */
	public function test_honeypot() {
		$html = $this->render_field( array( 'type' => 'text' ) );

		$this->assertStringContainsString( 'atf_website', $html );
		$this->assertStringContainsString( 'aria-hidden="true"', $html );
		$this->assertStringContainsString( 'tabindex="-1"', $html );
		$this->assertStringNotContainsString( 'name="atf_website" type="hidden"', $html );
	}

	/**
	 * The signed timestamp is emitted.
	 *
	 * @covers ::atf_render_hidden_fields
	 */
	public function test_timestamp_is_signed() {
		$html = $this->render_field( array( 'type' => 'text' ) );

		$this->assertMatchesRegularExpression( '/name="atf_t" value="\d+"/', $html );
		$this->assertMatchesRegularExpression( '/name="atf_ts" value="[a-f0-9]+"/', $html );
	}

	/**
	 * An upload field switches the form's encoding.
	 *
	 * Without it `$_FILES` arrives empty and nothing says why.
	 *
	 * @covers ::atf_has_upload_field
	 */
	public function test_upload_form_has_enctype() {
		$this->assertStringContainsString(
			'enctype="multipart/form-data"',
			$this->render_field( array( 'type' => 'file' ) )
		);

		$this->assertStringNotContainsString(
			'enctype',
			$this->render_field( array( 'type' => 'text' ) )
		);
	}

	/**
	 * Two forms on one page get different DOM ids.
	 *
	 * Duplicate ids would break every `for` and every `aria-describedby` on the
	 * second copy.
	 *
	 * @covers ::atf_next_instance_id
	 */
	public function test_two_instances_do_not_collide() {
		$form_id = atf_test_form(
			array(
				'fields' => array(
					array(
						'id'    => 'f1',
						'type'  => 'text',
						'label' => 'Name',
					),
				),
			)
		);

		preg_match( '/id="(atf-\d+-\d+)"/', atf_render_form( $form_id ), $first );
		preg_match( '/id="(atf-\d+-\d+)"/', atf_render_form( $form_id ), $second );

		$this->assertNotSame( $first[1], $second[1] );
	}

	/**
	 * The client schema is emitted, and holds nothing private.
	 *
	 * A form's schema carries notification recipients, webhook secrets, the spam
	 * blocklist and quiz answers. None of it may reach the page source of a
	 * public form.
	 *
	 * @covers ::atf_render_client_schema
	 */
	public function test_client_schema_leaks_nothing() {
		$form_id = atf_test_form(
			array(
				'fields'        => array(
					array(
						'id'      => 'q',
						'type'    => 'quiz',
						// The correct answer is deliberately a value no choice carries.
						// It used to be `the-secret-answer` on both the `correct`
						// setting and one of the choices — and a choice's value has to
						// be in the page or the radio cannot post it. So the test failed
						// on markup that was doing exactly the right thing, and would
						// have gone on failing however well a real leak was plugged.
						'correct' => 'the-secret-answer',
						'choices' => array(
							array(
								'label' => 'Right',
								'value' => 'right',
							),
							array(
								'label' => 'Wrong',
								'value' => 'nope',
							),
						),
					),
				),
				'notifications' => array(
					array(
						'id'      => 'n1',
						'to'      => 'private-inbox@example.com',
						'subject' => 'New one',
					),
				),
				'actions'       => array(
					array(
						'id'       => 'a1',
						'type'     => 'webhook',
						'settings' => array(
							'url'    => 'https://hooks.example/secret-path',
							'secret' => 'shhh-signing-secret',
						),
					),
				),
				'settings'      => array(
					'spam' => array( 'blocklist' => 'a-blocked-word' ),
				),
			)
		);

		$html = atf_render_form( $form_id );

		$this->assertStringContainsString( 'data-atf-schema', $html );

		foreach ( array( 'private-inbox@example.com', 'shhh-signing-secret', 'hooks.example', 'a-blocked-word', 'the-secret-answer' ) as $secret ) {
			$this->assertStringNotContainsString(
				$secret,
				$html,
				sprintf( '"%s" reached the page source of a public form.', $secret )
			);
		}
	}

	/**
	 * The client schema cannot break out of its script block.
	 *
	 * @covers ::atf_render_client_schema
	 */
	public function test_client_schema_is_escaped() {
		$form_id = atf_test_form(
			array(
				'fields' => array(
					array(
						'id'    => 'f1',
						'type'  => 'text',
						'label' => '</script><script>alert(1)</script>',
					),
				),
			)
		);

		$html = atf_render_form( $form_id );

		$this->assertStringNotContainsString( '</script><script>alert(1)', $html );
	}

	/**
	 * Every theme renders the same markup, differing only in tokens.
	 *
	 * That is the property that keeps the accessibility work done once rather
	 * than ten times.
	 *
	 * @covers ::atf_render_form
	 */
	public function test_themes_do_not_change_the_markup() {
		$form_id = atf_test_form(
			array(
				'fields' => array(
					array(
						'id'    => 'f1',
						'type'  => 'text',
						'label' => 'Name',
					),
				),
			)
		);

		$strip = static function ( $html ) {
			// The token block and the theme classes are exactly what is meant to
			// differ; everything else must not.
			$html = preg_replace( '#<style>.*?</style>#s', '', $html );
			$html = preg_replace( '/atf-theme-[a-z-]+/', '', $html );
			$html = preg_replace( '/atf-labels-[a-z]+/', '', $html );
			$html = preg_replace( '/atf-fields-[a-z]+/', '', $html );
			$html = preg_replace( '/atf-is-dark/', '', $html );
			$html = preg_replace( '/atf-\d+-\d+/', 'INSTANCE', $html );
			$html = preg_replace( '/name="atf_t" value="\d+"/', 'T', $html );
			$html = preg_replace( '/value="[a-f0-9]{32,}"/', 'HASH', $html );

			// Runs of spaces collapsed, because the stripping above leaves them
			// behind: a dark theme carries `atf-is-dark` and a light one does not,
			// so removing the class leaves one extra space in the attribute. That
			// is an artefact of this normalisation, not a difference in the
			// markup — and comparing it made every dark theme look like it was
			// rewriting the document.
			$html = preg_replace( '/\s+/', ' ', $html );

			return $html;
		};

		$clean = $strip( atf_render_form( $form_id, array( 'theme' => 'clean' ) ) );

		foreach ( array_keys( atf_builtin_themes() ) as $slug ) {
			$this->assertSame(
				$clean,
				$strip( atf_render_form( $form_id, array( 'theme' => $slug ) ) ),
				sprintf( 'Theme "%s" changes the markup rather than only the tokens.', $slug )
			);
		}
	}

	/**
	 * A missing form renders nothing for a visitor.
	 *
	 * A broken shortcode id must never print an error into a published page.
	 *
	 * @covers ::atf_render_form
	 */
	public function test_missing_form_is_silent_for_visitors() {
		wp_set_current_user( 0 );

		$this->assertSame( '', atf_render_form( 999999 ) );
	}

	/**
	 * …but says so to somebody who can fix it.
	 *
	 * @covers ::atf_render_form
	 */
	public function test_missing_form_is_loud_for_editors() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		atf_add_capabilities();

		$this->assertStringContainsString( 'does not exist', atf_render_form( 999999 ) );
	}
}

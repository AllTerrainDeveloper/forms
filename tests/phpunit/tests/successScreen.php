<?php
/**
 * The success screen: styles, normalisation, resolution and static markup.
 *
 * The screen is stored config on a confirmation, resolved per submission, and
 * rendered twice — by the bundle after an AJAX submit and by PHP for the
 * no-JavaScript fallback. What is pinned here is the storage contract (an
 * unknown style can never reach the client) and the PHP render (whose markup
 * the stylesheet and the bundle both depend on).
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * The success screen.
 *
 * @group allterrain-forms
 */
class ATF_Test_Success_Screen extends WP_UnitTestCase {

	/**
	 * The style registry carries the promised presets.
	 *
	 * @covers ::atf_success_styles
	 */
	public function test_the_styles_ship() {
		$styles = atf_success_styles();

		foreach ( array( 'plain', 'simple', 'minimal', 'card', 'check', 'confetti', 'fireworks', 'sparkles', 'typewriter' ) as $key ) {
			$this->assertArrayHasKey( $key, $styles );
			$this->assertNotSame( '', $styles[ $key ]['label'] );
		}
	}

	/**
	 * The registry is filterable, and a filtered-in style survives normalisation.
	 *
	 * @covers ::atf_success_styles
	 * @covers ::atf_normalize_success_screen
	 */
	public function test_a_plugin_can_register_a_style() {
		$add = static function ( $styles ) {
			$styles['disco'] = array(
				'label'       => 'Disco',
				'description' => 'Mirror ball.',
				'icon'        => '🪩',
			);

			return $styles;
		};

		add_filter( 'atf_success_styles', $add );

		$success = atf_normalize_success_screen( array( 'style' => 'disco' ) );

		$this->assertSame( 'disco', $success['style'] );

		remove_filter( 'atf_success_styles', $add );
	}

	/**
	 * Normalisation fills every knob and refuses what it does not know.
	 *
	 * @covers ::atf_normalize_success_screen
	 */
	public function test_normalisation_is_complete_and_suspicious() {
		$defaults = atf_normalize_success_screen( array() );

		$this->assertSame( 'simple', $defaults['style'] );
		$this->assertSame( 'medium', $defaults['intensity'] );
		$this->assertFalse( $defaults['showButton'] );

		$mangled = atf_normalize_success_screen(
			array(
				'style'     => 'raveparty',
				'intensity' => 'ludicrous',
				'accent'    => 'javascript:alert(1)',
				'icon'      => str_repeat( '🎉', 20 ),
				'title'     => '<script>alert(1)</script>Thanks',
			)
		);

		$this->assertSame( 'simple', $mangled['style'], 'An unknown style falls back rather than reaching the client.' );
		$this->assertSame( 'medium', $mangled['intensity'] );
		$this->assertSame( '', $mangled['accent'], 'Anything but a hex colour is dropped.' );
		$this->assertSame( 8, mb_strlen( $mangled['icon'] ), 'The icon is a glyph, not a marquee.' );
		$this->assertStringNotContainsString( '<script>', $mangled['title'] );
	}

	/**
	 * A confirmation stores its screen, and the resolver hands it out with
	 * merge tags replaced.
	 *
	 * @covers ::atf_resolve_confirmation
	 * @covers ::atf_resolve_success_screen
	 */
	public function test_the_resolved_confirmation_carries_the_screen() {
		$form_id = atf_test_form(
			array(
				'fields'        => array(
					array(
						'id'    => 'f1',
						'type'  => 'text',
						'label' => 'Name',
					),
				),
				'confirmations' => array(
					array(
						'id'      => 'c1',
						'type'    => 'message',
						'message' => 'Thanks.',
						'success' => array(
							'style' => 'confetti',
							'title' => 'Nice one, {field:f1}!',
						),
					),
				),
			)
		);

		$schema   = atf_get_form_schema( $form_id );
		$resolved = atf_resolve_confirmation( $schema, array( 'f1' => 'Ada' ), 0, $form_id );

		$this->assertSame( 'confetti', $resolved['success']['style'] );
		$this->assertSame( 'Nice one, Ada!', $resolved['success']['title'] );
	}

	/**
	 * A form with no confirmations still resolves a complete screen.
	 *
	 * @covers ::atf_resolve_confirmation
	 * @covers ::atf_default_confirmation
	 */
	public function test_the_default_confirmation_has_a_screen() {
		$form_id  = atf_test_form();
		$schema   = atf_get_form_schema( $form_id );
		$resolved = atf_resolve_confirmation( $schema, array(), 0, $form_id );

		$this->assertSame( 'simple', $resolved['success']['style'] );
	}

	/**
	 * The static render: same classes the bundle builds, style on the root.
	 *
	 * @covers ::atf_success_screen_html
	 */
	public function test_static_markup_matches_the_bundle_contract() {
		$html = atf_success_screen_html(
			'<p>Saved.</p>',
			array(
				'style' => 'card',
				'title' => 'Thank you',
				'icon'  => '🎉',
			)
		);

		$this->assertStringContainsString( 'atf-success atf-success--card', $html );
		$this->assertStringContainsString( 'role="status"', $html );
		$this->assertStringContainsString( '<h2 class="atf-success__title">Thank you</h2>', $html );
		$this->assertStringContainsString( '<p>Saved.</p>', $html );
		$this->assertStringContainsString( '🎉', $html );
	}

	/**
	 * Plain is exactly the old confirmation, untouched.
	 *
	 * @covers ::atf_success_screen_html
	 */
	public function test_plain_is_the_old_markup() {
		$html = atf_success_screen_html( '<p>Done.</p>', array( 'style' => 'plain' ) );

		$this->assertStringNotContainsString( 'atf-success', $html );
		$this->assertSame( '<div class="atf-confirmation" role="status" tabindex="-1"><p>Done.</p></div>', $html );
	}

	/**
	 * The check style draws its SVG; the accent rides as a custom property;
	 * hostile markup in the message is filtered on the way out.
	 *
	 * @covers ::atf_success_screen_html
	 */
	public function test_the_render_is_styled_and_safe() {
		$html = atf_success_screen_html(
			'Fine. <script>alert(1)</script>',
			array(
				'style'  => 'check',
				'accent' => '#ff0055',
			)
		);

		$this->assertStringContainsString( 'atf-success__check-mark', $html );
		$this->assertStringContainsString( '--atf-accent: #ff0055', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	/**
	 * The again-button renders as a link — the one reset that works without
	 * the bundle.
	 *
	 * @covers ::atf_success_screen_html
	 */
	public function test_the_again_button_is_a_link() {
		$html = atf_success_screen_html(
			'Done.',
			array(
				'style'       => 'simple',
				'showButton'  => true,
				'buttonLabel' => 'One more',
			)
		);

		$this->assertStringContainsString( 'atf-success__again', $html );
		$this->assertStringContainsString( '>One more</a>', $html );
	}
}

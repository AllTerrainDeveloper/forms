<?php
/**
 * Themes.
 *
 * Two claims are tested here, and both are load-bearing for the whole design.
 *
 * **The token surface and the stylesheet agree.** A token no CSS rule reads is a
 * control that does nothing; a CSS custom property no token declares is a value
 * no theme can reach. Both directions are asserted against the real stylesheet.
 *
 * **A theme cannot inject CSS.** Token values land inside a `<style>` block, so
 * a value containing a brace could close the rule and open another — which is
 * how a "theme" becomes a way to restyle the page around it or load a remote
 * resource.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * Themes, tokens and the stylesheet that reads them.
 *
 * @group allterrain-forms
 */
class ATF_Test_Themes extends WP_UnitTestCase {

	/**
	 * Tokens the renderer turns into a class rather than a custom property.
	 *
	 * These change the shape of the markup, not just its paint, so no single
	 * `var()` can express them. They are covered by their own test instead of
	 * the CSS-usage sweep.
	 */
	const STRUCTURAL_TOKENS = array( 'field-style', 'label-position' );

	/**
	 * The structural tokens reach the markup as classes.
	 *
	 * The other half of the contract the CSS sweep exempts them from: a theme
	 * setting `label-position` to `floating` must produce a form the stylesheet's
	 * `.atf-labels-floating` rules can reach.
	 *
	 * @covers ::atf_render_form
	 */
	public function test_structural_tokens_reach_the_markup() {
		$form_id = atf_test_form(
			array(
				'fields'   => array(
					array(
						'id'    => 'f1',
						'type'  => 'text',
						'label' => 'Name',
					),
				),
				'settings' => array(
					'theme'          => 'clean',
					'themeOverrides' => array(
						'label-position' => 'floating',
						'field-style'    => 'underline',
					),
				),
			)
		);

		$html = atf_render_form( $form_id );

		$this->assertStringContainsString( 'atf-labels-floating', $html );
		$this->assertStringContainsString( 'atf-fields-underline', $html );

		$css = file_get_contents( ATF_DIR . 'assets/css/form.css' );

		$this->assertStringContainsString( '.atf-labels-floating', $css );
		$this->assertStringContainsString( '.atf-fields-underline', $css );
	}

	/**
	 * Ten themes ship.
	 *
	 * @covers ::atf_builtin_themes
	 */
	public function test_ten_themes_ship() {
		$themes = atf_builtin_themes();

		$this->assertCount( 10, $themes, 'The plugin promises ten built-in themes.' );

		foreach ( $themes as $slug => $theme ) {
			$this->assertNotSame( '', $theme['label'], "Theme {$slug} needs a label." );
			$this->assertIsArray( $theme['tokens'], "Theme {$slug} must declare a token map." );
		}
	}

	/**
	 * Every theme resolves to a complete token map.
	 *
	 * @covers ::atf_resolve_tokens
	 */
	public function test_every_theme_resolves_completely() {
		$expected = array_keys( atf_theme_token_defaults() );

		foreach ( array_keys( atf_builtin_themes() ) as $slug ) {
			$resolved = atf_resolve_tokens( $slug );

			foreach ( $expected as $token ) {
				$this->assertArrayHasKey( $token, $resolved, "Theme {$slug} is missing token {$token}." );
				$this->assertNotSame( '', $resolved[ $token ], "Theme {$slug} resolved {$token} to nothing." );
			}
		}
	}

	/**
	 * Themes only set tokens that exist.
	 *
	 * A typo in a theme's token name is otherwise invisible: the value is stored,
	 * emitted, and read by nothing at all.
	 *
	 * @covers ::atf_builtin_themes
	 */
	public function test_themes_only_set_known_tokens() {
		$known = atf_theme_token_defaults();

		foreach ( atf_builtin_themes() as $slug => $theme ) {
			foreach ( array_keys( $theme['tokens'] ) as $token ) {
				$this->assertArrayHasKey(
					$token,
					$known,
					"Theme {$slug} sets \"{$token}\", which is not a token any CSS rule reads."
				);
			}
		}
	}

	/**
	 * Every declared token is actually used by the stylesheet.
	 *
	 * @covers ::atf_theme_token_defaults
	 */
	public function test_every_token_is_read_by_the_css() {
		$css = file_get_contents( ATF_DIR . 'assets/css/form.css' );

		$this->assertNotEmpty( $css, 'The front-end stylesheet is missing.' );

		$unused = array();

		foreach ( array_keys( atf_theme_token_defaults() ) as $token ) {
			// Two tokens are structural rather than visual: the renderer reads
			// them in PHP and picks a class (`atf-fields-filled`,
			// `atf-labels-floating`), because they change the *markup's shape*
			// and no single custom property can express "put the label inside
			// the field". They are still real, still settable, and still
			// exercised — by `test_structural_tokens_reach_the_markup()` below.
			if ( in_array( $token, self::STRUCTURAL_TOKENS, true ) ) {
				continue;
			}

			if ( false === strpos( $css, '--atf-' . $token ) ) {
				$unused[] = $token;
			}
		}

		$this->assertSame(
			array(),
			$unused,
			'These tokens are declared but no CSS rule reads them, so a theme setting them changes nothing: '
				. implode( ', ', $unused )
		);
	}

	/**
	 * The stylesheet reads no token the token table does not declare.
	 *
	 * @covers ::atf_theme_token_defaults
	 */
	public function test_css_reads_no_undeclared_token() {
		$css   = file_get_contents( ATF_DIR . 'assets/css/form.css' );
		$known = atf_theme_token_defaults();

		preg_match_all( '/var\(\s*--atf-([a-z0-9-]+)/i', $css, $matches );

		$undeclared = array();

		foreach ( array_unique( $matches[1] ) as $token ) {
			// `--atf-image-columns` is set inline by the renderer per field
			// rather than by a theme, so it is deliberately not a theme token.
			if ( 'image-columns' === $token ) {
				continue;
			}

			if ( ! isset( $known[ $token ] ) ) {
				$undeclared[] = $token;
			}
		}

		$this->assertSame(
			array(),
			$undeclared,
			'The stylesheet reads tokens no theme can set: ' . implode( ', ', $undeclared )
		);
	}

	/**
	 * Every control rule outranks a stylesheet that targets `input[type=…]`.
	 *
	 * The bug this pins: WordPress's own `forms.css` carries
	 * `input[type="checkbox"], input[type="radio"] { margin: -0.25rem … }` and
	 * `textarea, input { font-size: 14px }`. Those weigh **(0,1,1)** and
	 * **(0,0,2)**; a rule of ours written as a single class weighs **(0,1,0)** and
	 * loses. The visible result was every checkbox in every theme sitting 7px
	 * above its own label, and `em` offsets computing against 14px while the
	 * label used 16px.
	 *
	 * The fix is a rule, not a patch: control rules are scoped under `.atf-form`,
	 * which makes them (0,2,0) — two classes beat one class plus one type. This
	 * test is what stops the next control being added without it.
	 *
	 * @covers ::atf_render_form
	 */
	public function test_control_rules_outrank_theme_stylesheets() {
		$css = file_get_contents( ATF_DIR . 'assets/css/form.css' );

		// The classes that dress an actual form control. A theme's `input[type]`
		// rules reach every one of them.
		$controls = array(
			'atf-input',
			'atf-textarea',
			'atf-select',
			'atf-multiselect',
			'atf-choice__input',
			'atf-toggle__input',
			'atf-range__input',
			'atf-file__input',
			'atf-total__input',
		);

		$unscoped = array();

		foreach ( preg_split( '/}/', $css ) as $block ) {
			$brace = strpos( $block, '{' );

			if ( false === $brace ) {
				continue;
			}

			$selector = trim( preg_replace( '#/\*.*?\*/#s', '', substr( $block, 0, $brace ) ) );

			if ( '' === $selector || false !== strpos( $selector, '@' ) ) {
				continue;
			}

			foreach ( explode( ',', $selector ) as $single ) {
				$single = trim( $single );

				foreach ( $controls as $control ) {
					if ( false === strpos( $single, '.' . $control ) ) {
						continue;
					}

					if ( $this->middle_specificity( $single ) >= 2 ) {
						continue 2;
					}

					$unscoped[] = $single;
				}
			}
		}

		$this->assertSame(
			array(),
			array_unique( $unscoped ),
			"These control rules weigh only one class, so a theme's `input[type=…]` rule "
				. 'outranks them and wins. Scope them under `.atf-form`: '
				. implode( ' | ', array_unique( $unscoped ) )
		);
	}

	/**
	 * The middle column of a selector's specificity.
	 *
	 * Classes, attribute selectors and pseudo-classes all count the same. That
	 * matters here: `.atf-choice__input[type="checkbox"]` weighs (0,2,0) on one
	 * class and one attribute, and a test that counted only classes would call
	 * it unscoped and be wrong. Pseudo-*elements* belong to the last column and
	 * are excluded.
	 *
	 * @param string $selector One selector, already split on commas.
	 * @return int The `b` column.
	 */
	private function middle_specificity( $selector ) {
		// `::before` first, so its colons are not counted as a pseudo-class.
		$selector = preg_replace( '/::[a-z-]+/i', '', $selector );

		$classes    = preg_match_all( '/\.[a-z_-][a-z0-9_-]*/i', $selector );
		$attributes = preg_match_all( '/\[[^\]]+\]/', $selector );
		$pseudos    = preg_match_all( '/:[a-z-]+/i', $selector );

		return $classes + $attributes + $pseudos;
	}

	/**
	 * A stylesheet with its comments removed.
	 *
	 * @param string $css The stylesheet.
	 * @return string
	 */
	private function without_comments( $css ) {
		return (string) preg_replace( '#/\*.*?\*/#s', '', $css );
	}

	/**
	 * The tick box paints its own checked state.
	 *
	 * The bug this pins: the accent reached the checkbox and the radio as
	 * `accent-color: var( --atf-accent )`, which only does anything while the
	 * browser is still painting the control itself. WordPress's `forms.css` sets
	 * `appearance: none` on every `input[type=checkbox]` and `input[type=radio]`
	 * in the admin, so it did nothing at all — and the mark was drawn instead by
	 * whichever admin stylesheet claimed `:checked::before`. Inside an
	 * OpenStation window that was the shell, which painted it in its own brand
	 * pink while the theme's accent sat there being green.
	 *
	 * `accent-color` is therefore not a way to colour these two controls: it is
	 * a hint that any stylesheet in the document can retire without touching a
	 * single one of our rules. The checked state has to be something we draw.
	 *
	 * @covers ::atf_theme_token_defaults
	 */
	public function test_the_tick_box_draws_its_own_accent() {
		$css = $this->without_comments( file_get_contents( ATF_DIR . 'assets/css/form.css' ) );

		$this->assertMatchesRegularExpression(
			'/\.atf-form \.atf-choice__input,\s*\.atf-form \.atf-toggle__input\s*\{[^}]*appearance:\s*none/s',
			$css,
			'The tick box must declare `appearance: none` itself, so its rendering is '
				. "the same on the front end as in the admin — where WordPress's forms.css "
				. 'declares it for us and the two would otherwise disagree.'
		);

		// Both controls, and the accent by name: a checked box that took its fill
		// from `currentColor` or a literal would drift from the theme the moment
		// somebody changed one and not the other.
		foreach ( array( 'atf-choice__input', 'atf-toggle__input' ) as $control ) {
			$this->assertMatchesRegularExpression(
				'/\.atf-form \.' . preg_quote( $control, '/' ) . ':checked[^{]*\{[^}]*background-color:\s*var\(\s*--atf-accent\s*\)/s',
				$css,
				"`.{$control}:checked` must fill itself with --atf-accent. Without it the "
					. 'checked state is whatever another stylesheet on the page decides.'
			);
		}

		$this->assertDoesNotMatchRegularExpression(
			'/\.atf-form \.atf-(choice|toggle)__input\s*\{[^}]*accent-color/s',
			$css,
			'`accent-color` on the tick box is inert once `appearance` is none. Leaving it '
				. 'in reads as the accent being handled when it is not.'
		);
	}

	/**
	 * The checkbox offset is computed, never a magic number.
	 *
	 * A hardcoded nudge is right for exactly one line-height and wrong for every
	 * theme that changes it — and `--atf-line-height` is a token themes are
	 * expected to change.
	 *
	 * @covers ::atf_theme_token_defaults
	 */
	public function test_checkbox_offset_is_derived_from_the_line_height() {
		// Comments stripped first, and that is not tidiness.
		//
		// These patterns walk from a selector to a declaration with `[^}]*`, which
		// means the first `}` ends the search. A comment inside the rule reading
		// "a site theme setting `input { line-height: 1.65 }`" therefore stopped
		// the match dead — and because the assertion is "this rule exists", the
		// test failed while the CSS it was checking was perfectly correct. A test
		// that a comment can break is a test that gets deleted rather than
		// believed.
		$css = $this->without_comments( file_get_contents( ATF_DIR . 'assets/css/form.css' ) );

		$this->assertMatchesRegularExpression(
			'/\.atf-form \.atf-choice__input\s*\{[^}]*margin-block-start:\s*calc\([^}]*--atf-line-height/s',
			$css,
			'The choice box offset must be derived from --atf-line-height.'
		);

		$this->assertMatchesRegularExpression(
			'/\.atf-form \.atf-toggle__input\s*\{[^}]*line-height:\s*var\(\s*--atf-line-height/s',
			$css,
			'The toggle box must pin its line-height, or a theme setting `input { line-height }` shifts it.'
		);
	}

	/**
	 * A token value that could break out of the style block is refused.
	 *
	 * @dataProvider data_dangerous_values
	 * @covers ::atf_sanitize_tokens
	 *
	 * @param string $value A value that must not survive.
	 */
	public function test_dangerous_token_values_are_refused( $value ) {
		$clean = atf_sanitize_tokens( array( 'accent' => $value ) );

		$this->assertArrayNotHasKey(
			'accent',
			$clean,
			sprintf( 'A token value of %s was accepted.', var_export( $value, true ) )
		);
	}

	/**
	 * Values that must never reach a style block.
	 *
	 * @return array[]
	 */
	public function data_dangerous_values() {
		$values = array(
			'red; } body { display: none; } .x {',
			'}</style><script>alert(1)</script><style>',
			'url( https://evil.example/x.png )',
			'URL(//evil.example/x)',
			'expression( alert( 1 ) )',
			'image-set( "x.png" )',
			'@import url( evil.css )',
			'javascript:alert(1)',
			'red \\3c script',
			'<script>',
			str_repeat( 'a', 500 ),
		);

		$cases = array();

		foreach ( $values as $index => $value ) {
			$cases[ 'value ' . $index ] = array( $value );
		}

		return $cases;
	}

	/**
	 * Ordinary values survive.
	 *
	 * A sanitiser that refused everything would pass the test above and make the
	 * theme system useless, so the other direction matters just as much.
	 *
	 * @dataProvider data_safe_values
	 * @covers ::atf_sanitize_tokens
	 *
	 * @param string $value A value that must survive.
	 */
	public function test_ordinary_token_values_survive( $value ) {
		$clean = atf_sanitize_tokens( array( 'accent' => $value ) );

		$this->assertSame( $value, $clean['accent'] );
	}

	/**
	 * Values a real theme uses.
	 *
	 * @return array[]
	 */
	public function data_safe_values() {
		$values = array(
			'#f252fc',
			'rgba( 255, 255, 255, 0.14 )',
			'linear-gradient( 120deg, #b14aff, #00f0ff )',
			'0 8px 32px rgba( 0, 0, 0, 0.22 )',
			'blur( 18px ) saturate( 160% )',
			'inset 3px 3px 7px rgba( 163, 177, 198, 0.55 )',
			'ui-monospace, SFMono-Regular, Menlo, monospace',
			'clamp( 14px, 2vw, 18px )',
			'0.04em',
			'999px',
		);

		$cases = array();

		foreach ( $values as $index => $value ) {
			$cases[ 'value ' . $index ] = array( $value );
		}

		return $cases;
	}

	/**
	 * Unknown token names are dropped.
	 *
	 * @covers ::atf_sanitize_tokens
	 */
	public function test_unknown_tokens_are_dropped() {
		$clean = atf_sanitize_tokens(
			array(
				'accent'           => '#fff',
				'not-a-real-token' => 'red',
			)
		);

		$this->assertArrayHasKey( 'accent', $clean );
		$this->assertArrayNotHasKey( 'not-a-real-token', $clean );
	}

	/**
	 * The emitted CSS is scoped and well-formed.
	 *
	 * @covers ::atf_tokens_to_css
	 */
	public function test_css_is_scoped() {
		$css = atf_tokens_to_css( '#atf-1-1 .atf-form', array( 'accent' => '#ff0000' ) );

		$this->assertStringStartsWith( '#atf-1-1 .atf-form {', $css );
		$this->assertStringContainsString( '--atf-accent: #ff0000;', $css );
		$this->assertSame( 1, substr_count( $css, '{' ), 'The rule must not be able to open a second block.' );
		$this->assertSame( 1, substr_count( $css, '}' ) );
	}

	/**
	 * Overrides beat the theme, which beats the defaults.
	 *
	 * @covers ::atf_resolve_tokens
	 */
	public function test_overrides_win() {
		$defaults = atf_theme_token_defaults();
		$midnight = atf_resolve_tokens( 'midnight' );

		$this->assertNotSame( $defaults['surface'], $midnight['surface'], 'Midnight should change the surface.' );

		$overridden = atf_resolve_tokens( 'midnight', array( 'surface' => '#123456' ) );

		$this->assertSame( '#123456', $overridden['surface'] );
	}

	/**
	 * An unknown theme falls back rather than failing.
	 *
	 * A form whose theme was deleted should look plain, not blow up.
	 *
	 * @covers ::atf_get_theme
	 */
	public function test_unknown_theme_falls_back() {
		$theme = atf_get_theme( 'a-theme-that-was-deleted' );

		$this->assertSame( 'clean', $theme['slug'] );
		$this->assertNotEmpty( atf_resolve_tokens( 'a-theme-that-was-deleted' ) );
	}

	/**
	 * A saved theme cannot shadow a built-in.
	 *
	 * @covers ::atf_save_theme
	 */
	public function test_saved_theme_cannot_take_a_builtin_slug() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		atf_add_capabilities();

		$saved = atf_save_theme(
			array(
				'label'  => 'Midnight',
				'slug'   => 'midnight',
				'tokens' => array( 'accent' => '#123456' ),
			)
		);

		$this->assertNotWPError( $saved );
		$this->assertNotSame( 'midnight', $saved['slug'] );
		$this->assertSame( 'Midnight', atf_get_theme( 'midnight' )['label'] );
		$this->assertFalse( atf_get_theme( 'midnight' )['custom'] );
	}

	/**
	 * Saving a theme needs the capability.
	 *
	 * @covers ::atf_save_theme
	 */
	public function test_saving_a_theme_requires_capability() {
		wp_set_current_user( 0 );

		$this->assertWPError( atf_save_theme( array( 'label' => 'Sneaky' ) ) );
	}

	/**
	 * Every theme is legible on a light page *and* on a dark one.
	 *
	 * The earlier version of this test measured only solid colours and **skipped**
	 * any theme whose surface was an `rgba()`. Glass and Holo are exactly that
	 * shape, and the skip is what let them ship painting white text on a
	 * translucent surface with nothing behind it — a measured contrast of 1.00 on
	 * an ordinary page. Not "low contrast": invisible.
	 *
	 * So nothing is skipped now. Translucent layers are composited over the page
	 * colour the way a browser composites them, and every theme is checked
	 * against both a white page and a dark one, because a form goes wherever
	 * somebody puts it and a theme cannot require a particular backdrop.
	 *
	 * @dataProvider data_page_colours
	 * @covers ::atf_builtin_themes
	 *
	 * @param string $label Which page is behind the form.
	 * @param array  $page  Its opaque RGB.
	 */
	public function test_themes_are_legible_on_any_page( $label, $page ) {
		foreach ( array_keys( atf_builtin_themes() ) as $slug ) {
			$tokens = atf_resolve_tokens( $slug );
			$text   = $this->to_rgba( $tokens['text'] );

			$this->assertNotNull( $text, sprintf( 'Theme "%s" has an unreadable text colour.', $slug ) );

			// The stack a label actually sits on: the page, then the form's own
			// background, then the field surface — each composited in turn.
			$ground = $page;

			foreach ( array( $tokens['bg'], $tokens['surface'] ) as $layer ) {
				$parsed = $this->to_rgba( $layer );

				if ( $parsed ) {
					$ground = $this->composite( $parsed, $ground );
				}
			}

			$ratio = $this->contrast( $text, $ground );

			$this->assertGreaterThanOrEqual(
				4.5,
				$ratio,
				sprintf(
					'Theme "%s" reads at %.2f against a %s — below the WCAG AA minimum of 4.5. '
						. 'A theme with light text needs to bring its own ground rather than assume the page has one.',
					$slug,
					$ratio,
					$label
				)
			);
		}
	}

	/**
	 * The two backdrops a form has to survive.
	 *
	 * @return array[]
	 */
	public function data_page_colours() {
		return array(
			'white page' => array( 'white page', array( 255, 255, 255, 1.0 ) ),
			'dark page'  => array( 'dark page', array( 20, 20, 20, 1.0 ) ),
		);
	}

	/**
	 * Parses a colour into RGBA, including `rgb()`, `rgba()` and `transparent`.
	 *
	 * @param string $colour The colour.
	 * @return float[]|null Four channels, or null when it is not a flat colour
	 *                      (a gradient, a keyword this does not know).
	 */
	private function to_rgba( $colour ) {
		$colour = trim( (string) $colour );

		if ( 'transparent' === strtolower( $colour ) ) {
			return array( 0.0, 0.0, 0.0, 0.0 );
		}

		if ( preg_match( '/^#([0-9a-f]{3})$/i', $colour, $matches ) ) {
			$hex = $matches[1];

			return array(
				(float) hexdec( str_repeat( $hex[0], 2 ) ),
				(float) hexdec( str_repeat( $hex[1], 2 ) ),
				(float) hexdec( str_repeat( $hex[2], 2 ) ),
				1.0,
			);
		}

		if ( preg_match( '/^#([0-9a-f]{6})$/i', $colour, $matches ) ) {
			return array(
				(float) hexdec( substr( $matches[1], 0, 2 ) ),
				(float) hexdec( substr( $matches[1], 2, 2 ) ),
				(float) hexdec( substr( $matches[1], 4, 2 ) ),
				1.0,
			);
		}

		if ( preg_match( '/rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:[\s,\/]+([\d.]+))?\s*\)/i', $colour, $matches ) ) {
			return array(
				(float) $matches[1],
				(float) $matches[2],
				(float) $matches[3],
				isset( $matches[4] ) ? (float) $matches[4] : 1.0,
			);
		}

		return null;
	}

	/**
	 * Composites a translucent colour over an opaque one, as a browser would.
	 *
	 * @param float[] $over   The translucent layer.
	 * @param float[] $under  The opaque colour beneath it.
	 * @return float[] The resulting opaque colour.
	 */
	private function composite( $over, $under ) {
		$alpha = $over[3];

		return array(
			( $alpha * $over[0] ) + ( ( 1 - $alpha ) * $under[0] ),
			( $alpha * $over[1] ) + ( ( 1 - $alpha ) * $under[1] ),
			( $alpha * $over[2] ) + ( ( 1 - $alpha ) * $under[2] ),
			1.0,
		);
	}

	/**
	 * The WCAG contrast ratio between two opaque colours.
	 *
	 * @param float[] $a First colour.
	 * @param float[] $b Second colour.
	 * @return float
	 */
	private function contrast( $a, $b ) {
		$luminance = static function ( $rgb ) {
			$channels = array();

			foreach ( array_slice( $rgb, 0, 3 ) as $value ) {
				$channel    = $value / 255;
				$channels[] = $channel <= 0.03928
					? $channel / 12.92
					: pow( ( $channel + 0.055 ) / 1.055, 2.4 );
			}

			return ( 0.2126 * $channels[0] ) + ( 0.7152 * $channels[1] ) + ( 0.0722 * $channels[2] );
		};

		$first  = $luminance( $a );
		$second = $luminance( $b );

		return ( max( $first, $second ) + 0.05 ) / ( min( $first, $second ) + 0.05 );
	}
}

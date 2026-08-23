<?php
/**
 * Themes are data.
 *
 * A theme is one flat map of design tokens and nothing else. There is no theme
 * PHP, no theme template, no theme stylesheet -- the renderer emits the same
 * markup for every theme and the tokens decide what it looks like. That is the
 * whole reason a new theme can be made without code: if a theme were a
 * stylesheet, making one would mean writing CSS, and "expandable without code"
 * would be a lie told in a README.
 *
 * The bet this file makes is that the token surface is wide enough. A theme can
 * change colour, radius, shadow, border, spacing, typography, label position,
 * button shape, focus ring, motion, and the field's fill style. If a theme ever
 * needs something the tokens cannot say, the answer is a new token here rather
 * than an escape hatch into raw CSS -- because the moment one theme reaches for
 * raw CSS, every theme after it has to as well.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every token a theme may set, with the value used when it does not.
 *
 * This list is the contract. The renderer emits a custom property for each of
 * these and the stylesheet reads only these, so a token missing from here is a
 * token no theme can reach, and a token in here that the stylesheet ignores is
 * a control that does nothing. The PHPUnit suite asserts both directions.
 *
 * The defaults are the Clean theme's values, which is deliberate: an unthemed
 * form and the Clean theme are the same form, so there is no unstyled state to
 * maintain separately.
 *
 * @since 0.1.0
 *
 * @return array<string, string> Token name => default value.
 */
function alltfo_theme_token_defaults() {
	$tokens = array(

		/* ------------------------------------------------------------ Colour */
		'bg'                   => 'transparent',
		'surface'              => '#ffffff',
		'surface-alt'          => '#f6f7f7',
		'text'                 => '#1e1e1e',
		'text-muted'           => '#646970',
		'heading'              => '#1e1e1e',
		'accent'               => '#2271b1',
		'accent-text'          => '#ffffff',
		'accent-soft'          => 'rgba( 34, 113, 177, 0.1 )',
		'border'               => '#8c8f94',
		'border-focus'         => '#2271b1',
		'error'                => '#d63638',
		'error-soft'           => 'rgba( 214, 54, 56, 0.08 )',
		'success'              => '#008a20',
		'placeholder'          => '#8c8f94',

		/* ------------------------------------------------------------ Radius */
		'radius-field'         => '4px',
		'radius-button'        => '4px',
		'radius-card'          => '8px',
		'radius-check'         => '3px',

		/* ------------------------------------------------------------ Shadow */
		'shadow-field'         => 'none',
		'shadow-field-focus'   => 'none',
		'shadow-button'        => 'none',
		'shadow-button-hover'  => 'none',
		'shadow-card'          => 'none',

		/* ------------------------------------------------------------ Border */
		'border-width'         => '1px',
		'border-style'         => 'solid',
		// `outline` draws a border all round; `filled` drops the border for a
		// tinted fill; `underline` keeps only the bottom edge; `none` removes
		// the chrome entirely. The stylesheet keys off this one string.
		'field-style'          => 'outline',

		/* ------------------------------------------------------------- Space */
		'gap-fields'           => '20px',
		'gap-label'            => '6px',
		'pad-field-x'          => '12px',
		'pad-field-y'          => '9px',
		'pad-card'             => '0px',
		'field-height'         => 'auto',

		/* -------------------------------------------------------- Typography */
		'font-family'          => 'inherit',
		'font-family-heading'  => 'inherit',
		'size-base'            => '16px',
		'size-label'           => '14px',
		'size-hint'            => '13px',
		'size-heading'         => '20px',
		'size-button'          => '15px',
		'weight-label'         => '600',
		'weight-heading'       => '600',
		'weight-button'        => '600',
		'letter-spacing'       => 'normal',
		'letter-spacing-label' => 'normal',
		'line-height'          => '1.5',
		'transform-label'      => 'none',

		// Label. top | inside | floating | left | hidden. `hidden` keeps the label in
		// the DOM for screen readers and only hides it visually — there is no
		// theme in which throwing away the accessible name is the right call.
		'label-position'       => 'top',
		'label-width'          => '180px',

		/* ------------------------------------------------------------ Button */
		'button-bg'            => 'var( --atf-accent )',
		'button-text'          => 'var( --atf-accent-text )',
		'button-bg-hover'      => 'var( --atf-accent )',
		'button-border'        => 'transparent',
		'button-pad-x'         => '20px',
		'button-pad-y'         => '11px',
		'button-width'         => 'auto',
		'button-align'         => 'start',
		'button-transform'     => 'none',

		/* ------------------------------------------------------------- Focus */
		'focus-ring-width'     => '2px',
		'focus-ring-color'     => 'var( --atf-accent )',
		'focus-ring-offset'    => '1px',

		/* ------------------------------------------------------------ Motion */
		'transition-duration'  => '120ms',
		'transition-easing'    => 'ease',
		'field-lift'           => 'none',

		/* ----------------------------------------------------------- Effects */
		'backdrop-blur'        => 'none',
		'field-gradient'       => 'none',
		'card-gradient'        => 'none',
		'card-border'          => 'none',
		'progress-height'      => '4px',
	);

	/**
	 * Filters the token surface itself.
	 *
	 * Adding a token here makes it settable by every theme and by the Theme
	 * Studio, which builds its controls from this list. A plugin adding a token
	 * is also responsible for the CSS that reads it.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $tokens Token name => default value.
	 */
	return apply_filters( 'alltfo_theme_tokens', $tokens );
}

/**
 * Which control the Theme Studio should show for each token.
 *
 * Derived from the token name rather than declared per token, so a new token
 * gets a sensible editor for free and only the exceptions need listing. The
 * Studio reads this over REST and builds itself from it -- which is what makes
 * "expandable without code" true for the *editor* as well as the renderer.
 *
 * @since 0.1.0
 *
 * @param string $token Token name.
 * @return array { control: string, options?: string[], unit?: string, label: string, group: string }
 */
function alltfo_theme_token_control( $token ) {
	$enums = array(
		'field-style'      => array( 'outline', 'filled', 'underline', 'none' ),
		'label-position'   => array( 'top', 'inside', 'floating', 'left', 'hidden' ),
		'button-width'     => array( 'auto', 'full' ),
		'button-align'     => array( 'start', 'center', 'end' ),
		'border-style'     => array( 'solid', 'dashed', 'dotted', 'double', 'none' ),
		'transform-label'  => array( 'none', 'uppercase', 'lowercase', 'capitalize' ),
		'button-transform' => array( 'none', 'uppercase', 'lowercase', 'capitalize' ),
	);

	if ( isset( $enums[ $token ] ) ) {
		$control = array(
			'control' => 'select',
			'options' => $enums[ $token ],
		);
	} elseif ( 0 === strpos( $token, 'shadow-' ) || 0 === strpos( $token, 'font-family' ) || in_array( $token, array( 'field-gradient', 'card-gradient', 'backdrop-blur', 'field-lift', 'card-border', 'transition-easing', 'letter-spacing', 'letter-spacing-label', 'field-height', 'button-bg', 'button-text', 'button-bg-hover', 'button-border', 'focus-ring-color' ), true ) ) {
		// Free text, because these are whole CSS values -- a shadow stack, a
		// font stack, a gradient -- and no picker expresses them. The Studio
		// still previews live, so a wrong value is visible immediately.
		$control = array( 'control' => 'text' );
	} elseif ( preg_match( '/(^|-)(bg|surface|surface-alt|text|text-muted|heading|accent|accent-text|accent-soft|border|border-focus|error|error-soft|success|placeholder)$/', $token ) ) {
		$control = array( 'control' => 'color' );
	} elseif ( preg_match( '/(radius|pad|gap|width|height|size|offset)/', $token ) ) {
		$control = array(
			'control' => 'length',
			'unit'    => 'px',
		);
	} elseif ( 0 === strpos( $token, 'weight-' ) ) {
		$control = array(
			'control' => 'select',
			'options' => array( '300', '400', '500', '600', '700', '800' ),
		);
	} elseif ( 0 === strpos( $token, 'transition-duration' ) ) {
		$control = array(
			'control' => 'length',
			'unit'    => 'ms',
		);
	} else {
		$control = array( 'control' => 'text' );
	}

	$control['label'] = ucfirst( str_replace( '-', ' ', $token ) );
	$control['group'] = alltfo_theme_token_group( $token );

	/**
	 * Filters the Theme Studio control for one token.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $control The control descriptor.
	 * @param string $token   Token name.
	 */
	return apply_filters( 'alltfo_theme_token_control', $control, $token );
}

/**
 * Which Studio section a token belongs in.
 *
 * @since 0.1.0
 *
 * @param string $token Token name.
 * @return string Group slug.
 */
function alltfo_theme_token_group( $token ) {
	$prefixes = array(
		'radius-'     => 'shape',
		'shadow-'     => 'shadow',
		'border'      => 'shape',
		'field-'      => 'fields',
		'pad-'        => 'space',
		'gap-'        => 'space',
		'font-'       => 'type',
		'size-'       => 'type',
		'weight-'     => 'type',
		'letter-'     => 'type',
		'line-'       => 'type',
		'transform-'  => 'type',
		'label-'      => 'labels',
		'button-'     => 'button',
		'focus-'      => 'focus',
		'transition-' => 'motion',
		'card-'       => 'shape',
	);

	foreach ( $prefixes as $prefix => $group ) {
		if ( 0 === strpos( $token, $prefix ) ) {
			return $group;
		}
	}

	return 'colour';
}

/**
 * The ten themes that ship.
 *
 * Each is a partial token map -- only what it changes from the defaults -- so a
 * new token added to the surface later inherits sensibly into all ten rather
 * than needing ten edits.
 *
 * @since 0.1.0
 *
 * @return array<string, array> Theme slug => { label, description, tokens, dark }.
 */
function alltfo_builtin_themes() {
	$themes = array(

		'clean'     => array(
			'label'       => __( 'Clean', 'allterrain-forms' ),
			'description' => __( 'The neutral one. Outlined fields, soft corners, nothing shouting.', 'allterrain-forms' ),
			'tokens'      => array(),
		),

		'midnight'  => array(
			'label'       => __( 'Midnight', 'allterrain-forms' ),
			'description' => __( 'Dark, high contrast, one luminous accent.', 'allterrain-forms' ),
			'dark'        => true,
			'tokens'      => array(
				'surface'            => '#1d2327',
				'surface-alt'        => '#2c3338',
				'text'               => '#f0f0f1',
				'text-muted'         => '#a7aaad',
				'heading'            => '#ffffff',
				'accent'             => '#4f94d4',
				'accent-text'        => '#0b1015',
				'accent-soft'        => 'rgba( 79, 148, 212, 0.16 )',
				'border'             => '#5b6570',
				'border-focus'       => '#4f94d4',
				'placeholder'        => '#787c82',
				'error'              => '#ff6b6d',
				'error-soft'         => 'rgba( 255, 107, 109, 0.12 )',
				'success'            => '#4ab866',
				'radius-field'       => '6px',
				'radius-button'      => '6px',
				'shadow-field-focus' => '0 0 0 3px rgba( 79, 148, 212, 0.24 )',
			),
		),

		'glass'     => array(
			'label'       => __( 'Glass', 'allterrain-forms' ),
			'description' => __( 'Translucent surfaces over whatever is behind them, hairline borders, a real blur.', 'allterrain-forms' ),
			// Light text, so the form needs a dark ground under it.
			'dark'        => true,
			'tokens'      => array(
				// The scrim is what makes this theme work anywhere.
				//
				// Glass paints white text on a 14%-white surface. With nothing
				// behind it that is white-on-white -- a measured contrast of
				// 1.00 on an ordinary light page, which is not "low contrast",
				// it is invisible. A glass surface needs something to be glass
				// *over*, and a theme cannot require the page to supply one.
				//
				// 0.82 alpha is the point where the stack clears WCAG AA on a
				// white page while still passing enough of the page through,
				// under the blur, to read as glass rather than as a dark panel.
				'bg'                 => 'rgba( 18, 21, 28, 0.82 )',
				'surface'            => 'rgba( 255, 255, 255, 0.14 )',
				'surface-alt'        => 'rgba( 255, 255, 255, 0.08 )',
				'text'               => '#ffffff',
				'text-muted'         => 'rgba( 255, 255, 255, 0.72 )',
				'heading'            => '#ffffff',
				'accent'             => 'rgba( 255, 255, 255, 0.92 )',
				'accent-text'        => '#101418',
				'accent-soft'        => 'rgba( 255, 255, 255, 0.14 )',
				'border'             => 'rgba( 255, 255, 255, 0.28 )',
				'border-focus'       => 'rgba( 255, 255, 255, 0.72 )',
				'placeholder'        => 'rgba( 255, 255, 255, 0.55 )',
				'radius-field'       => '12px',
				'radius-button'      => '999px',
				'radius-card'        => '20px',
				'backdrop-blur'      => 'blur( 18px ) saturate( 160% )',
				'shadow-card'        => '0 8px 32px rgba( 0, 0, 0, 0.22 )',
				'shadow-field-focus' => '0 0 0 4px rgba( 255, 255, 255, 0.18 )',
				'pad-card'           => '28px',
				'pad-field-x'        => '16px',
				'pad-field-y'        => '12px',
				'card-border'        => '1px solid rgba( 255, 255, 255, 0.22 )',
				'button-pad-x'       => '26px',
			),
		),

		'brutal'    => array(
			'label'       => __( 'Brutal', 'allterrain-forms' ),
			'description' => __( 'Hard edges, thick black rules, an offset shadow that does not blur.', 'allterrain-forms' ),
			'tokens'      => array(
				'surface'              => '#ffffff',
				'surface-alt'          => '#fffbe6',
				'text'                 => '#000000',
				'heading'              => '#000000',
				'accent'               => '#ffe600',
				'accent-text'          => '#000000',
				'accent-soft'          => '#fff9b1',
				'border'               => '#000000',
				'border-focus'         => '#000000',
				'border-width'         => '3px',
				'radius-field'         => '0',
				'radius-button'        => '0',
				'radius-card'          => '0',
				'radius-check'         => '0',
				'shadow-field'         => '4px 4px 0 #000000',
				'shadow-field-focus'   => '6px 6px 0 #000000',
				'shadow-button'        => '5px 5px 0 #000000',
				'shadow-button-hover'  => '2px 2px 0 #000000',
				'button-border'        => '#000000',
				'weight-label'         => '800',
				'weight-button'        => '800',
				'transform-label'      => 'uppercase',
				'button-transform'     => 'uppercase',
				'letter-spacing-label' => '0.04em',
				'focus-ring-width'     => '3px',
				'focus-ring-color'     => '#000000',
				'field-lift'           => 'translate( -2px, -2px )',
			),
		),

		'paper'     => array(
			'label'       => __( 'Paper', 'allterrain-forms' ),
			'description' => __( 'Warm stock, serif headings, ruled fields. Reads like a form you would be handed.', 'allterrain-forms' ),
			'tokens'      => array(
				'bg'                  => '#fbf8f1',
				'surface'             => 'transparent',
				'surface-alt'         => '#f3eee2',
				'text'                => '#2b2621',
				'text-muted'          => '#6f6558',
				'heading'             => '#1c1814',
				'accent'              => '#7a5c3e',
				'accent-text'         => '#fbf8f1',
				'accent-soft'         => 'rgba( 122, 92, 62, 0.1 )',
				'border'              => '#b7a68e',
				'border-focus'        => '#7a5c3e',
				'field-style'         => 'underline',
				'radius-field'        => '0',
				'radius-button'       => '2px',
				'font-family-heading' => 'Georgia, "Times New Roman", serif',
				'size-heading'        => '24px',
				'weight-label'        => '500',
				'gap-fields'          => '26px',
				'pad-field-x'         => '2px',
				'pad-card'            => '32px',
				'card-border'         => '1px solid #e0d5c0',
			),
		),

		'neon'      => array(
			'label'       => __( 'Neon', 'allterrain-forms' ),
			'description' => __( 'Near-black, saturated gradient, focus rings that glow.', 'allterrain-forms' ),
			'dark'        => true,
			'tokens'      => array(
				'bg'                  => '#08070f',
				'surface'             => '#12101f',
				'surface-alt'         => '#1a1730',
				'text'                => '#f2f0ff',
				'text-muted'          => '#9d97c4',
				'heading'             => '#ffffff',
				'accent'              => '#b14aff',
				'accent-text'         => '#0b0810',
				'accent-soft'         => 'rgba( 177, 74, 255, 0.18 )',
				'border'              => '#524a8a',
				'border-focus'        => '#00f0ff',
				'placeholder'         => '#6b6591',
				'error'               => '#ff4d8d',
				'success'             => '#39ffa0',
				'radius-field'        => '10px',
				'radius-button'       => '10px',
				'radius-card'         => '18px',
				'shadow-field-focus'  => '0 0 0 1px #00f0ff, 0 0 18px rgba( 0, 240, 255, 0.45 )',
				'shadow-button'       => '0 0 24px rgba( 177, 74, 255, 0.45 )',
				'shadow-button-hover' => '0 0 34px rgba( 177, 74, 255, 0.7 )',
				'button-bg'           => 'linear-gradient( 120deg, #b14aff, #00f0ff )',
				'button-bg-hover'     => 'linear-gradient( 120deg, #c46bff, #4ff5ff )',
				'button-text'         => '#0b0810',
				'focus-ring-color'    => '#00f0ff',
				'pad-card'            => '28px',
				'card-gradient'       => 'radial-gradient( circle at 20% 0%, rgba( 177, 74, 255, 0.16 ), transparent 60% )',
			),
		),

		'terminal'  => array(
			'label'       => __( 'Terminal', 'allterrain-forms' ),
			'description' => __( 'Monospace, phosphor green, square corners. A form for people who like a prompt.', 'allterrain-forms' ),
			'dark'        => true,
			'tokens'      => array(
				'bg'                   => '#0a0f0a',
				'surface'              => '#0d140d',
				'surface-alt'          => '#111a11',
				'text'                 => '#4aff91',
				'text-muted'           => '#2e9959',
				'heading'              => '#7dffb4',
				'accent'               => '#4aff91',
				'accent-text'          => '#04170b',
				'accent-soft'          => 'rgba( 74, 255, 145, 0.12 )',
				'border'               => '#2b7c4a',
				'border-focus'         => '#4aff91',
				'placeholder'          => '#246b41',
				'error'                => '#ff5f56',
				'font-family'          => 'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace',
				'font-family-heading'  => 'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace',
				'radius-field'         => '0',
				'radius-button'        => '0',
				'radius-card'          => '0',
				'radius-check'         => '0',
				'size-base'            => '15px',
				'weight-label'         => '400',
				'weight-button'        => '700',
				'transform-label'      => 'uppercase',
				'letter-spacing-label' => '0.08em',
				'button-transform'     => 'uppercase',
				'shadow-field-focus'   => '0 0 0 1px #4aff91',
				'pad-card'             => '24px',
				'card-border'          => '1px solid #1f5c37',
			),
		),

		'soft'      => array(
			'label'       => __( 'Soft', 'allterrain-forms' ),
			'description' => __( 'Neumorphic. Low contrast, fields pressed into the surface rather than drawn on it.', 'allterrain-forms' ),
			'tokens'      => array(
				'bg'                  => '#e8ecf3',
				'surface'             => '#e8ecf3',
				'surface-alt'         => '#e2e7ef',
				'text'                => '#3d4657',
				'text-muted'          => '#8a93a6',
				'heading'             => '#2c3444',
				'accent'              => '#6c7ee1',
				'accent-text'         => '#ffffff',
				'accent-soft'         => 'rgba( 108, 126, 225, 0.14 )',
				'border'              => 'transparent',
				'border-width'        => '0',
				'field-style'         => 'filled',
				'placeholder'         => '#a3abbb',
				'radius-field'        => '14px',
				'radius-button'       => '14px',
				'radius-card'         => '24px',
				'shadow-field'        => 'inset 3px 3px 7px rgba( 163, 177, 198, 0.55 ), inset -3px -3px 7px rgba( 255, 255, 255, 0.9 )',
				'shadow-field-focus'  => 'inset 4px 4px 9px rgba( 163, 177, 198, 0.7 ), inset -4px -4px 9px rgba( 255, 255, 255, 1 )',
				'shadow-button'       => '5px 5px 12px rgba( 163, 177, 198, 0.6 ), -5px -5px 12px rgba( 255, 255, 255, 0.9 )',
				'shadow-button-hover' => '2px 2px 6px rgba( 163, 177, 198, 0.6 ), -2px -2px 6px rgba( 255, 255, 255, 0.9 )',
				'shadow-card'         => '10px 10px 26px rgba( 163, 177, 198, 0.45 ), -10px -10px 26px rgba( 255, 255, 255, 0.85 )',
				'pad-field-x'         => '18px',
				'pad-field-y'         => '13px',
				'pad-card'            => '32px',
				'gap-fields'          => '22px',
				'focus-ring-width'    => '0',
			),
		),

		'editorial' => array(
			'label'       => __( 'Editorial', 'allterrain-forms' ),
			'description' => __( 'Large serif headings, labels in the margin, a lot of air.', 'allterrain-forms' ),
			'tokens'      => array(
				'surface'             => '#ffffff',
				'surface-alt'         => '#faf9f7',
				'text'                => '#16151a',
				'text-muted'          => '#6b6a72',
				'heading'             => '#0b0a0e',
				'accent'              => '#16151a',
				'accent-text'         => '#ffffff',
				'accent-soft'         => 'rgba( 22, 21, 26, 0.06 )',
				'border'              => '#d8d5cf',
				'border-focus'        => '#16151a',
				'label-position'      => 'left',
				'label-width'         => '200px',
				'field-style'         => 'underline',
				'radius-field'        => '0',
				'radius-button'       => '0',
				'font-family-heading' => '"Iowan Old Style", Georgia, "Times New Roman", serif',
				'size-heading'        => '30px',
				'size-label'          => '15px',
				'weight-label'        => '400',
				'weight-heading'      => '400',
				'gap-fields'          => '34px',
				'pad-field-x'         => '0',
				'pad-field-y'         => '10px',
				'button-pad-x'        => '32px',
				'button-transform'    => 'uppercase',
				'letter-spacing'      => '0.01em',
			),
		),

		'holo'      => array(
			'label'       => __( 'Holo', 'allterrain-forms' ),
			'description' => __( 'The OpenStation brand. Mesh accents, Pulse focus, Void ink.', 'allterrain-forms' ),
			'dark'        => true,
			'tokens'      => array(
				// As with Glass: a 4%-white surface under near-white text has
				// nothing to be seen against on a light page (measured 1.14),
				// so the theme brings its own Void ground rather than assuming
				// the desktop's wallpaper is behind it.
				'bg'                  => 'rgba( 14, 8, 22, 0.82 )',
				'surface'             => 'rgba( 255, 255, 255, 0.04 )',
				'surface-alt'         => 'rgba( 255, 255, 255, 0.07 )',
				'text'                => '#f0f0f1',
				'text-muted'          => '#a7aaad',
				'heading'             => '#ffffff',
				'accent'              => '#f252fc',
				'accent-text'         => '#10001a',
				'accent-soft'         => 'rgba( 242, 82, 252, 0.16 )',
				'border'              => 'rgba( 255, 255, 255, 0.22 )',
				'border-focus'        => '#f252fc',
				'placeholder'         => 'rgba( 255, 255, 255, 0.42 )',
				'radius-field'        => '10px',
				'radius-button'       => '10px',
				'radius-card'         => '16px',
				'backdrop-blur'       => 'blur( 12px )',
				'shadow-field-focus'  => '0 0 0 3px rgba( 242, 82, 252, 0.28 )',
				'button-bg'           => 'linear-gradient( 120deg, #f252fc, #7b6cff 55%, #3ad7ff )',
				'button-bg-hover'     => 'linear-gradient( 120deg, #ff6bff, #8f80ff 55%, #5ee0ff )',
				'button-text'         => '#10001a',
				'shadow-button-hover' => '0 0 28px rgba( 242, 82, 252, 0.4 )',
				'focus-ring-color'    => '#f252fc',
				'pad-card'            => '26px',
				'card-border'         => '1px solid rgba( 255, 255, 255, 0.16 )',
			),
		),
	);

	/**
	 * Filters the built-in themes.
	 *
	 * Adding a theme here makes it available everywhere a built-in is, without
	 * creating a post -- which is the right shape for a theme that ships inside
	 * another plugin rather than one a user made.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array> $themes Theme slug => definition.
	 */
	return apply_filters( 'alltfo_builtin_themes', $themes );
}

/**
 * Themes registered in code by other plugins.
 *
 * A function-static rather than a global so nothing can reach in and mutate it
 * without going through `alltfo_register_theme()`, which normalises.
 *
 * @since 0.1.0
 *
 * @param array|null $set Internal. Replaces the whole table.
 * @return array<string, array> Theme slug => definition.
 */
function &alltfo_theme_store( $set = null ) {
	static $themes = array();

	if ( null !== $set ) {
		$themes = $set;
	}

	return $themes;
}

/**
 * Registers a theme in code.
 *
 * The parallel of `alltfo_register_field_type()`, and the right shape for a theme
 * that ships inside another plugin rather than one a user made in the Studio.
 *
 * Only the tokens a theme changes need listing -- everything else inherits from
 * the defaults, so a token added to the surface in a later release reaches every
 * registered theme without an edit.
 *
 * Call it on `alltfo_loaded`.
 *
 * @since 0.1.0
 *
 * @param string $slug Theme slug. Lowercase, `[a-z0-9_-]`.
 * @param array  $args {
 *     Theme definition.
 *
 *     @type string $label       Human name. Required.
 *     @type string $description One line, shown under the name in the picker.
 *     @type bool   $dark        Whether the theme is dark, so surfaces around it can adapt.
 *     @type array  $tokens      Token name => value. Unknown names are dropped.
 * }
 * @return true|WP_Error True on success.
 */
function alltfo_register_theme( $slug, $args = array() ) {
	$slug = sanitize_key( $slug );

	if ( '' === $slug ) {
		return new WP_Error( 'alltfo_theme_slug', __( 'A theme needs a slug.', 'allterrain-forms' ) );
	}

	if ( empty( $args['label'] ) ) {
		return new WP_Error(
			'alltfo_theme_label',
			/* translators: %s: theme slug. */
			sprintf( __( 'Theme "%s" needs a label.', 'allterrain-forms' ), $slug )
		);
	}

	$themes          = &alltfo_theme_store();
	$themes[ $slug ] = array(
		'label'       => (string) $args['label'],
		'description' => isset( $args['description'] ) ? (string) $args['description'] : '',
		'dark'        => ! empty( $args['dark'] ),
		// Sanitised at registration rather than at use, so a typo in a token
		// name is dropped once instead of being carried around and silently
		// ignored by every reader.
		'tokens'      => alltfo_sanitize_tokens( isset( $args['tokens'] ) ? $args['tokens'] : array() ),
	);

	return true;
}

/**
 * Removes a theme registered in code.
 *
 * Forms using it are deliberately left alone; they fall back to Clean the next
 * time they render, which is recoverable where rewriting every form is not.
 *
 * @since 0.1.0
 *
 * @param string $slug Theme slug.
 * @return bool True when a theme was removed.
 */
function alltfo_unregister_theme( $slug ) {
	$themes = &alltfo_theme_store();
	$slug   = sanitize_key( $slug );

	if ( ! isset( $themes[ $slug ] ) ) {
		return false;
	}

	unset( $themes[ $slug ] );

	return true;
}

/**
 * Every theme available on this site: the built-ins plus the saved ones.
 *
 * Saved themes win on a slug collision, so a site can override a built-in by
 * making one with the same slug -- and get it back by deleting theirs.
 *
 * @since 0.1.0
 *
 * @return array<string, array> Theme slug => { label, description, tokens, custom, id }.
 */
function alltfo_get_themes() {
	$themes = array();

	// Three sources, each beating the one before: the built-ins, then anything
	// registered in code by another plugin, then the ones a user saved. A saved
	// theme winning on a slug collision is what lets a site override a shipped
	// theme and get it back by deleting theirs.
	$registered = array_merge( alltfo_builtin_themes(), alltfo_theme_store() );

	foreach ( $registered as $slug => $theme ) {
		$themes[ $slug ] = array_merge(
			array(
				'label'       => $slug,
				'description' => '',
				'tokens'      => array(),
				'dark'        => false,
			),
			$theme,
			array(
				'slug'   => $slug,
				'custom' => false,
				'id'     => 0,
			)
		);
	}

	foreach ( alltfo_get_custom_themes() as $theme ) {
		$themes[ $theme['slug'] ] = $theme;
	}

	return $themes;
}

/**
 * The themes saved as posts.
 *
 * @since 0.1.0
 *
 * @return array[] Theme records.
 */
function alltfo_get_custom_themes() {
	$posts = get_posts(
		array(
			'post_type'        => ALLTFO_THEME_TYPE,
			'post_status'      => array( 'publish', 'draft' ),
			'numberposts'      => 200,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'suppress_filters' => false,
		)
	);

	$themes = array();

	foreach ( $posts as $post ) {
		$tokens = json_decode( (string) get_post_meta( $post->ID, ALLTFO_META_TOKENS, true ), true );

		$themes[ $post->post_name ] = array(
			'slug'        => $post->post_name,
			'label'       => $post->post_title,
			'description' => $post->post_excerpt,
			'tokens'      => alltfo_sanitize_tokens( is_array( $tokens ) ? $tokens : array() ),
			'dark'        => false,
			'custom'      => true,
			'id'          => $post->ID,
		);
	}

	return $themes;
}

/**
 * One theme by slug.
 *
 * An unknown slug returns Clean rather than null, because every caller of this
 * is about to render a form and none of them has anything useful to do with a
 * missing theme. A form whose theme was deleted should look plain, not blow up.
 *
 * @since 0.1.0
 *
 * @param string $slug Theme slug.
 * @return array The theme record.
 */
function alltfo_get_theme( $slug ) {
	$themes = alltfo_get_themes();
	$slug   = sanitize_key( (string) $slug );

	if ( isset( $themes[ $slug ] ) ) {
		return $themes[ $slug ];
	}

	return isset( $themes['clean'] ) ? $themes['clean'] : array(
		'slug'   => 'clean',
		'label'  => 'Clean',
		'tokens' => array(),
		'custom' => false,
		'id'     => 0,
		'dark'   => false,
	);
}

/**
 * Resolves a theme and any per-form overrides into a complete token map.
 *
 * Three layers, each beating the one before: the defaults, the theme, then the
 * form's own overrides. That last layer is what lets one form on a site nudge
 * its accent colour without anybody having to make a whole new theme for it.
 *
 * @since 0.1.0
 *
 * @param string $slug      Theme slug.
 * @param array  $overrides Per-form token overrides.
 * @return array<string, string> Every token, resolved.
 */
function alltfo_resolve_tokens( $slug, $overrides = array() ) {
	$theme  = alltfo_get_theme( $slug );
	$tokens = array_merge(
		alltfo_theme_token_defaults(),
		alltfo_sanitize_tokens( $theme['tokens'] ),
		alltfo_sanitize_tokens( is_array( $overrides ) ? $overrides : array() )
	);

	/**
	 * Filters a form's resolved tokens just before they become CSS.
	 *
	 * The last word on how a form looks. A site can key off the theme slug here
	 * to tune a built-in globally without forking it.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $tokens    Resolved tokens.
	 * @param string                $slug      Theme slug.
	 * @param array                 $overrides The per-form overrides that were applied.
	 */
	return apply_filters( 'alltfo_resolved_tokens', $tokens, $slug, $overrides );
}

/**
 * Keeps a token map to known names and CSS-safe values.
 *
 * This is the security boundary of the whole theme system. Token values land
 * inside the form wrapper's `style` attribute, so a value that could close a
 * declaration and smuggle in another is how a "theme" becomes a way to restyle
 * the page around it, or to load a remote resource. Braces, semicolons, angle brackets,
 * `@` rules, backslash escapes and `url(`/`expression(` are all refused rather
 * than escaped, because there is no legitimate token value that needs one.
 *
 * @since 0.1.0
 *
 * @param array $tokens Raw tokens.
 * @return array<string, string> Only known names, with usable values.
 */
function alltfo_sanitize_tokens( $tokens ) {
	if ( ! is_array( $tokens ) ) {
		return array();
	}

	$known = alltfo_theme_token_defaults();
	$clean = array();

	foreach ( $tokens as $name => $value ) {
		$name = is_string( $name ) ? strtolower( trim( $name ) ) : '';

		if ( ! isset( $known[ $name ] ) || ! is_scalar( $value ) ) {
			continue;
		}

		$value = trim( (string) $value );

		if ( '' === $value ) {
			continue;
		}

		if ( preg_match( '/[{};<>\\\\]/', $value ) ) {
			continue;
		}

		if ( preg_match( '/(^|[^a-z-])(url|expression|image-set)\s*\(/i', $value ) ) {
			continue;
		}

		if ( false !== stripos( $value, '@import' ) || false !== stripos( $value, 'javascript:' ) ) {
			continue;
		}

		if ( strlen( $value ) > 400 ) {
			continue;
		}

		$clean[ $name ] = $value;
	}

	return $clean;
}

/**
 * Turns a resolved token map into CSS custom-property declarations for one form.
 *
 * Shaped for a `style` attribute rather than a `<style>` block, because the
 * renderer puts the tokens straight on the form's own wrapper element. That
 * buys the same scoping the old per-instance rule bought -- two forms with
 * different themes routinely share a page -- without the plugin printing a
 * style tag by hand.
 *
 * @since 0.1.0
 *
 * @param array $tokens Resolved tokens.
 * @return string Declarations for `esc_attr()`, or the empty string for none.
 */
function alltfo_tokens_to_declarations( $tokens ) {
	$lines = array();

	foreach ( $tokens as $name => $value ) {
		// Both halves are re-checked here rather than trusted from the caller:
		// this function is public, and the only safe assumption about its
		// arguments is none.
		$name = preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $name ) );

		if ( '' === $name ) {
			continue;
		}

		$value = trim( (string) $value );

		if ( '' === $value || preg_match( '/[{};<>\\\\]/', $value ) ) {
			continue;
		}

		$lines[] = sprintf( '--atf-%s: %s', $name, $value );
	}

	if ( ! $lines ) {
		return '';
	}

	return implode( '; ', $lines ) . ';';
}

/**
 * Saves a user-made theme.
 *
 * @since 0.1.0
 *
 * @param array $args {
 *     The theme to save.
 *
 *     @type int    $id          Existing theme post to update, or 0 to create it.
 *     @type string $label       Human name.
 *     @type string $slug        Slug. Derived from the label when empty.
 *     @type string $description One line.
 *     @type array  $tokens      Token map.
 * }
 * @return array|WP_Error The saved theme record.
 */
function alltfo_save_theme( $args ) {
	if ( ! alltfo_can_edit_forms() ) {
		return new WP_Error( 'alltfo_forbidden', __( 'You cannot change form themes.', 'allterrain-forms' ), array( 'status' => 403 ) );
	}

	$label = isset( $args['label'] ) ? sanitize_text_field( (string) $args['label'] ) : '';

	if ( '' === $label ) {
		return new WP_Error( 'alltfo_theme_no_label', __( 'A theme needs a name.', 'allterrain-forms' ), array( 'status' => 400 ) );
	}

	$id     = isset( $args['id'] ) ? absint( $args['id'] ) : 0;
	$slug   = isset( $args['slug'] ) && '' !== $args['slug'] ? sanitize_title( $args['slug'] ) : sanitize_title( $label );
	$tokens = alltfo_sanitize_tokens( isset( $args['tokens'] ) ? $args['tokens'] : array() );

	// A saved theme must not take a built-in's slug, or `alltfo_get_themes()` would
	// hide the built-in behind it and there would be no way back short of the
	// database. Suffixing is friendlier than refusing the save.
	$builtins = alltfo_builtin_themes();

	if ( isset( $builtins[ $slug ] ) ) {
		$slug .= '-custom';
	}

	$postarr = array(
		'post_type'    => ALLTFO_THEME_TYPE,
		'post_title'   => $label,
		'post_name'    => $slug,
		'post_excerpt' => isset( $args['description'] ) ? sanitize_text_field( (string) $args['description'] ) : '',
		'post_status'  => 'publish',
	);

	if ( $id ) {
		$postarr['ID'] = $id;
		$result        = wp_update_post( $postarr, true );
	} else {
		$result = wp_insert_post( $postarr, true );
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	update_post_meta( $result, ALLTFO_META_TOKENS, wp_slash( wp_json_encode( $tokens ) ) );

	/**
	 * Fires after a user-made theme is saved.
	 *
	 * @since 0.1.0
	 *
	 * @param int   $theme_id The theme post.
	 * @param array $tokens   The tokens stored.
	 */
	do_action( 'alltfo_theme_saved', $result, $tokens );

	$post = get_post( $result );

	return array(
		'slug'        => $post->post_name,
		'label'       => $post->post_title,
		'description' => $post->post_excerpt,
		'tokens'      => $tokens,
		'custom'      => true,
		'dark'        => false,
		'id'          => $result,
	);
}

/**
 * Deletes a user-made theme.
 *
 * Forms referencing it are deliberately left alone. They fall back to Clean the
 * next time they render, which is recoverable; rewriting every form that used
 * the theme is not.
 *
 * @since 0.1.0
 *
 * @param int $theme_id The theme post.
 * @return true|WP_Error
 */
function alltfo_delete_theme( $theme_id ) {
	if ( ! alltfo_can_edit_forms() ) {
		return new WP_Error( 'alltfo_forbidden', __( 'You cannot change form themes.', 'allterrain-forms' ), array( 'status' => 403 ) );
	}

	$theme_id = absint( $theme_id );
	$post     = $theme_id ? get_post( $theme_id ) : null;

	if ( ! $post || ALLTFO_THEME_TYPE !== $post->post_type ) {
		return new WP_Error( 'alltfo_theme_missing', __( 'That theme does not exist.', 'allterrain-forms' ), array( 'status' => 404 ) );
	}

	wp_delete_post( $theme_id, true );

	return true;
}

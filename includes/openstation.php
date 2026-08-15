<?php
/**
 * OpenStation integration.
 *
 * Everything here sits behind a `function_exists()` gate resolved through
 * `shell-api.php`. With no shell installed, none of it runs and AllTerrain Forms
 * is a forms plugin with an admin page. With the shell installed, it becomes a
 * desktop app: three native windows, a wallpaper icon, a dock badge, a desktop
 * widget, and three entries in the command palette.
 *
 * The builder is a **native** window rather than an iframe, and that is the
 * whole reason the drag-and-drop feels the way it does. Rendering into the
 * shell's own DOM is what gives it `wp.os.dragManager` -- one pointer pipeline
 * shared with the wallpaper's file tiles and every other window -- so:
 *
 * - a field can be dragged from the palette onto the canvas, and between two
 *   open builder windows;
 * - an image dragged out of WP Explorer can be dropped onto an image-choice
 *   field and become one of its options;
 * - an entry dragged out of the Entries window carries the payload type
 *   `allterrain-forms/entry`, so any other plugin can accept it -- drop one on
 *   an AllTerrain Work column and it becomes a task.
 *
 * None of that is reachable from inside an iframe.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * The drag payload types this plugin emits.
 *
 * Exported as constants and documented, so another plugin can register a drop
 * target that accepts one without knowing anything else about this plugin.
 *
 * @since 0.1.0
 */
const ATF_DRAG_FIELD = 'allterrain-forms/field';
const ATF_DRAG_FORM  = 'allterrain-forms/form';
const ATF_DRAG_ENTRY = 'allterrain-forms/entry';

add_action( 'plugins_loaded', 'atf_maybe_init_openstation', 20 );

/**
 * Wires up the shell integrations, if there is a shell to wire into.
 *
 * On `plugins_loaded` rather than at file scope: plugins load alphabetically, so
 * `allterrain-forms` runs before `desktop-mode` and none of the shell's
 * functions exist yet when this file is first read. Checking then would fail on
 * every site, every time.
 *
 * The gate is on the registration function rather than on a version constant, so
 * a shell release that renames itself or drops the API degrades to "no desktop
 * integration" instead of a fatal error on every request.
 *
 * @since 0.1.0
 *
 * @return void
 */
function atf_maybe_init_openstation() {
	if ( ! atf_shell_has( 'register_window' ) ) {
		return;
	}

	add_action( 'init', 'atf_register_shell_surfaces', 20 );

	// Registered against both spellings of the hook. Which one fires depends on
	// the shell's version, and a listener for a hook that never fires costs
	// nothing -- far less than deciding at boot which shell is present, since
	// the answer can change between `plugins_loaded` and the hook firing.
	foreach ( atf_shell_hooks( 'mode_init' ) as $hook ) {
		add_action( $hook, 'atf_enqueue_in_shell' );
	}

	add_action( 'admin_enqueue_scripts', 'atf_enqueue_shell_styles', 20 );
}

/**
 * Registers the windows, the icon, the widget and the commands.
 *
 * @since 0.1.0
 *
 * @return void
 */
function atf_register_shell_surfaces() {
	// Either capability is enough to have *something* here: somebody who may
	// read entries but not build forms still gets the Entries window and its
	// dock row. Each registration carries its own `capabilities`, so the shell
	// gates them individually rather than this one check standing in for all
	// three -- which is what previously denied an entries-only user everything.
	if ( ! atf_can_edit_forms() && ! atf_can_read_entries() ) {
		return;
	}

	$registered = atf_shell_call(
		'register_window',
		'allterrain-forms',
		array(
			'title'        => __( 'AllTerrain Forms', 'allterrain-forms' ),
			'icon'         => 'dashicons-feedback',
			'template'     => 'atf_render_builder_template',
			'script'       => 'allterrain-forms-builder',
			'style'        => 'allterrain-forms-builder',
			'width'        => 1280,
			'height'       => 820,
			'min_width'    => 720,
			'min_height'   => 480,
			// 'none', not 'dock'. All three windows are reached through a
			// single dock tile with a hover menu, registered in `dock.ts` --
			// three tiles for one plugin is three claims on the same corner of
			// the user's attention.
			'placement'    => 'none',
			'capabilities' => array( 'atf_edit_forms' ),
		)
	);

	// A `WP_Error` here means the shell refused the builder -- a malformed
	// registration, or an unmet capability for a user who may only read
	// entries. The entries window is registered independently and gated on its
	// own capability, so a refusal here must not take it down too; only the
	// surfaces that reference the builder by id are skipped.
	$has_builder = ! is_wp_error( $registered );

	atf_shell_call(
		'register_window',
		'allterrain-forms-entries',
		array(
			'title'        => __( 'Form Entries', 'allterrain-forms' ),
			'icon'         => 'dashicons-list-view',
			'template'     => 'atf_render_entries_template',
			'script'       => 'allterrain-forms-entries',
			'style'        => 'allterrain-forms-builder',
			'width'        => 1180,
			'height'       => 760,
			'min_width'    => 640,
			'min_height'   => 420,
			'placement'    => 'none',
			'capabilities' => array( 'atf_read_entries' ),
		)
	);

	atf_shell_call(
		'register_window',
		'allterrain-forms-themes',
		array(
			'title'        => __( 'Theme Studio', 'allterrain-forms' ),
			'icon'         => 'dashicons-art',
			'template'     => 'atf_render_theme_studio_template',
			// The Studio ships inside the builder bundle rather than its own.
			// It shares the token table, the control renderers and the live
			// preview with the builder's own theme tab, and a second bundle
			// would either duplicate all of it or need a third shared chunk for
			// a window most people open rarely.
			'script'       => 'allterrain-forms-builder',
			'style'        => 'allterrain-forms-builder',
			'width'        => 1100,
			'height'       => 780,
			'min_width'    => 680,
			'min_height'   => 460,
			'placement'    => 'none',
			'capabilities' => array( 'atf_edit_forms' ),
		)
	);

	// The eye button in the builder window's title bar, which opens the form's
	// real front-end preview as a paired window beside it. Registering the
	// *script* here is what makes the button paint for a session that was
	// already open when this plugin was activated -- without it, the button only
	// appears after a reload, which is exactly when nobody is looking for it.
	if ( $has_builder && atf_shell_has( 'register_titlebar_button_script' ) ) {
		atf_shell_call( 'register_titlebar_button_script', 'allterrain-forms-builder' );
	}

	if ( $has_builder && atf_shell_has( 'register_icon' ) ) {
		atf_shell_call(
			'register_icon',
			'allterrain-forms',
			array(
				'title'        => __( 'Forms', 'allterrain-forms' ),
				'icon'         => 'dashicons-feedback',
				'window'       => 'allterrain-forms',
				'position'     => 30,
				'capabilities' => array( 'atf_edit_forms' ),
			)
		);
	}

	if ( atf_shell_has( 'register_widget' ) ) {
		atf_shell_call(
			'register_widget',
			'allterrain-forms/recent',
			array(
				'label'          => __( 'Recent submissions', 'allterrain-forms' ),
				'description'    => __( 'The latest entries across your forms, with a conversion sparkline.', 'allterrain-forms' ),
				'icon'           => 'dashicons-feedback',
				'script'         => 'allterrain-forms-widget',
				'movable'        => true,
				'resizable'      => true,
				'min_width'      => 260,
				'min_height'     => 200,
				'default_width'  => 340,
				'default_height' => 380,
				'capabilities'   => array( 'atf_read_entries' ),
			)
		);
	}

	if ( atf_shell_has( 'register_command' ) ) {
		$commands = array(
			array(
				'slug'        => 'allterrain-forms',
				'label'       => __( 'Forms: open the builder', 'allterrain-forms' ),
				'description' => __( 'Build a form by dragging fields onto a canvas.', 'allterrain-forms' ),
				'icon'        => 'dashicons-feedback',
				'script'      => 'allterrain-forms-builder',
			),
			array(
				'slug'        => 'allterrain-forms-entries',
				'label'       => __( 'Forms: open entries', 'allterrain-forms' ),
				'description' => __( 'Read, filter and export what people have submitted.', 'allterrain-forms' ),
				'icon'        => 'dashicons-list-view',
				'script'      => 'allterrain-forms-entries',
			),
			array(
				'slug'        => 'allterrain-forms-themes',
				'label'       => __( 'Forms: open the Theme Studio', 'allterrain-forms' ),
				'description' => __( 'Make a form theme without writing any CSS.', 'allterrain-forms' ),
				'icon'        => 'dashicons-art',
				'script'      => 'allterrain-forms-builder',
			),
		);

		foreach ( $commands as $command ) {
			atf_shell_call( 'register_command', $command );
		}
	}
}

/**
 * The builder window's body markup.
 *
 * The shell clones this into the window before calling the JavaScript render
 * callback, so the callback enhances existing markup rather than building from
 * nothing -- which means the window paints its three panes and a loading state
 * immediately instead of flashing empty while the bundle boots.
 *
 * @since 0.1.0
 *
 * @return void
 */
function atf_render_builder_template() {
	?>
	<div class="atfb" data-atfb-root>
		<div class="atfb__bar" data-atfb-bar>
			<os-spinner preset="inline"></os-spinner>
			<span class="atfb__loading"><?php esc_html_e( 'Loading your forms…', 'allterrain-forms' ); ?></span>
		</div>
		<div class="atfb__body">
			<aside class="atfb__palette" data-atfb-palette aria-label="<?php esc_attr_e( 'Field palette', 'allterrain-forms' ); ?>"></aside>
			<main class="atfb__canvas" data-atfb-canvas aria-label="<?php esc_attr_e( 'Form canvas', 'allterrain-forms' ); ?>"></main>
			<aside class="atfb__inspector" data-atfb-inspector aria-label="<?php esc_attr_e( 'Field settings', 'allterrain-forms' ); ?>"></aside>
		</div>
	</div>
	<?php
}

/**
 * The entries window's body markup.
 *
 * @since 0.1.0
 *
 * @return void
 */
function atf_render_entries_template() {
	?>
	<div class="atfe" data-atfe-root>
		<div class="atfe__bar" data-atfe-bar>
			<os-spinner preset="inline"></os-spinner>
			<span class="atfe__loading"><?php esc_html_e( 'Loading entries…', 'allterrain-forms' ); ?></span>
		</div>
		<div class="atfe__body">
			<div class="atfe__list" data-atfe-list></div>
			<div class="atfe__detail" data-atfe-detail></div>
		</div>
	</div>
	<?php
}

/**
 * The Theme Studio window's body markup.
 *
 * @since 0.1.0
 *
 * @return void
 */
function atf_render_theme_studio_template() {
	?>
	<div class="atfs" data-atfs-root>
		<div class="atfs__bar" data-atfs-bar>
			<os-spinner preset="inline"></os-spinner>
			<span class="atfs__loading"><?php esc_html_e( 'Loading themes…', 'allterrain-forms' ); ?></span>
		</div>
		<div class="atfs__body">
			<aside class="atfs__controls" data-atfs-controls aria-label="<?php esc_attr_e( 'Theme controls', 'allterrain-forms' ); ?>"></aside>
			<main class="atfs__preview" data-atfs-preview aria-label="<?php esc_attr_e( 'Live preview', 'allterrain-forms' ); ?>"></main>
		</div>
	</div>
	<?php
}

/**
 * Loads the bundles into the shell.
 *
 * `openstation_mode_init` fires while the shell is rendering, which is the
 * documented place for a plugin to enqueue shell-level code. Naming the handle
 * on the window registration is not enough on its own: the shell enqueues the
 * handle but never runs this plugin's `wp_add_inline_script()`, so the bundle
 * would boot with no `window.allTerrainForms` to read.
 *
 * @since 0.1.0
 *
 * @return void
 */
function atf_enqueue_in_shell() {
	if ( ! atf_can_edit_forms() && ! atf_can_read_entries() ) {
		return;
	}

	wp_enqueue_script( 'allterrain-forms-config' );
	wp_enqueue_script( 'allterrain-forms-dock' );
	wp_enqueue_style( 'allterrain-forms-builder' );
}

/**
 * Puts the stylesheets on shell pages before anything renders.
 *
 * Separate from the enqueue above because the *widget* also needs this CSS, and
 * the widget's bundle loads lazily -- possibly after first paint. Registering
 * the style on the widget would not help: the shell injects a stylesheet link
 * for a window's `style` handle, but a widget card that mounts before its CSS
 * arrives renders as unstyled text for a frame.
 *
 * @since 0.1.0
 *
 * @return void
 */
function atf_enqueue_shell_styles() {
	if ( ! atf_shell_is_active() || atf_shell_is_chromeless() ) {
		return;
	}

	if ( ! atf_can_edit_forms() && ! atf_can_read_entries() ) {
		return;
	}

	wp_enqueue_style( 'allterrain-forms-builder' );
	wp_enqueue_script( 'allterrain-forms-config' );
	wp_enqueue_script( 'allterrain-forms-dock' );
}

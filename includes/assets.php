<?php
/**
 * Script and style handles.
 *
 * Four bundles, loaded on four different schedules:
 *
 * - `form` — the front end. Enqueued by the shortcode and the block, only on
 *   pages that actually contain a form.
 * - `builder` — the native window's script. Loaded by the shell when the window
 *   opens, or by the admin fallback page.
 * - `entries` — the entries window, which most people open far less often than
 *   the builder and should not pay for on every load.
 * - `widget` — loaded by the shell only when somebody has the widget on their
 *   desktop, so it is **registered and never enqueued**. Calling
 *   `wp_enqueue_script()` on it here would put it on every admin page for every
 *   user, including the ones who never added it.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'atf_register_assets', 5 );

/**
 * Registers every handle.
 *
 * On `init` at 5, so the registrations in `openstation.php` -- which name these
 * handles -- can rely on them existing when they run at 20.
 *
 * @since 0.1.0
 *
 * @return void
 */
function atf_register_assets() {
	$suffix = atf_asset_suffix();

	// A `false` src is WordPress's supported way to ship inline-only JS: a real
	// handle with nothing to fetch. Being a *dependency* of every bundle rather
	// than merely enqueued alongside them is what makes it reliable -- enqueue
	// order is not execution order once other plugins are enqueueing too, and a
	// bundle that runs before its config reads `undefined` and silently does
	// nothing.
	wp_register_script( 'allterrain-forms-config', false, array(), ATF_VERSION, true );

	wp_register_style(
		'allterrain-forms',
		ATF_URL . 'assets/css/form.css',
		array(),
		atf_asset_version( 'assets/css/form.css' )
	);

	wp_register_style(
		'allterrain-forms-explorer',
		ATF_URL . 'assets/css/explorer.css',
		array(),
		atf_asset_version( 'assets/css/explorer.css' )
	);

	wp_register_style(
		'allterrain-forms-builder',
		ATF_URL . 'assets/css/builder.css',
		array( 'allterrain-forms' ),
		atf_asset_version( 'assets/css/builder.css' )
	);

	wp_register_script(
		'allterrain-forms-front',
		ATF_URL . "assets/js/form{$suffix}.js",
		array( 'allterrain-forms-config' ),
		atf_asset_version( "assets/js/form{$suffix}.js" ),
		true
	);

	wp_register_script(
		'allterrain-forms-builder',
		ATF_URL . "assets/js/builder{$suffix}.js",
		array( 'allterrain-forms-config' ),
		atf_asset_version( "assets/js/builder{$suffix}.js" ),
		true
	);

	wp_register_script(
		'allterrain-forms-entries',
		ATF_URL . "assets/js/entries{$suffix}.js",
		array( 'allterrain-forms-config' ),
		atf_asset_version( "assets/js/entries{$suffix}.js" ),
		true
	);

	wp_register_script(
		'allterrain-forms-analytics',
		ATF_URL . "assets/js/analytics{$suffix}.js",
		array( 'allterrain-forms-config' ),
		atf_asset_version( "assets/js/analytics{$suffix}.js" ),
		true
	);

	wp_register_style(
		'allterrain-forms-analytics',
		ATF_URL . 'assets/css/analytics.css',
		array( 'allterrain-forms-builder' ),
		atf_asset_version( 'assets/css/analytics.css' )
	);

	// The dock tile. Registered separately from every window bundle because it
	// is the one script that loads for everybody at boot, so it has to stay
	// small enough that paying for it is never a question.
	wp_register_script(
		'allterrain-forms-dock',
		ATF_URL . "assets/js/dock{$suffix}.js",
		array( 'allterrain-forms-config' ),
		atf_asset_version( "assets/js/dock{$suffix}.js" ),
		true
	);

	wp_register_script(
		'allterrain-forms-widget',
		ATF_URL . "assets/js/widget{$suffix}.js",
		array( 'allterrain-forms-config' ),
		atf_asset_version( "assets/js/widget{$suffix}.js" ),
		true
	);

	atf_print_config( 'allterrain-forms-config' );
}

/**
 * Attaches the config blob to a handle.
 *
 * Attached at registration rather than lazily, because the widget bundle is
 * loaded by the shell long after `wp_print_scripts()` has run -- by which point
 * an inline script added on an enqueue hook has already missed its moment.
 *
 * The REST nonce is included, which is the one piece here that is per-user and
 * therefore uncacheable. That is why the front-end config is printed separately
 * and holds no nonce for a logged-out visitor: a page cache would serve one
 * visitor's nonce to everybody.
 *
 * @since 0.1.0
 *
 * @param string $handle The script handle to attach to.
 * @return void
 */
function atf_print_config( $handle ) {
	$config = array(
		'restUrl'   => esc_url_raw( rest_url( ATF_REST_NAMESPACE ) ),
		'wpRestUrl' => esc_url_raw( rest_url() ),
		'nonce'     => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
		'adminUrl'  => esc_url_raw( admin_url() ),
		'version'   => ATF_VERSION,
		'canEdit'   => atf_can_edit_forms(),
		'canRead'   => atf_can_read_entries(),
		// Read by the dock so the demo-data row appears without a reload, and by
		// the analytics window so the developer panel does. It is a preference,
		// never an authorisation -- every route it reveals checks a capability of
		// its own.
		'devMode'   => atf_developer_mode(),
		'locale'    => get_locale(),
		'i18n'      => atf_client_strings(),
	);

	/**
	 * Filters the configuration blob handed to the JavaScript bundles.
	 *
	 * @since 0.1.0
	 *
	 * @param array $config The config.
	 */
	$config = apply_filters( 'atf_script_config', $config );

	wp_add_inline_script(
		$handle,
		'window.allTerrainForms = ' . wp_json_encode( $config ) . ';',
		'before'
	);
}

/**
 * Strings the bundles need, translated on the server.
 *
 * Passed as data rather than loaded through `wp_set_script_translations()`
 * because that requires a `.json` per locale shipped in the plugin, and this
 * plugin ships no translation files -- so the JED route would silently produce
 * English while looking like it was set up correctly.
 *
 * @since 0.1.0
 *
 * @return array<string, string>
 */
function atf_client_strings() {
	return array(
		'required'      => __( 'This is required.', 'allterrain-forms' ),
		'invalidEmail'  => __( 'That does not look like an email address.', 'allterrain-forms' ),
		'invalidUrl'    => __( 'That does not look like a web address.', 'allterrain-forms' ),
		'tooShort'      => __( 'That is too short.', 'allterrain-forms' ),
		'tooLong'       => __( 'That is too long.', 'allterrain-forms' ),
		'tooSmall'      => __( 'That number is too small.', 'allterrain-forms' ),
		'tooBig'        => __( 'That number is too large.', 'allterrain-forms' ),
		'badFormat'     => __( 'That is not in the expected format.', 'allterrain-forms' ),
		'sending'       => __( 'Sending…', 'allterrain-forms' ),
		'sent'          => __( 'Sent.', 'allterrain-forms' ),
		'failed'        => __( 'That did not send. Please try again.', 'allterrain-forms' ),
		'checkForm'     => __( 'Please check the form and try again.', 'allterrain-forms' ),
		'errorsFound'   => __( 'There are problems to fix.', 'allterrain-forms' ),
		'step'          => __( 'Step', 'allterrain-forms' ),
		'of'            => __( 'of', 'allterrain-forms' ),
		'removeRow'     => __( 'Remove this row', 'allterrain-forms' ),
		'addRow'        => __( 'Add another', 'allterrain-forms' ),
		'clear'         => __( 'Clear', 'allterrain-forms' ),
		'saving'        => __( 'Saving…', 'allterrain-forms' ),
		'saved'         => __( 'Saved', 'allterrain-forms' ),
		'saveFailed'    => __( 'Could not save', 'allterrain-forms' ),
		'untitledField' => __( 'Untitled field', 'allterrain-forms' ),
		'dropHere'      => __( 'Drop a field here', 'allterrain-forms' ),
		'emptyCanvas'   => __( 'Drag a field from the left to begin.', 'allterrain-forms' ),
		'confirmDelete' => __( 'Delete this? It cannot be undone.', 'allterrain-forms' ),
	);
}

/**
 * Puts the front-end bundle and stylesheet on the page.
 *
 * Called by the shortcode and the block rather than hooked, so a page with no
 * form on it ships neither.
 *
 * @since 0.1.0
 *
 * @return void
 */
function atf_enqueue_form_assets() {
	wp_enqueue_style( 'allterrain-forms' );
	wp_enqueue_script( 'allterrain-forms-front' );
}

/**
 * The cache-busting version for one asset.
 *
 * `ATF_VERSION` alone is right for a release and actively wrong during
 * development: the plugin version does not change between rebuilds, so the URL
 * does not change either, and the browser keeps serving the bundle it cached
 * before the last `npm run build`. The symptom is the worst kind -- code that is
 * provably correct on disk and provably absent in the page.
 *
 * @since 0.1.0
 *
 * @param string $relative_path Path under the plugin directory.
 * @return string
 */
function atf_asset_version( $relative_path ) {
	$developing = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG );

	if ( ! $developing ) {
		return ATF_VERSION;
	}

	$file = ATF_DIR . ltrim( $relative_path, '/' );

	if ( ! file_exists( $file ) ) {
		return ATF_VERSION;
	}

	return (string) filemtime( $file );
}

/**
 * `.min` unless the site asked for readable sources.
 *
 * @since 0.1.0
 *
 * @return string Either `.min` or an empty string.
 */
function atf_asset_suffix() {
	return ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
}

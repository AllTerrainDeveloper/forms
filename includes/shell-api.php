<?php
/**
 * Naming the shell.
 *
 * The desktop shell was called Desktop Mode and is now called OpenStation, and
 * the rename went all the way down: `desktop_mode_register_window()` became
 * `openstation_register_window()`, and every hook and constant with it.
 * AllTerrain Forms ships to sites running either version and cannot know which,
 * so it asks for a capability by its bare name and this file resolves the
 * spelling.
 *
 * Deliberately a lookup rather than a version check. A site mid-upgrade, a fork,
 * or a shell that renames itself again all degrade to "no desktop integration"
 * instead of a fatal error on every request -- which is the same promise the
 * rest of this plugin makes to sites with no shell at all.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prefixes to try, current first.
 *
 * @since 0.1.0
 */
const ATF_SHELL_PREFIXES = array( 'openstation_', 'desktop_mode_' );

/**
 * Resolves a shell function to whichever name this install has.
 *
 * @since 0.1.0
 *
 * @param string $name Bare function name, e.g. `register_window`.
 * @return string The callable name, or an empty string when no shell provides it.
 */
function atf_shell_function( $name ) {
	foreach ( ATF_SHELL_PREFIXES as $prefix ) {
		if ( function_exists( $prefix . $name ) ) {
			return $prefix . $name;
		}
	}

	return '';
}

/**
 * Whether the shell offers a capability at all.
 *
 * @since 0.1.0
 *
 * @param string $name Bare function name.
 * @return bool True when some spelling of it exists.
 */
function atf_shell_has( $name ) {
	return '' !== atf_shell_function( $name );
}

/**
 * Calls a shell function by its bare name.
 *
 * @since 0.1.0
 *
 * @param string $name    Bare function name.
 * @param mixed  ...$args Arguments to pass through.
 * @return mixed The return value, or null when no shell provides it.
 */
function atf_shell_call( $name, ...$args ) {
	$fn = atf_shell_function( $name );

	return $fn ? call_user_func_array( $fn, $args ) : null;
}

/**
 * Every spelling of a shell hook.
 *
 * Returned as a list so callers can register against all of them. A listener for
 * a hook that never fires costs nothing, and it is far cheaper than deciding at
 * boot which shell is present -- the answer can change between `plugins_loaded`
 * and the hook actually firing.
 *
 * @since 0.1.0
 *
 * @param string $name Bare hook name, e.g. `mode_init`.
 * @return string[] Hook names.
 */
function atf_shell_hooks( $name ) {
	$hooks = array();

	foreach ( ATF_SHELL_PREFIXES as $prefix ) {
		$hooks[] = $prefix . $name;
	}

	return $hooks;
}

/**
 * Determines whether the shell is installed *and* switched on for this user.
 *
 * Two separate questions, and both matter. `atf_shell_has()` answers "is the
 * plugin active"; `openstation_is_enabled()` answers "has this particular user
 * opted in", since the shell is a per-user preference rather than a site-wide
 * one. Only when both hold should the builder present itself as a desktop app
 * rather than as an admin page.
 *
 * @since 0.1.0
 *
 * @return bool True when the desktop shell is active for the current user.
 */
function atf_shell_is_active() {
	if ( ! atf_shell_has( 'register_window' ) || ! atf_shell_has( 'is_enabled' ) ) {
		return false;
	}

	return (bool) atf_shell_call( 'is_enabled' );
}

/**
 * Whether the current request is an admin page rendering inside a shell window.
 *
 * Chromeless requests are admin pages loaded inside a window iframe with the
 * admin bar and menu suppressed. The builder is a *native* window, so it never
 * takes this path itself -- but the standalone admin page does, when a user
 * reaches it through the shell's own menu, and it drops its page heading there.
 *
 * @since 0.1.0
 *
 * @return bool True when rendering inside a shell window iframe.
 */
function atf_shell_is_chromeless() {
	if ( ! atf_shell_has( 'is_chromeless_request' ) ) {
		return false;
	}

	return (bool) atf_shell_call( 'is_chromeless_request' );
}

/**
 * Tells an admin without OpenStation why the desktop is not happening.
 *
 * The `Requires Plugins: desktop-mode` header is the real gate — WordPress 6.5+
 * refuses to activate this plugin without OpenStation and refuses to deactivate
 * OpenStation underneath it. This notice covers the installs that header cannot
 * reach: an older WordPress that ignores it, or a site that force-removed the
 * shell from disk. Visitors' published forms keep rendering either way, because
 * a missing admin dependency must never take down somebody's front end.
 *
 * @since 0.3.0
 *
 * @return void
 */
function atf_shell_missing_notice() {
	if ( atf_shell_has( 'register_window' ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
		esc_html__( 'AllTerrain Forms needs OpenStation.', 'allterrain-forms' ),
		wp_kses(
			sprintf(
				/* translators: %s: link to install OpenStation. */
				__( 'The form builder is an OpenStation desktop app, and the shell is not running on this site. <a href="%s">Install and activate OpenStation</a> to use it. Published forms keep working for your visitors in the meantime.', 'allterrain-forms' ),
				esc_url( admin_url( 'plugin-install.php?s=openstation&tab=search&type=term' ) )
			),
			array( 'a' => array( 'href' => array() ) )
		)
	);
}
add_action( 'admin_notices', 'atf_shell_missing_notice' );

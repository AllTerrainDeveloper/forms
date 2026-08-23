<?php
/**
 * Developer mode.
 *
 * OpenStation has a per-user **Developer mode** switch, in Preferences →
 * Features. It is off for everybody by default and it is what gates the shell's
 * own developer-facing surfaces — the Starter Widget, the missing-import warner.
 * This plugin reads the same switch, so a person who has turned developer tools
 * on once gets them everywhere rather than having to find a second toggle.
 *
 * # What it gates here
 *
 * The demo-data tools: a survey with several hundred realistic submissions,
 * generated so the analytics have something to be analytics *of*. That is a
 * genuinely useful thing and a genuinely dangerous one — it writes hundreds of
 * entries into a live database — so it is not something to leave lying around in
 * the menu of a site that is collecting real enquiries.
 *
 * # Developer mode is not a permission
 *
 * It says "show me developer things", not "you may do developer things". Every
 * route it gates is *also* gated on `alltfo_edit_forms`, and the two are checked
 * separately on purpose: a preference is stored per user and a capability is not,
 * so treating the preference as authorisation would mean anybody who could write
 * their own user meta could seed a database.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether developer mode is on for a user.
 *
 * Reads OpenStation's own setting. Without OpenStation there is no switch to
 * read and this is false — the tools it gates are shell surfaces, so there would
 * be nowhere to put them anyway.
 *
 * @since 0.1.0
 *
 * @param int $user_id The user, or 0 for the current one.
 * @return bool
 */
function alltfo_developer_mode( $user_id = 0 ) {
	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
	$enabled = false;

	if ( $user_id && function_exists( 'openstation_get_os_settings' ) ) {
		$settings = openstation_get_os_settings( $user_id );
		$enabled  = is_array( $settings ) && ! empty( $settings['developerModeEnabled'] );
	}

	/**
	 * Filters whether developer mode is on.
	 *
	 * The way to switch the demo-data tools on without OpenStation — a CI run, a
	 * plain wp-admin install, a site that wants them for one role:
	 *
	 *     add_filter( 'alltfo_developer_mode', function ( $on ) {
	 *         return $on || current_user_can( 'manage_options' );
	 *     } );
	 *
	 * Returning true does not grant anything on its own: every route this gates
	 * checks `alltfo_edit_forms` as well.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $enabled Whether it is on.
	 * @param int  $user_id The user being asked about.
	 */
	return (bool) apply_filters( 'alltfo_developer_mode', $enabled, $user_id );
}

/**
 * Whether the current user may use the developer tools.
 *
 * Both halves, in one place, so no caller can accidentally check only the one it
 * happened to be thinking about.
 *
 * @since 0.1.0
 *
 * @return bool
 */
function alltfo_can_use_developer_tools() {
	return alltfo_developer_mode() && alltfo_can_edit_forms();
}

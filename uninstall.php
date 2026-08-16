<?php
/**
 * Uninstall.
 *
 * Runs only when the plugin is *deleted*, never when it is merely deactivated —
 * which is why the capabilities removed here are deliberately left alone by
 * `atf_deactivate()`. A site that deactivates to debug something and reactivates
 * a minute later must not find every editor's form permissions gone.
 *
 * **Entries are not deleted by default.** They are somebody's submissions —
 * enquiries, applications, orders — and a plugin deletion is not consent to
 * destroy them. Deleting the plugin leaves the data in the database where it can
 * be exported or recovered; a site that genuinely wants it gone defines
 * `ATF_REMOVE_ALL_DATA` and gets exactly that.
 *
 * On multisite the plugin's footprint is per site — capabilities, posts,
 * transients, uploads — so a network deletion walks every site and cleans each
 * one, with the same "entries survive unless `ATF_REMOVE_ALL_DATA`" promise
 * applying to each site individually.
 *
 * @package AllTerrain_Forms
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Cleans this plugin out of the current site.
 *
 * Everything in here reads the *current* site's state — `wp_roles()`,
 * `$wpdb->posts`, `wp_upload_dir()` — so on multisite it must run inside a
 * `switch_to_blog()`, which re-derives all three for the switched site.
 *
 * @since 0.1.0
 *
 * @return void
 */
function atf_uninstall_site() {
	/*
	 * The capabilities this plugin granted.
	 *
	 * Listed here rather than read from the plugin's own code, because the
	 * plugin's files are not loaded during uninstall and requiring them would
	 * run its bootstrap in a context it was never written for.
	 */
	$atf_caps = array(
		'atf_edit_forms',
		'atf_read_entries',
		'atf_delete_entries',
		'atf_manage_settings',
		'edit_atf_forms',
		'edit_others_atf_forms',
		'publish_atf_forms',
		'read_private_atf_forms',
		'delete_atf_forms',
		'delete_others_atf_forms',
		'edit_atf_entries',
		'edit_others_atf_entries',
		'publish_atf_entries',
		'read_private_atf_entries',
		'delete_atf_entries',
		'delete_others_atf_entries',
	);

	foreach ( wp_roles()->role_objects as $atf_role ) {
		foreach ( $atf_caps as $atf_cap ) {
			$atf_role->remove_cap( $atf_cap );
		}
	}

	wp_clear_scheduled_hook( 'atf_apply_retention' );

	// Everything below destroys data, and only on an explicit opt-in.
	if ( ! defined( 'ATF_REMOVE_ALL_DATA' ) || ! ATF_REMOVE_ALL_DATA ) {
		return;
	}

	global $wpdb;

	$atf_types = array( 'atf_form', 'atf_entry', 'atf_theme' );

	// A direct query rather than `get_posts()` in a loop: uninstall runs once, on a
	// table that may hold a hundred thousand entries, and paging through them with
	// `wp_delete_post()` would time out long before it finished. Post meta and any
	// comments on an entry go with them.
	$atf_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( %s, %s, %s )",
			$atf_types[0],
			$atf_types[1],
			$atf_types[2]
		)
	);

	if ( $atf_ids ) {
		foreach ( array_chunk( $atf_ids, 500 ) as $atf_chunk ) {
			$atf_placeholders = implode( ', ', array_fill( 0, count( $atf_chunk ), '%d' ) );

			// Attachments uploaded through a form are parented to their entry, so
			// they are deleted properly -- files and all -- rather than being
			// orphaned in the uploads directory.
			foreach ( $atf_chunk as $atf_post_id ) {
				$atf_attachments = get_children(
					array(
						'post_parent' => $atf_post_id,
						'post_type'   => 'attachment',
						'fields'      => 'ids',
					)
				);

				foreach ( $atf_attachments as $atf_attachment_id ) {
					wp_delete_attachment( $atf_attachment_id, true );
				}
			}

			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->postmeta} WHERE post_id IN ( {$atf_placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders are generated above and every value is passed through prepare().
					...$atf_chunk
				)
			);

			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->comments} WHERE comment_post_ID IN ( {$atf_placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
					...$atf_chunk
				)
			);

			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->posts} WHERE ID IN ( {$atf_placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
					...$atf_chunk
				)
			);
		}
	}

	// The rate-limiting transients, which are the only option rows this plugin
	// writes outside post meta.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_atf_rl_%' OR option_name LIKE '_transient_timeout_atf_rl_%'" );

	// The uploads directory, once every file that was in it has been deleted above.
	$atf_uploads = wp_upload_dir();
	$atf_dir     = trailingslashit( $atf_uploads['basedir'] ) . 'allterrain-forms';

	if ( is_dir( $atf_dir ) ) {
		$atf_files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $atf_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $atf_files as $atf_file ) {
			if ( $atf_file->isDir() ) {
				@rmdir( $atf_file->getRealPath() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best effort during uninstall; a permissions failure must not fatal the deletion.
				continue;
			}

			wp_delete_file( $atf_file->getRealPath() );
		}

		@rmdir( $atf_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- As above.
	}
}

if ( is_multisite() ) {
	// Every site, in one list. `'number' => 0` lifts the default cap of 100;
	// the ids are a handful of integers each, so even a network of tens of
	// thousands of sites fits comfortably in memory -- and a network larger
	// than that is not deleting plugins through this screen anyway.
	$atf_site_ids = get_sites(
		array(
			'number' => 0,
			'fields' => 'ids',
		)
	);

	foreach ( $atf_site_ids as $atf_site_id ) {
		// `switch_to_blog()` re-points `$wpdb`'s table properties, the roles
		// store and the uploads path at the switched site, which is exactly
		// the set of things `atf_uninstall_site()` reads.
		switch_to_blog( $atf_site_id );
		atf_uninstall_site();
		restore_current_blog();
	}
} else {
	atf_uninstall_site();
}

// Once, at the end, rather than per site: the object cache is shared across
// the network, and flushing it inside the loop would just flush it N times.
if ( defined( 'ATF_REMOVE_ALL_DATA' ) && ATF_REMOVE_ALL_DATA ) {
	wp_cache_flush();
}

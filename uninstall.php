<?php
/**
 * Uninstall.
 *
 * Runs only when the plugin is *deleted*, never when it is merely deactivated —
 * which is why the capabilities removed here are deliberately left alone by
 * `alltfo_deactivate()`. A site that deactivates to debug something and reactivates
 * a minute later must not find every editor's form permissions gone.
 *
 * **Entries are not deleted by default.** They are somebody's submissions —
 * enquiries, applications, orders — and a plugin deletion is not consent to
 * destroy them. Deleting the plugin leaves the data in the database where it can
 * be exported or recovered; a site that genuinely wants it gone defines
 * `ALLTFO_REMOVE_ALL_DATA` and gets exactly that.
 *
 * On multisite the plugin's footprint is per site — capabilities, posts,
 * transients, uploads — so a network deletion walks every site and cleans each
 * one, with the same "entries survive unless `ALLTFO_REMOVE_ALL_DATA`" promise
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
function alltfo_uninstall_site() {
	/*
	 * The capabilities this plugin granted.
	 *
	 * Listed here rather than read from the plugin's own code, because the
	 * plugin's files are not loaded during uninstall and requiring them would
	 * run its bootstrap in a context it was never written for.
	 */
	$alltfo_caps = array(
		'alltfo_edit_forms',
		'alltfo_read_entries',
		'alltfo_delete_entries',
		'alltfo_manage_settings',
		'edit_alltfo_forms',
		'edit_others_alltfo_forms',
		'publish_alltfo_forms',
		'read_private_alltfo_forms',
		'delete_alltfo_forms',
		'delete_others_alltfo_forms',
		'edit_alltfo_entries',
		'edit_others_alltfo_entries',
		'publish_alltfo_entries',
		'read_private_alltfo_entries',
		'delete_alltfo_entries',
		'delete_others_alltfo_entries',
	);

	foreach ( wp_roles()->role_objects as $alltfo_role ) {
		foreach ( $alltfo_caps as $alltfo_cap ) {
			$alltfo_role->remove_cap( $alltfo_cap );
		}
	}

	wp_clear_scheduled_hook( 'alltfo_apply_retention' );

	// Everything below destroys data, and only on an explicit opt-in.
	if ( ! defined( 'ALLTFO_REMOVE_ALL_DATA' ) || ! ALLTFO_REMOVE_ALL_DATA ) {
		return;
	}

	global $wpdb;

	$alltfo_types = array( 'alltfo_form', 'alltfo_entry', 'alltfo_theme' );

	// A direct query rather than `get_posts()` in a loop: uninstall runs once, on a
	// table that may hold a hundred thousand entries, and paging through them with
	// `wp_delete_post()` would time out long before it finished. Post meta and any
	// comments on an entry go with them.
	$alltfo_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( %s, %s, %s )",
			$alltfo_types[0],
			$alltfo_types[1],
			$alltfo_types[2]
		)
	);

	if ( $alltfo_ids ) {
		foreach ( array_chunk( $alltfo_ids, 500 ) as $alltfo_chunk ) {
			$alltfo_placeholders = implode( ', ', array_fill( 0, count( $alltfo_chunk ), '%d' ) );

			// Attachments uploaded through a form are parented to their entry, so
			// they are deleted properly -- files and all -- rather than being
			// orphaned in the uploads directory.
			foreach ( $alltfo_chunk as $alltfo_post_id ) {
				$alltfo_attachments = get_children(
					array(
						'post_parent' => $alltfo_post_id,
						'post_type'   => 'attachment',
						'fields'      => 'ids',
					)
				);

				foreach ( $alltfo_attachments as $alltfo_attachment_id ) {
					wp_delete_attachment( $alltfo_attachment_id, true );
				}
			}

			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->postmeta} WHERE post_id IN ( {$alltfo_placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders are generated above and every value is passed through prepare().
					...$alltfo_chunk
				)
			);

			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->comments} WHERE comment_post_ID IN ( {$alltfo_placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
					...$alltfo_chunk
				)
			);

			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->posts} WHERE ID IN ( {$alltfo_placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
					...$alltfo_chunk
				)
			);
		}
	}

	// The rate-limiting transients, which are the only option rows this plugin
	// writes outside post meta.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_alltfo_rl_%' OR option_name LIKE '_transient_timeout_alltfo_rl_%'" );

	// The uploads directory, once every file that was in it has been deleted above.
	$alltfo_uploads = wp_upload_dir();
	$alltfo_dir     = trailingslashit( $alltfo_uploads['basedir'] ) . 'allterrain-forms';

	if ( is_dir( $alltfo_dir ) ) {
		$alltfo_files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $alltfo_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $alltfo_files as $alltfo_file ) {
			if ( $alltfo_file->isDir() ) {
				@rmdir( $alltfo_file->getRealPath() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best effort during uninstall; a permissions failure must not fatal the deletion.
				continue;
			}

			wp_delete_file( $alltfo_file->getRealPath() );
		}

		@rmdir( $alltfo_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- As above.
	}
}

if ( is_multisite() ) {
	// Every site, in one list. `'number' => 0` lifts the default cap of 100;
	// the ids are a handful of integers each, so even a network of tens of
	// thousands of sites fits comfortably in memory -- and a network larger
	// than that is not deleting plugins through this screen anyway.
	$alltfo_site_ids = get_sites(
		array(
			'number' => 0,
			'fields' => 'ids',
		)
	);

	foreach ( $alltfo_site_ids as $alltfo_site_id ) {
		// `switch_to_blog()` re-points `$wpdb`'s table properties, the roles
		// store and the uploads path at the switched site, which is exactly
		// the set of things `alltfo_uninstall_site()` reads.
		switch_to_blog( $alltfo_site_id );
		alltfo_uninstall_site();
		restore_current_blog();
	}
} else {
	alltfo_uninstall_site();
}

// Once, at the end, rather than per site: the object cache is shared across
// the network, and flushing it inside the loop would just flush it N times.
if ( defined( 'ALLTFO_REMOVE_ALL_DATA' ) && ALLTFO_REMOVE_ALL_DATA ) {
	wp_cache_flush();
}

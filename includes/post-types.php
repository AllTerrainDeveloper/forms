<?php
/**
 * Forms, entries and themes as posts.
 *
 * Nothing here lives in a bespoke table. A form is a post, an entry is a post, a
 * saved theme is a post, and an entry note is an ordinary comment on the entry.
 * That is not nostalgia -- it is what makes `current_user_can()`, the REST API,
 * revisions, search, the trash, the privacy exporter and every plugin that hooks
 * `save_post` work on this data with no integration code at all.
 *
 * The one thing it costs is that entries are rows in `wp_posts` alongside
 * content, and a site taking a hundred thousand submissions will feel that. The
 * mitigation is that entries are `exclude_from_search`, not publicly queryable,
 * and always fetched through `atf_query_entries()`, which filters on an indexed
 * meta key. If a site ever outgrows this, the swap is behind that one function.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'atf_register_post_types', 5 );
add_action( 'init', 'atf_register_post_statuses', 5 );
add_action( 'init', 'atf_register_meta', 6 );

/**
 * Registers the three post types.
 *
 * On `init` at 5, ahead of the field-type and theme registries at 10, so that
 * anything reading a post type during its own registration finds it there.
 *
 * @since 0.1.0
 *
 * @return void
 */
function atf_register_post_types() {
	register_post_type(
		ATF_FORM_TYPE,
		array(
			'labels'              => array(
				'name'          => __( 'Forms', 'allterrain-forms' ),
				'singular_name' => __( 'Form', 'allterrain-forms' ),
				'add_new_item'  => __( 'Add New Form', 'allterrain-forms' ),
				'edit_item'     => __( 'Edit Form', 'allterrain-forms' ),
				'search_items'  => __( 'Search Forms', 'allterrain-forms' ),
				'not_found'     => __( 'No forms yet.', 'allterrain-forms' ),
			),
			'public'              => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_rest'        => true,
			'rest_base'           => 'atf-forms',
			'supports'            => array( 'title', 'revisions', 'author' ),
			'capability_type'     => array( 'atf_form', 'atf_forms' ),
			'map_meta_cap'        => true,
			'exclude_from_search' => true,
			'has_archive'         => false,
			'rewrite'             => false,
			'delete_with_user'    => false,
		)
	);

	register_post_type(
		ATF_ENTRY_TYPE,
		array(
			'labels'              => array(
				'name'          => __( 'Entries', 'allterrain-forms' ),
				'singular_name' => __( 'Entry', 'allterrain-forms' ),
				'edit_item'     => __( 'View Entry', 'allterrain-forms' ),
				'search_items'  => __( 'Search Entries', 'allterrain-forms' ),
				'not_found'     => __( 'No entries yet.', 'allterrain-forms' ),
			),
			'public'              => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			// Not `show_in_rest`. An entry holds whatever the form asked for --
			// names, addresses, answers to survey questions -- and core's
			// generic `/wp/v2/` handler would expose all of it to anyone who can
			// read a post. Entries are served only by this plugin's own routes,
			// which check `atf_read_entries` and run every value back through
			// the field type that produced it.
			'show_in_rest'        => false,
			// Comments are on so that entry notes are ordinary comments: the
			// Comments screen moderates them, `current_user_can( 'moderate_comments' )`
			// governs them, and nothing here has to reimplement a thread.
			'supports'            => array( 'title', 'comments', 'author' ),
			'capability_type'     => array( 'atf_entry', 'atf_entries' ),
			'map_meta_cap'        => true,
			'exclude_from_search' => true,
			'has_archive'         => false,
			'rewrite'             => false,
			'delete_with_user'    => false,
		)
	);

	register_post_type(
		ATF_THEME_TYPE,
		array(
			'labels'              => array(
				'name'          => __( 'Form Themes', 'allterrain-forms' ),
				'singular_name' => __( 'Form Theme', 'allterrain-forms' ),
			),
			'public'              => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_rest'        => true,
			'rest_base'           => 'atf-themes',
			'supports'            => array( 'title', 'revisions' ),
			'capability_type'     => array( 'atf_form', 'atf_forms' ),
			'map_meta_cap'        => true,
			'exclude_from_search' => true,
			'has_archive'         => false,
			'rewrite'             => false,
		)
	);
}

/**
 * Registers the entry statuses.
 *
 * Read/unread is a post status rather than a meta flag for one reason that pays
 * for itself immediately: `wp_count_posts()` returns the per-status counts the
 * entries table needs as a single cached query, where a meta flag would need a
 * `meta_query` per tab on every page load.
 *
 * `internal => true` keeps them out of the post-status dropdowns of a UI that
 * never shows entries anyway, and out of `get_post_stati( array( 'public' ) )`,
 * so no theme or feed can stumble into one.
 *
 * @since 0.1.0
 *
 * @return void
 */
function atf_register_post_statuses() {
	$statuses = array(
		ATF_STATUS_UNREAD  => array(
			/* translators: %s: number of entries. */
			'label_count' => _n_noop( 'Unread <span class="count">(%s)</span>', 'Unread <span class="count">(%s)</span>', 'allterrain-forms' ),
			'label'       => _x( 'Unread', 'entry status', 'allterrain-forms' ),
		),
		ATF_STATUS_READ    => array(
			/* translators: %s: number of entries. */
			'label_count' => _n_noop( 'Read <span class="count">(%s)</span>', 'Read <span class="count">(%s)</span>', 'allterrain-forms' ),
			'label'       => _x( 'Read', 'entry status', 'allterrain-forms' ),
		),
		ATF_STATUS_SPAM    => array(
			/* translators: %s: number of entries. */
			'label_count' => _n_noop( 'Spam <span class="count">(%s)</span>', 'Spam <span class="count">(%s)</span>', 'allterrain-forms' ),
			'label'       => _x( 'Spam', 'entry status', 'allterrain-forms' ),
		),
		ATF_STATUS_PARTIAL => array(
			/* translators: %s: number of entries. */
			'label_count' => _n_noop( 'Incomplete <span class="count">(%s)</span>', 'Incomplete <span class="count">(%s)</span>', 'allterrain-forms' ),
			'label'       => _x( 'Incomplete', 'entry status', 'allterrain-forms' ),
		),
	);

	foreach ( $statuses as $status => $args ) {
		register_post_status(
			$status,
			array(
				'label'                     => $args['label'],
				'label_count'               => $args['label_count'],
				'public'                    => false,
				'internal'                  => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => ATF_STATUS_SPAM !== $status && ATF_STATUS_PARTIAL !== $status,
				'show_in_admin_status_list' => true,
			)
		);
	}
}

/**
 * Registers the post meta.
 *
 * Every key is `single` and `auth_callback`-gated. None is `show_in_rest`:
 * schemas and submitted values are read and written through this plugin's own
 * routes, which know how to normalise a schema and how to run a value back
 * through the field type that produced it. Core's generic meta endpoint knows
 * neither, and exposing a raw `_atf_values` blob over `/wp/v2/` would hand every
 * submission to anyone who can read a post.
 *
 * @since 0.1.0
 *
 * @return void
 */
function atf_register_meta() {
	$forms_editable = static function () {
		return current_user_can( 'atf_edit_forms' );
	};

	$entries_readable = static function () {
		return current_user_can( 'atf_read_entries' );
	};

	register_post_meta(
		ATF_FORM_TYPE,
		ATF_META_SCHEMA,
		array(
			'type'          => 'string',
			'single'        => true,
			'default'       => '',
			'show_in_rest'  => false,
			'auth_callback' => $forms_editable,
		)
	);

	register_post_meta(
		ATF_FORM_TYPE,
		ATF_META_STATS,
		array(
			'type'          => 'string',
			'single'        => true,
			'default'       => '',
			'show_in_rest'  => false,
			'auth_callback' => $forms_editable,
		)
	);

	foreach ( array( ATF_META_VALUES, ATF_META_CONTEXT, ATF_META_RESUME ) as $key ) {
		register_post_meta(
			ATF_ENTRY_TYPE,
			$key,
			array(
				'type'          => 'string',
				'single'        => true,
				'default'       => '',
				'show_in_rest'  => false,
				'auth_callback' => $entries_readable,
			)
		);
	}

	register_post_meta(
		ATF_ENTRY_TYPE,
		ATF_META_FORM,
		array(
			'type'          => 'integer',
			'single'        => true,
			'default'       => 0,
			'show_in_rest'  => false,
			'auth_callback' => $entries_readable,
		)
	);

	register_post_meta(
		ATF_THEME_TYPE,
		ATF_META_TOKENS,
		array(
			'type'          => 'string',
			'single'        => true,
			'default'       => '',
			'show_in_rest'  => false,
			'auth_callback' => $forms_editable,
		)
	);
}

/**
 * The plugin's capabilities, mapped to the roles that should hold them.
 *
 * Four capabilities rather than the eight or so `map_meta_cap` would generate,
 * because the meaningful distinctions are few: who may build forms, who may read
 * what people submitted, who may delete it, and who may change site-wide
 * defaults. Reading entries is separated from editing forms deliberately -- a
 * shop that lets a contractor build a form should not thereby hand them every
 * name and address the old form collected.
 *
 * @since 0.1.0
 *
 * @return array<string, string[]> Role slug => capabilities.
 */
function atf_capability_map() {
	$editor = array( 'atf_edit_forms', 'atf_read_entries', 'atf_delete_entries' );

	$map = array(
		'administrator' => array_merge( $editor, array( 'atf_manage_settings' ) ),
		'editor'        => $editor,
	);

	/**
	 * Filters which roles hold which of the plugin's capabilities.
	 *
	 * Applied at activation and whenever `atf_add_capabilities()` runs. Changing
	 * it on an already-activated site has no effect until capabilities are
	 * re-applied, because roles are stored in the database, not computed per
	 * request -- call `atf_add_capabilities()` after filtering to apply it.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string[]> $map Role slug => list of capabilities.
	 */
	return apply_filters( 'atf_capability_map', $map );
}

/**
 * Grants the plugin's capabilities to the roles that should hold them.
 *
 * Also grants the primitive capabilities `map_meta_cap` needs to resolve
 * `edit_post` and friends for the three post types. Without them an editor can
 * hold `atf_edit_forms` and still be refused by `current_user_can( 'edit_post', $form_id )`,
 * because that check goes through the post type's own `edit_posts` capability
 * and never sees ours.
 *
 * @since 0.1.0
 *
 * @return void
 */
function atf_add_capabilities() {
	$primitives = array(
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

	foreach ( atf_capability_map() as $role_slug => $caps ) {
		$role = get_role( $role_slug );

		if ( ! $role ) {
			continue;
		}

		foreach ( array_merge( $caps, $primitives ) as $cap ) {
			$role->add_cap( $cap );
		}
	}
}

/**
 * Whether the current user may build and change forms.
 *
 * @since 0.1.0
 *
 * @return bool
 */
function atf_can_edit_forms() {
	return current_user_can( 'atf_edit_forms' );
}

/**
 * Whether the current user may read submitted entries.
 *
 * @since 0.1.0
 *
 * @param int $form_id Optional. Restrict the question to one form.
 * @return bool
 */
function atf_can_read_entries( $form_id = 0 ) {
	$can = current_user_can( 'atf_read_entries' );

	/**
	 * Filters whether the current user may read entries.
	 *
	 * The seam for per-form permissions: a site can let a department read only
	 * the entries of the forms it owns by returning false for every other id.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $can     Whether reading is allowed.
	 * @param int  $form_id Form the question is about, or 0 for "any".
	 */
	return (bool) apply_filters( 'atf_can_read_entries', $can, (int) $form_id );
}

/**
 * Every status an entry can hold.
 *
 * Exists because `'post_status' => 'any'` does **not** mean any status. It means
 * every status not registered with `exclude_from_search`, and all four of these
 * are — deliberately, so a theme, a feed or the site's search can never surface
 * somebody's submission.
 *
 * The consequence is easy to miss and expensive: a query written as `'any'`
 * matches no entry at all, finds nothing, throws nothing, and reports success.
 * That is how the retention sweep came to delete nothing on a site that had
 * asked it to delete something, and how a privacy export came back empty for a
 * person who had submitted a dozen forms. Neither failure is visible from the
 * outside — the only symptom is data that quietly outlives the policy that was
 * supposed to remove it.
 *
 * So: entry queries name their statuses, and they name them from here.
 *
 * @since 0.1.0
 *
 * @param bool $include_trash Whether to include the trash. Sweeps and exports
 *                            want it; a list of somebody's submissions does not.
 * @return string[] Status slugs.
 */
function atf_entry_statuses( $include_trash = true ) {
	$statuses = array( ATF_STATUS_UNREAD, ATF_STATUS_READ, ATF_STATUS_SPAM, ATF_STATUS_PARTIAL );

	if ( $include_trash ) {
		$statuses[] = 'trash';
	}

	/**
	 * Filters the statuses an entry query covers.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $statuses      Status slugs.
	 * @param bool     $include_trash Whether the trash was asked for.
	 */
	return apply_filters( 'atf_entry_statuses', $statuses, $include_trash );
}

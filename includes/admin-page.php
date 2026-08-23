<?php
/**
 * The admin page, for sites with no shell.
 *
 * The same three bundles the desktop windows use, mounted into a `wrap` under a
 * top-level menu. Not a second implementation of the builder -- the builder is
 * written against a root element and does not know or care whether that element
 * is inside an OpenStation window or a wp-admin page.
 *
 * What is lost without the shell is the part that needs the shell: a field can
 * still be dragged from the palette to the canvas, because the builder falls
 * back to its own pointer handling, but it cannot be dragged *out* of the window
 * onto anything else, because on a wp-admin page there is nothing else to drag
 * it to.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'alltfo_register_admin_pages' );

/**
 * Registers the menu.
 *
 * @since 0.1.0
 *
 * @return void
 */
function alltfo_register_admin_pages() {
	if ( ! alltfo_can_edit_forms() && ! alltfo_can_read_entries() ) {
		return;
	}

	$capability = alltfo_can_edit_forms() ? 'alltfo_edit_forms' : 'alltfo_read_entries';

	// With the desktop shell up, the dock tile in `dock.ts` is the way in and it
	// opens the native windows directly. Leaving the admin menu registered as
	// well would put a *second* tile in the dock -- the shell builds menu tiles
	// from WordPress's own `$menu` -- and its rows would open this page as an
	// iframe window, which then hands off to the native one. Two tiles, and a
	// window opened only to be superseded.
	//
	// The pages stay reachable by URL, registered under a `null` parent, so a
	// bookmark still resolves and the handoff still fires. Switch the shell off
	// and the ordinary menu comes back.
	if ( alltfo_shell_is_active() ) {
		alltfo_register_hidden_page( __( 'AllTerrain Forms', 'allterrain-forms' ), $capability, 'allterrain-forms', 'alltfo_render_builder_page' );
		alltfo_register_hidden_page( __( 'Entries', 'allterrain-forms' ), 'alltfo_read_entries', 'allterrain-forms-entries', 'alltfo_render_entries_page' );
		alltfo_register_hidden_page( __( 'Analytics', 'allterrain-forms' ), 'alltfo_read_entries', 'allterrain-forms-analytics', 'alltfo_render_analytics_page' );
		alltfo_register_hidden_page( __( 'Theme Studio', 'allterrain-forms' ), 'alltfo_edit_forms', 'allterrain-forms-themes', 'alltfo_render_theme_studio_page' );

		return;
	}

	add_menu_page(
		__( 'AllTerrain Forms', 'allterrain-forms' ),
		__( 'AllTerrain Forms', 'allterrain-forms' ),
		$capability,
		'allterrain-forms',
		'alltfo_render_builder_page',
		'dashicons-feedback',
		26
	);

	add_submenu_page(
		'allterrain-forms',
		__( 'Forms', 'allterrain-forms' ),
		__( 'All forms', 'allterrain-forms' ),
		$capability,
		'allterrain-forms',
		'alltfo_render_builder_page'
	);

	add_submenu_page(
		'allterrain-forms',
		__( 'Entries', 'allterrain-forms' ),
		__( 'Entries', 'allterrain-forms' ),
		'alltfo_read_entries',
		'allterrain-forms-entries',
		'alltfo_render_entries_page'
	);

	add_submenu_page(
		'allterrain-forms',
		__( 'Analytics', 'allterrain-forms' ),
		__( 'Analytics', 'allterrain-forms' ),
		'alltfo_read_entries',
		'allterrain-forms-analytics',
		'alltfo_render_analytics_page'
	);

	add_submenu_page(
		'allterrain-forms',
		__( 'Theme Studio', 'allterrain-forms' ),
		__( 'Themes', 'allterrain-forms' ),
		'alltfo_edit_forms',
		'allterrain-forms-themes',
		'alltfo_render_theme_studio_page'
	);
}

/**
 * Registers a page that has a URL but no menu entry.
 *
 * `add_submenu_page( null, … )` is WordPress's way of saying "reachable, but not
 * in the menu", and it has one sharp edge: `get_admin_page_title()` finds a
 * page's title by walking `$submenu` under its parent, and a page with no parent
 * is in nobody's `$submenu`. So `$title` stays null, and `admin-header.php` does
 * `strip_tags( $title )` — which on PHP 8.1 and up prints a deprecation notice at
 * the very top of the page, above everything the page renders. Inside a shell
 * window that notice is the first thing in the window.
 *
 * Setting the global on `load-{$hook}` is the fix, and the hook is the right one
 * because it is the last thing `admin.php` fires before including the header.
 *
 * @since 0.1.0
 *
 * @param string   $title      The page title.
 * @param string   $capability Capability required.
 * @param string   $slug       The page slug.
 * @param callable $callback   Renders the page.
 * @return void
 */
function alltfo_register_hidden_page( $title, $capability, $slug, $callback ) {
	$hook = add_submenu_page( null, $title, '', $capability, $slug, $callback );

	if ( ! $hook ) {
		return;
	}

	add_action(
		"load-{$hook}",
		static function () use ( $title ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Setting the screen title *is* the fix; see the docblock above.
			$GLOBALS['title'] = $title;
		}
	);
}

add_action( 'admin_enqueue_scripts', 'alltfo_enqueue_admin_page_assets' );

/**
 * Loads the right bundle for the page being viewed.
 *
 * Keyed off `$hook_suffix` so the builder bundle is not shipped to every admin
 * screen on the site.
 *
 * @since 0.1.0
 *
 * @param string $hook_suffix The current admin page.
 * @return void
 */
function alltfo_enqueue_admin_page_assets( $hook_suffix ) {
	$pages = array(
		'toplevel_page_allterrain-forms'        => 'allterrain-forms-builder',
		'forms_page_allterrain-forms-entries'   => 'allterrain-forms-entries',
		'forms_page_allterrain-forms-analytics' => 'allterrain-forms-analytics',
		'forms_page_allterrain-forms-themes'    => 'allterrain-forms-builder',
	);

	// The submenu hook suffix is derived from the parent menu's *title*, which
	// is translated -- so on a non-English site the keys above do not match.
	// Checking the page parameter as well covers that without hardcoding a
	// locale-dependent string.
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading which admin screen is being rendered; nothing is written.

	$handle = '';

	if ( isset( $pages[ $hook_suffix ] ) ) {
		$handle = $pages[ $hook_suffix ];
	} elseif ( 'allterrain-forms-entries' === $page ) {
		$handle = 'allterrain-forms-entries';
	} elseif ( 'allterrain-forms-analytics' === $page ) {
		$handle = 'allterrain-forms-analytics';
	} elseif ( in_array( $page, array( 'allterrain-forms', 'allterrain-forms-themes' ), true ) ) {
		$handle = 'allterrain-forms-builder';
	}

	if ( '' === $handle ) {
		return;
	}

	wp_enqueue_script( $handle );

	// The analytics stylesheet declares the builder's as a dependency, so
	// enqueueing it pulls in both; every other page uses the builder's alone.
	wp_enqueue_style( 'allterrain-forms-analytics' === $handle ? 'allterrain-forms-analytics' : 'allterrain-forms-builder' );
}

/**
 * The builder, on an admin page.
 *
 * @since 0.1.0
 *
 * @return void
 */
function alltfo_render_builder_page() {
	alltfo_render_admin_shell( 'alltfo_render_builder_template', __( 'AllTerrain Forms', 'allterrain-forms' ), 'allterrain-forms' );
}

/**
 * The entries table, on an admin page.
 *
 * @since 0.1.0
 *
 * @return void
 */
function alltfo_render_entries_page() {
	alltfo_render_admin_shell( 'alltfo_render_entries_template', __( 'AllTerrain Forms — Entries', 'allterrain-forms' ), 'allterrain-forms-entries' );
}

/**
 * The analytics report, on an admin page.
 *
 * @since 0.1.0
 *
 * @return void
 */
function alltfo_render_analytics_page() {
	alltfo_render_admin_shell( 'alltfo_render_analytics_template', __( 'AllTerrain Forms — Analytics', 'allterrain-forms' ), 'allterrain-forms-analytics' );
}

/**
 * The Theme Studio, on an admin page.
 *
 * @since 0.1.0
 *
 * @return void
 */
function alltfo_render_theme_studio_page() {
	alltfo_render_admin_shell( 'alltfo_render_theme_studio_template', __( 'AllTerrain Forms — Theme Studio', 'allterrain-forms' ), 'allterrain-forms-themes' );
}

/**
 * Wraps one of the window templates in an admin page.
 *
 * The heading is dropped when the page is being rendered inside a shell window
 * iframe, because the window's own title bar already says what this is and two
 * titles stacked on top of each other looks like a mistake.
 *
 * @since 0.1.0
 *
 * @param callable $template  The window template callback.
 * @param string   $title     The page heading.
 * @param string   $window_id The native window that supersedes this page when
 *                            the desktop shell is up. Empty means this page is
 *                            always the experience.
 * @return void
 */
function alltfo_render_admin_shell( $template, $title, $window_id = '' ) {
	echo '<div class="wrap atf-admin">';

	if ( ! alltfo_shell_is_chromeless() ) {
		printf( '<h1 class="wp-heading-inline">%s</h1>', esc_html( $title ) );
	}

	// No shell on the site at all: the tool does not render. These surfaces are
	// OpenStation desktop apps -- the `Requires Plugins` header enforces that on
	// WordPress, and this covers the installs it cannot reach (an older
	// WordPress, a force-removed shell). A fork that strips the header gets the
	// same answer, stated politely, rather than a quietly lesser builder that
	// misrepresents what this plugin is. Import stays available -- it has no
	// `$window_id` -- and published forms keep rendering for visitors.
	if ( '' !== $window_id && ! alltfo_shell_has( 'register_window' ) ) {
		printf(
			'<div class="atf-admin__pointer"><p>%1$s</p><p><a class="button button-primary" href="%2$s">%3$s</a></p></div>',
			esc_html__( 'This tool is an OpenStation desktop app, and OpenStation is not running on this site. Published forms keep working for your visitors in the meantime.', 'allterrain-forms' ),
			esc_url( admin_url( 'plugin-install.php?s=openstation&tab=search&type=term' ) ),
			esc_html__( 'Install OpenStation', 'allterrain-forms' )
		);

		echo '</div>';

		return;
	}

	// With the desktop shell up, this surface already exists as a native window
	// and the admin page must not become a second one.
	//
	// Two ways it otherwise does. Rendered into the desktop document, the markup
	// mounts a second builder beside the window's -- two autosave timers, two
	// sets of REST calls, two instances able to overwrite each other on the same
	// form. Rendered *chromelessly*, the shell has opened this URL as its own
	// iframe window, so the desktop ends up with two windows showing the same
	// tool: the native one, and a taller empty-looking one behind it.
	//
	// The shell offers no way for a native window to claim an admin URL, so the
	// page hands off instead: it renders a pointer, and the script opens the
	// real window. Reaching the URL directly therefore lands you in the window
	// rather than in a copy of it.
	if ( alltfo_shell_is_active() && '' !== $window_id ) {
		printf(
			'<div class="atf-admin__pointer" data-atf-handoff="%1$s"><p>%2$s</p>'
			. '<p><button type="button" class="button button-primary" data-atf-open-window="%1$s">%3$s</button></p></div>',
			esc_attr( $window_id ),
			esc_html__( 'This opens as a desktop window, where fields can be dragged between forms and files dropped straight onto a field.', 'allterrain-forms' ),
			esc_html__( 'Open the window', 'allterrain-forms' )
		);

		echo '</div>';

		return;
	}

	echo '<div class="atf-admin__mount">';

	call_user_func( $template );

	echo '</div></div>';
}

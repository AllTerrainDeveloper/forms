<?php
/**
 * The offer to import, made where somebody will see it.
 *
 * An importer nobody finds is an importer nobody uses, and the moment it is
 * worth the most is the one nobody is looking for it: just after activating
 * this plugin, on a site that already has fifteen forms in another one. So the
 * plugin looks for them itself and says so, once, with a button that does the
 * whole job.
 *
 * Three rules keep that from becoming the kind of notice people install other
 * plugins to hide:
 *
 * 1. It appears only where it is relevant — this plugin's own screens and the
 *    Plugins screen, which is where an activation lands.
 * 2. "Not now" is remembered per user, for good.
 * 3. It stops offering the moment anything has been imported. From then on the
 *    Import page is a place you go to, not a thing that asks.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * User meta set when somebody answers "Not now".
 *
 * Per user rather than per site: one administrator dismissing it must not
 * decide for the next, who may be the person who actually built those forms.
 */
define( 'ALLTFO_IMPORT_NOTICE_META', 'alltfo_import_notice_dismissed' );

/**
 * Option set the first time anything is imported, from anywhere.
 *
 * The notice's job is to introduce a feature, and it is done the moment the
 * feature has been used once. Somebody with more to bring over knows where the
 * Import page is by then, because they have just been on it.
 */
define( 'ALLTFO_IMPORTED_OPTION', 'alltfo_has_imported' );

/**
 * Remembers that this site has imported something.
 *
 * @since 0.2.0
 *
 * @return void
 */
function alltfo_remember_import() {
	update_option( ALLTFO_IMPORTED_OPTION, '1', false );
}
add_action( 'alltfo_form_imported', 'alltfo_remember_import' );

/**
 * Whether the current screen is one this notice may appear on.
 *
 * @since 0.2.0
 *
 * @return bool
 */
function alltfo_import_notice_screen() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	// Where an activation lands, and therefore where the offer is worth the
	// most: the forms are in the database and the old plugin was switched off
	// about four seconds ago.
	if ( $screen && 'plugins' === $screen->id ) {
		return true;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading which screen is being rendered; nothing is written.
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

	// Every Forms screen except the Import page itself, where a notice
	// pointing at the page you are on is just noise.
	return 0 === strpos( $page, 'allterrain-forms' ) && 'allterrain-forms-import' !== $page;
}

/**
 * Whether to offer the import.
 *
 * Kept apart from the markup so the decision can be tested without capturing
 * output, and because every clause in it is one somebody could reasonably get
 * wrong later.
 *
 * @since 0.2.0
 *
 * @return bool
 */
function alltfo_should_show_import_notice() {
	if ( ! alltfo_can_edit_forms() ) {
		return false;
	}

	if ( get_option( ALLTFO_IMPORTED_OPTION ) ) {
		return false;
	}

	if ( get_user_meta( get_current_user_id(), ALLTFO_IMPORT_NOTICE_META, true ) ) {
		return false;
	}

	/**
	 * Filters whether the import offer is shown at all.
	 *
	 * A site that would rather introduce the importer its own way turns this
	 * off once, rather than teaching every administrator to dismiss it.
	 *
	 * @since 0.2.0
	 *
	 * @param bool $show Whether to show it.
	 */
	if ( ! apply_filters( 'alltfo_show_import_notice', true ) ) {
		return false;
	}

	return alltfo_importable_count() > 0;
}

/**
 * Renders the offer.
 *
 * @since 0.2.0
 *
 * @return void
 */
function alltfo_render_import_notice() {
	if ( ! alltfo_import_notice_screen() || ! alltfo_should_show_import_notice() ) {
		return;
	}

	$total   = alltfo_importable_count();
	$sources = alltfo_importable_forms();

	// One source names itself; several are counted out, because "4 forms in
	// other plugins" leaves somebody wondering which, and the answer is the
	// reason they would click.
	if ( 1 === count( $sources ) ) {
		$source = reset( $sources );

		$headline = sprintf(
			/* translators: 1: number of forms found, 2: the plugin they are in. */
			_n(
				'AllTerrain Forms found %1$d form in %2$s.',
				'AllTerrain Forms found %1$d forms in %2$s.',
				$total,
				'allterrain-forms'
			),
			$total,
			$source['label']
		);

		$detail = __( 'Each one is copied with its fields, notification emails and thank-you message. The originals are never changed.', 'allterrain-forms' );
	} else {
		$counts = array();

		foreach ( $sources as $source ) {
			$counts[] = sprintf(
				/* translators: 1: number of forms, 2: name of the plugin they are in. */
				__( '%1$d from %2$s', 'allterrain-forms' ),
				(int) $source['count'],
				$source['label']
			);
		}

		$headline = sprintf(
			/* translators: %d: how many forms were found across every other plugin. */
			_n(
				'AllTerrain Forms found %d form in another plugin.',
				'AllTerrain Forms found %d forms in other plugins.',
				$total,
				'allterrain-forms'
			),
			$total
		);

		$detail = sprintf(
			/* translators: %s: a list like "3 from Contact Form 7 and 1 from Gravity Forms". */
			__( '%s. Each one is copied with its fields, notification emails and thank-you message. The originals are never changed.', 'allterrain-forms' ),
			wp_sprintf( '%l', $counts )
		);
	}

	printf(
		'<div class="notice notice-info"><p><strong>%1$s</strong></p><p>%2$s</p><p>%3$s%4$s%5$s</p></div>',
		esc_html( $headline ),
		esc_html( $detail ),
		// The whole job, in one button.
		sprintf(
			'<form method="post" action="%1$s" style="display: inline;">
				<input type="hidden" name="action" value="alltfo_import_form" />
				<input type="hidden" name="importer" value="all" />
				<input type="hidden" name="source" value="" />
				%2$s
				<button type="submit" class="button button-primary">%3$s</button>
			</form> ',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'alltfo-import', '_alltfo_nonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() builds escaped markup.
			esc_html(
				sprintf(
					/* translators: %d: how many forms would be imported. */
					_n( 'Import %d form', 'Import all %d forms', $total, 'allterrain-forms' ),
					$total
				)
			)
		),
		sprintf(
			'<a class="button" href="%1$s">%2$s</a> ',
			esc_url( admin_url( 'admin.php?page=allterrain-forms-import' ) ),
			esc_html__( 'Choose which', 'allterrain-forms' )
		),
		// A real answer, remembered -- not the X in the corner, which forgets
		// the moment the page reloads.
		sprintf(
			'<form method="post" action="%1$s" style="display: inline;">
				<input type="hidden" name="action" value="alltfo_dismiss_import_notice" />
				%2$s
				<button type="submit" class="button-link">%3$s</button>
			</form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'alltfo-dismiss-import', '_alltfo_nonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() builds escaped markup.
			esc_html__( 'Not now', 'allterrain-forms' )
		)
	);
}
add_action( 'admin_notices', 'alltfo_render_import_notice' );

/**
 * Remembers that this user does not want to be asked again.
 *
 * @since 0.2.0
 *
 * @return void
 */
function alltfo_handle_dismiss_import_notice() {
	check_admin_referer( 'alltfo-dismiss-import', '_alltfo_nonce' );

	update_user_meta( get_current_user_id(), ALLTFO_IMPORT_NOTICE_META, '1' );

	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
	exit;
}
add_action( 'admin_post_alltfo_dismiss_import_notice', 'alltfo_handle_dismiss_import_notice' );

<?php
/**
 * Importers — forms brought in from other plugins.
 *
 * The single biggest barrier to switching form plugins is not features, it is
 * the fifteen forms somebody already built. An importer turns each of them into
 * an ordinary AllTerrain form: real fields, the notifications rewritten onto
 * merge tags, the thank-you message carried over. From that moment the form is
 * native — nothing here runs at render or submit time, and the source plugin's
 * data is never modified or deleted.
 *
 * Importers read the source plugin's *data*, not its API, wherever possible.
 * The moment somebody most wants to import is right after deactivating the old
 * plugin — exactly when its classes are gone and its posts and tables are not.
 *
 * Each importer is a small array of callbacks registered on the
 * `atf_importers` filter, so a third-party plugin can add its own source
 * without touching this file.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * The registered importers.
 *
 * @since 0.2.0
 *
 * @return array[] Importer id => {
 *     @type string   $label     Human name of the source plugin.
 *     @type callable $available Whether any of the source's data exists.
 *     @type callable $forms     Source form id => title, for the picker.
 *     @type callable $import    Imports one source form; returns the new
 *                               form id or a WP_Error.
 * }
 */
function atf_importers() {
	/**
	 * Filters the list of form importers.
	 *
	 * The built-in importers register through this same filter, exactly as a
	 * third-party source would.
	 *
	 * @since 0.2.0
	 *
	 * @param array[] $importers Importer id => definition.
	 */
	$importers = apply_filters( 'atf_importers', array() );

	$valid = array();

	foreach ( $importers as $id => $importer ) {
		$id = sanitize_key( $id );

		if ( '' === $id || ! isset( $importer['label'], $importer['available'], $importer['forms'], $importer['import'] ) ) {
			continue;
		}

		if ( ! is_callable( $importer['available'] ) || ! is_callable( $importer['forms'] ) || ! is_callable( $importer['import'] ) ) {
			continue;
		}

		$valid[ $id ] = $importer;
	}

	return $valid;
}

/**
 * The importers whose source data is actually present on this site.
 *
 * @since 0.2.0
 *
 * @return array[] Importer id => definition.
 */
function atf_available_importers() {
	return array_filter(
		atf_importers(),
		static function ( $importer ) {
			return (bool) call_user_func( $importer['available'] );
		}
	);
}

/**
 * Imports one form from a source plugin.
 *
 * @since 0.2.0
 *
 * @param string $importer_id Which importer.
 * @param string $source_id   The source plugin's id for the form.
 * @return int|WP_Error The new form's id.
 */
function atf_import_source_form( $importer_id, $source_id ) {
	if ( ! atf_can_edit_forms() ) {
		return new WP_Error( 'atf_forbidden', __( 'You cannot create forms.', 'allterrain-forms' ), array( 'status' => 403 ) );
	}

	$importers   = atf_importers();
	$importer_id = sanitize_key( $importer_id );

	if ( ! isset( $importers[ $importer_id ] ) ) {
		return new WP_Error( 'atf_unknown_importer', __( 'That importer does not exist.', 'allterrain-forms' ) );
	}

	$form_id = call_user_func( $importers[ $importer_id ]['import'], $source_id );

	if ( ! is_wp_error( $form_id ) ) {
		/**
		 * Fires after a form is imported from another plugin.
		 *
		 * @since 0.2.0
		 *
		 * @param int    $form_id     The new form.
		 * @param string $importer_id The importer that produced it.
		 * @param string $source_id   The source plugin's id for the form.
		 */
		do_action( 'atf_form_imported', $form_id, $importer_id, (string) $source_id );
	}

	return $form_id;
}

/**
 * Creates a form post around an imported schema.
 *
 * The shared tail of every importer: insert the post, normalise and save the
 * schema, hand the id back. The schema passes through a filter first, so a
 * site can correct a mapping this code got wrong for its data without forking
 * the importer.
 *
 * @since 0.2.0
 *
 * @param string $title       The form's title.
 * @param array  $schema      The converted schema, pre-normalisation.
 * @param string $importer_id Which importer produced it.
 * @param string $source_id   The source plugin's id for the form.
 * @return int|WP_Error The new form's id.
 */
function atf_create_imported_form( $title, $schema, $importer_id, $source_id ) {
	/**
	 * Filters an imported schema before it is saved.
	 *
	 * @since 0.2.0
	 *
	 * @param array  $schema      The converted schema, pre-normalisation.
	 * @param string $importer_id The importer that produced it.
	 * @param string $source_id   The source plugin's id for the form.
	 */
	$schema = apply_filters( 'atf_imported_schema', $schema, $importer_id, (string) $source_id );

	$form_id = wp_insert_post(
		array(
			'post_type'   => ATF_FORM_TYPE,
			'post_title'  => sanitize_text_field( '' !== $title ? $title : __( 'Imported form', 'allterrain-forms' ) ),
			'post_status' => 'publish',
			'post_author' => get_current_user_id(),
		),
		true
	);

	if ( is_wp_error( $form_id ) ) {
		return $form_id;
	}

	atf_save_form_schema( $form_id, atf_normalize_schema( $schema ) );

	return $form_id;
}

/**
 * How many forms each available importer can see, keyed by importer id.
 *
 * Cached, because this is asked on ordinary admin page loads to decide whether
 * the import is worth offering at all -- and answering it costs a query per
 * source, plus a `SHOW TABLES` for the one that keeps its forms in tables of
 * its own. Twelve hours means nobody pays for it twice in a sitting, and the
 * answer is forgotten the moment anything could have changed it: a form
 * imported, or a plugin switched on or off.
 *
 * @since 0.2.0
 *
 * @return array[] Importer id => { label, count }. A source with no forms is
 *                 left out, so an empty array means there is nothing to offer.
 */
function atf_importable_forms() {
	$cached = get_transient( 'atf_importable' );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$found = array();

	foreach ( atf_importers() as $id => $importer ) {
		if ( ! call_user_func( $importer['available'] ) ) {
			continue;
		}

		$count = count( (array) call_user_func( $importer['forms'] ) );

		if ( $count > 0 ) {
			$found[ $id ] = array(
				'label' => $importer['label'],
				'count' => $count,
			);
		}
	}

	set_transient( 'atf_importable', $found, 12 * HOUR_IN_SECONDS );

	return $found;
}

/**
 * Forgets what the last survey found.
 *
 * @since 0.2.0
 *
 * @return void
 */
function atf_forget_importable_forms() {
	delete_transient( 'atf_importable' );
}
add_action( 'atf_form_imported', 'atf_forget_importable_forms' );
add_action( 'activated_plugin', 'atf_forget_importable_forms' );
add_action( 'deactivated_plugin', 'atf_forget_importable_forms' );

/**
 * How many forms could be imported, across every source.
 *
 * @since 0.2.0
 *
 * @return int
 */
function atf_importable_count() {
	$total = 0;

	foreach ( atf_importable_forms() as $source ) {
		$total += (int) $source['count'];
	}

	return $total;
}

/**
 * Imports every form every available source can see.
 *
 * The one-click path. The importer ids are taken once, up front, because each
 * successful import forgets the survey -- re-reading it inside the loop would
 * re-run every source's query on every form.
 *
 * @since 0.2.0
 *
 * @return array {
 *     @type int $imported How many forms were created.
 *     @type int $failed   How many could not be.
 * }
 */
function atf_import_all() {
	$importers = atf_importers();
	$imported  = 0;
	$failed    = 0;

	foreach ( array_keys( atf_importable_forms() ) as $importer_id ) {
		if ( ! isset( $importers[ $importer_id ] ) ) {
			continue;
		}

		foreach ( array_keys( (array) call_user_func( $importers[ $importer_id ]['forms'] ) ) as $source_id ) {
			if ( is_wp_error( atf_import_source_form( $importer_id, (string) $source_id ) ) ) {
				++$failed;
			} else {
				++$imported;
			}
		}
	}

	return array(
		'imported' => $imported,
		'failed'   => $failed,
	);
}

/**
 * Registers the Import page.
 *
 * Priority 20, after the Forms menu itself exists. With the desktop shell up
 * the page keeps a URL but no menu entry, matching every other Forms page.
 *
 * @since 0.2.0
 *
 * @return void
 */
function atf_register_import_page() {
	if ( ! atf_can_edit_forms() ) {
		return;
	}

	if ( atf_shell_is_active() ) {
		atf_register_hidden_page( __( 'Import forms', 'allterrain-forms' ), 'atf_edit_forms', 'allterrain-forms-import', 'atf_render_import_page' );

		return;
	}

	add_submenu_page(
		'allterrain-forms',
		__( 'Import forms', 'allterrain-forms' ),
		__( 'Import', 'allterrain-forms' ),
		'atf_edit_forms',
		'allterrain-forms-import',
		'atf_render_import_page'
	);
}
add_action( 'admin_menu', 'atf_register_import_page', 20 );

/**
 * Renders the Import page.
 *
 * Server-rendered on purpose. Importing is a one-time migration, not a tool
 * anybody lives in: a plain page with real buttons asks nothing of the browser
 * and works identically inside a shell window and out.
 *
 * @since 0.2.0
 *
 * @return void
 */
function atf_render_import_page() {
	$available = atf_available_importers();

	echo '<div class="wrap atf-admin">';
	printf( '<h1 class="wp-heading-inline">%s</h1>', esc_html__( 'Import forms', 'allterrain-forms' ) );

	// The outcome of the redirect that brought us here. Display only — the
	// import itself was authorised by the nonce checked in the handler.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$imported = isset( $_GET['atf_imported'] ) ? absint( $_GET['atf_imported'] ) : 0;
	$failed   = isset( $_GET['atf_failed'] ) ? absint( $_GET['atf_failed'] ) : 0;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( $imported || $failed ) {
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s%3$s</p></div>',
			$failed ? 'warning' : 'success',
			esc_html(
				sprintf(
					/* translators: %d: number of forms imported. */
					_n( '%d form imported.', '%d forms imported.', $imported, 'allterrain-forms' ),
					$imported
				)
			),
			esc_html(
				$failed ? ' ' . sprintf(
					/* translators: %d: number of forms that could not be imported. */
					_n( '%d could not be imported.', '%d could not be imported.', $failed, 'allterrain-forms' ),
					$failed
				) : ''
			)
		);
	}

	if ( ! $available ) {
		printf(
			'<p>%s</p></div>',
			esc_html__( 'No importable forms were found. Importers look for data from Contact Form 7, WPForms and Gravity Forms — the data is read even when the source plugin has been deactivated.', 'allterrain-forms' )
		);

		return;
	}

	printf(
		'<p>%s</p>',
		esc_html__( 'Each import creates a new AllTerrain form. The original is never changed, so importing is safe to try and safe to repeat.', 'allterrain-forms' )
	);

	// The whole job in one button, above the per-source lists. Somebody
	// arriving here after switching plugins wants all of it; picking through
	// the lists is the exception, so it comes second.
	$total = atf_importable_count();

	if ( $total > 0 ) {
		printf(
			'<form method="post" action="%1$s" style="margin: 16px 0 24px;">
				<input type="hidden" name="action" value="atf_import_form" />
				<input type="hidden" name="importer" value="all" />
				<input type="hidden" name="source" value="" />
				%2$s
				<button type="submit" class="button button-primary button-hero">%3$s</button>
			</form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'atf-import', '_atf_nonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() builds escaped markup.
			esc_html(
				sprintf(
					/* translators: %d: total number of forms found across every source. */
					_n( 'Import all %d form', 'Import all %d forms', $total, 'allterrain-forms' ),
					$total
				)
			)
		);
	}

	foreach ( $available as $id => $importer ) {
		$forms = call_user_func( $importer['forms'] );

		printf( '<h2>%s</h2>', esc_html( $importer['label'] ) );

		if ( ! $forms ) {
			printf( '<p>%s</p>', esc_html__( 'No forms found.', 'allterrain-forms' ) );
			continue;
		}

		echo '<table class="widefat striped" style="max-width: 720px;"><tbody>';

		foreach ( $forms as $source_id => $title ) {
			printf(
				'<tr><td>%1$s</td><td style="width: 8em; text-align: end;">
					<form method="post" action="%2$s" style="margin: 0;">
						<input type="hidden" name="action" value="atf_import_form" />
						<input type="hidden" name="importer" value="%3$s" />
						<input type="hidden" name="source" value="%4$s" />
						%5$s
						<button type="submit" class="button">%6$s</button>
					</form>
				</td></tr>',
				esc_html( $title ),
				esc_url( admin_url( 'admin-post.php' ) ),
				esc_attr( $id ),
				esc_attr( (string) $source_id ),
				wp_nonce_field( 'atf-import', '_atf_nonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() builds escaped markup.
				esc_html__( 'Import', 'allterrain-forms' )
			);
		}

		echo '</tbody></table>';

		printf(
			'<form method="post" action="%1$s" style="margin: 12px 0 24px;">
				<input type="hidden" name="action" value="atf_import_form" />
				<input type="hidden" name="importer" value="%2$s" />
				<input type="hidden" name="source" value="" />
				%3$s
				<button type="submit" class="button button-primary">%4$s</button>
			</form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( $id ),
			wp_nonce_field( 'atf-import', '_atf_nonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() builds escaped markup.
			esc_html(
				sprintf(
					/* translators: %s: source plugin name. */
					__( 'Import all from %s', 'allterrain-forms' ),
					$importer['label']
				)
			)
		);
	}

	echo '</div>';
}

/**
 * Handles the Import page's POST.
 *
 * An empty `source` means "all of them" — the picker's per-form buttons and
 * its import-all button share this one handler and one nonce.
 *
 * @since 0.2.0
 *
 * @return void
 */
function atf_handle_import_post() {
	if ( ! atf_can_edit_forms() ) {
		wp_die( esc_html__( 'You cannot create forms.', 'allterrain-forms' ) );
	}

	check_admin_referer( 'atf-import', '_atf_nonce' );

	$importer_id = isset( $_POST['importer'] ) ? sanitize_key( wp_unslash( $_POST['importer'] ) ) : '';
	$source      = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
	$importers   = atf_importers();

	$imported = 0;
	$failed   = 0;

	// `all` is the one-click path: every form every source can see. Checked
	// against the registry first, so a third-party importer that calls itself
	// `all` keeps its own meaning rather than being shadowed by this.
	if ( 'all' === $importer_id && ! isset( $importers['all'] ) ) {
		$result   = atf_import_all();
		$imported = $result['imported'];
		$failed   = $result['failed'];
	} elseif ( isset( $importers[ $importer_id ] ) ) {
		$sources = '' !== $source
			? array( $source )
			: array_keys( call_user_func( $importers[ $importer_id ]['forms'] ) );

		foreach ( $sources as $source_id ) {
			$result = atf_import_source_form( $importer_id, (string) $source_id );

			if ( is_wp_error( $result ) ) {
				++$failed;
			} else {
				++$imported;
			}
		}
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'         => 'allterrain-forms-import',
				'atf_imported' => $imported,
				'atf_failed'   => $failed,
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}
add_action( 'admin_post_atf_import_form', 'atf_handle_import_post' );

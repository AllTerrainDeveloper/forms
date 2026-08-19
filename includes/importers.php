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
 *     @type string   $label          Human name of the source plugin.
 *     @type callable $available      Whether any of the source's data exists.
 *     @type callable $forms          Source form id => title, for the picker.
 *     @type callable $import         Imports one source form; returns the new
 *                                    form id or a WP_Error.
 *     @type callable $entries        Optional. How many stored submissions the
 *                                    source holds for one source form.
 *     @type callable $import_entries Optional. Imports a slice of them onto an
 *                                    AllTerrain form; returns { imported,
 *                                    skipped, done } or a WP_Error.
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

		// Entry migration is optional, and half of it is useless: an importer
		// that can count stored submissions but not import them would offer a
		// number with no button behind it, and one that can import without
		// counting cannot be asked how much work it has. Either both callbacks
		// are present and callable, or the importer simply does forms.
		foreach ( array( 'entries', 'import_entries' ) as $key ) {
			if ( isset( $importer[ $key ] ) && ! is_callable( $importer[ $key ] ) ) {
				unset( $importer['entries'], $importer['import_entries'] );
				break;
			}
		}

		if ( ! isset( $importer['entries'], $importer['import_entries'] ) ) {
			unset( $importer['entries'], $importer['import_entries'] );
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
 * @param array  $map         Optional. Source field name => new field id. Kept
 *                            on the form so its stored submissions can be read
 *                            later; without it they are just values under names
 *                            nothing here recognises.
 * @return int|WP_Error The new form's id.
 */
function atf_create_imported_form( $title, $schema, $importer_id, $source_id, $map = array() ) {
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

	update_post_meta(
		$form_id,
		ATF_META_IMPORT_SOURCE,
		array(
			'importer' => sanitize_key( $importer_id ),
			'source'   => (string) $source_id,
		)
	);

	if ( $map ) {
		update_post_meta( $form_id, ATF_META_IMPORT_MAP, array_map( 'strval', (array) $map ) );
	}

	return $form_id;
}

/**
 * What an imported form was imported from.
 *
 * @param int $form_id The form.
 * @return array|null { importer: string, source: string }, or null when the
 *                    form was not imported.
 */
function atf_form_import_source( $form_id ) {
	$source = get_post_meta( (int) $form_id, ATF_META_IMPORT_SOURCE, true );

	if ( ! is_array( $source ) || empty( $source['importer'] ) || ! isset( $source['source'] ) ) {
		return null;
	}

	return array(
		'importer' => (string) $source['importer'],
		'source'   => (string) $source['source'],
	);
}

/**
 * The source-field-name => field-id map recorded when a form was imported.
 *
 * @param int $form_id The form.
 * @return array Source field name => field id. Empty when unknown.
 */
function atf_form_import_map( $form_id ) {
	$map = get_post_meta( (int) $form_id, ATF_META_IMPORT_MAP, true );

	return is_array( $map ) ? $map : array();
}

/**
 * The forms that came from a source and could still have submissions to bring.
 *
 * Keyed by form id so the caller can offer each one by name.
 *
 * @return array[] Form id => { importer, source, label, count }.
 */
function atf_forms_with_importable_entries() {
	$importers = atf_importers();
	$forms     = get_posts(
		array(
			'post_type'      => ATF_FORM_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => ATF_META_IMPORT_SOURCE,
		)
	);

	$found = array();

	foreach ( $forms as $form_id ) {
		$source = atf_form_import_source( $form_id );

		if ( ! $source || ! isset( $importers[ $source['importer'] ] ) ) {
			continue;
		}

		$importer = $importers[ $source['importer'] ];

		if ( ! isset( $importer['entries'] ) || ! call_user_func( $importer['available'] ) ) {
			continue;
		}

		$count = (int) call_user_func( $importer['entries'], $source['source'], (int) $form_id );

		if ( $count > 0 ) {
			$found[ (int) $form_id ] = array(
				'importer' => $source['importer'],
				'source'   => $source['source'],
				'label'    => $importer['label'],
				'count'    => $count,
			);
		}
	}

	return $found;
}

/**
 * The key that identifies one imported entry against its source record.
 *
 * A plain string rather than the `{ importer, source }` array used on forms,
 * because this one is queried — `meta_value` matching an array means matching a
 * serialised blob, which works until the day a value inside it changes shape.
 *
 * @param string $importer_id Which importer.
 * @param string $record_id   The source plugin's id for the stored submission.
 * @return string
 */
function atf_entry_source_key( $importer_id, $record_id ) {
	return sanitize_key( $importer_id ) . ':' . (string) $record_id;
}

/**
 * The source keys already imported onto a form.
 *
 * Read once per pass and held in memory: asking per record would be a query per
 * record, and the answer cannot change underneath a single pass.
 *
 * @param int $form_id The form.
 * @return array Source key => true.
 */
function atf_imported_entry_keys( $form_id ) {
	global $wpdb;

	$keys = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN (
				SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %d
			)",
			ATF_META_ENTRY_SOURCE,
			ATF_META_FORM,
			(int) $form_id
		)
	);

	return array_fill_keys( (array) $keys, true );
}

/**
 * Stores one migrated submission as an entry.
 *
 * # Sanitised, deliberately not validated
 *
 * Values go through `atf_sanitize_submission()`, which coerces each one to the
 * shape its field type stores. They do **not** go through validation, and that
 * is the important half: validation enforces today's rules — required fields,
 * choice whitelists, bounds — against answers given years ago, under a form that
 * may since have lost the option somebody picked. A migration that dropped those
 * answers would be quietly deleting history it was asked to preserve. Anything
 * unsafe is removed by sanitising; anything merely *unexpected* is kept.
 *
 * # The date is the submission's, not today's
 *
 * `atf_store_entry()` stamps the current time and the current request's IP and
 * user agent, which is right for a real submission and wrong for every one of
 * these. Both are corrected afterwards rather than by duplicating that function,
 * so entry titles, value encoding and file parenting stay in one place.
 *
 * @param int   $form_id The form to store against.
 * @param array $args {
 *     The submission, as the source stored it.
 *
 *     @type array  $values       Source field name => stored value.
 *     @type string $importer     Importer id.
 *     @type string $record       The source plugin's id for this submission.
 *     @type int    $submitted_at Unix time of the original submission.
 *     @type bool   $spam         Whether the source marked it as spam.
 *     @type string $ip           Original remote address, if the source kept one.
 *     @type string $user_agent   Original user agent, if the source kept one.
 * }
 * @return int|WP_Error The entry id, or an error.
 */
function atf_import_entry( $form_id, array $args ) {
	$args = wp_parse_args(
		$args,
		array(
			'values'       => array(),
			'importer'     => '',
			'record'       => '',
			'submitted_at' => 0,
			'spam'         => false,
			'ip'           => '',
			'user_agent'   => '',
		)
	);

	$form_id = (int) $form_id;
	$schema  = atf_get_form_schema( $form_id );

	if ( ! $schema ) {
		return new WP_Error( 'atf_no_schema', __( 'That form has no schema.', 'allterrain-forms' ) );
	}

	$map = atf_form_import_map( $form_id );

	if ( ! $map ) {
		return new WP_Error(
			'atf_no_import_map',
			__( 'That form has no field map, so its stored submissions cannot be read. Import the form again to record one.', 'allterrain-forms' )
		);
	}

	// Source names to field ids. A source field the form no longer has — a tag
	// deleted before the migration, or one the importer had no equivalent for —
	// simply has nowhere to go.
	$raw = array();

	foreach ( (array) $args['values'] as $name => $value ) {
		if ( isset( $map[ $name ] ) ) {
			$raw[ $map[ $name ] ] = $value;
		}
	}

	$values = atf_sanitize_submission( $schema, $raw );

	$entry_id = atf_store_entry(
		$form_id,
		$schema,
		$values,
		array(
			'spam'   => (bool) $args['spam'],
			'reason' => $args['spam'] ? 'imported' : '',
		)
	);

	if ( is_wp_error( $entry_id ) ) {
		return $entry_id;
	}

	$submitted_at = (int) $args['submitted_at'];

	if ( $submitted_at > 0 ) {
		$gmt = gmdate( 'Y-m-d H:i:s', $submitted_at );

		wp_update_post(
			array(
				'ID'            => $entry_id,
				'post_date'     => get_date_from_gmt( $gmt ),
				'post_date_gmt' => $gmt,
			)
		);
	}

	$context = json_decode( (string) get_post_meta( $entry_id, ATF_META_CONTEXT, true ), true );
	$context = is_array( $context ) ? $context : array();

	$context['ip']        = (string) $args['ip'];
	$context['userAgent'] = (string) $args['user_agent'];
	$context['referrer']  = '';
	$context['userId']    = 0;
	$context['imported']  = sanitize_key( $args['importer'] );

	if ( $submitted_at > 0 ) {
		$context['submitted'] = gmdate( 'Y-m-d H:i:s', $submitted_at );
	}

	update_post_meta( $entry_id, ATF_META_CONTEXT, wp_slash( wp_json_encode( $context ) ) );
	update_post_meta( $entry_id, ATF_META_ENTRY_SOURCE, atf_entry_source_key( $args['importer'], $args['record'] ) );

	return $entry_id;
}

/**
 * Brings a slice of one source form's stored submissions across.
 *
 * Sliced rather than done in one pass because a form that has been live for
 * years is the normal case, not the exceptional one, and the request that
 * imports it is an ordinary admin POST with an ordinary time limit.
 *
 * @param int $form_id The AllTerrain form to import onto.
 * @param int $limit   How many to attempt in this pass.
 * @return array|WP_Error { imported, skipped, done, remaining }.
 */
function atf_import_form_entries( $form_id, $limit = 100 ) {
	if ( ! atf_can_edit_forms() ) {
		return new WP_Error( 'atf_forbidden', __( 'You cannot import submissions.', 'allterrain-forms' ), array( 'status' => 403 ) );
	}

	$form_id = (int) $form_id;
	$source  = atf_form_import_source( $form_id );

	if ( ! $source ) {
		return new WP_Error( 'atf_not_imported', __( 'That form was not imported, so it has no submissions to bring across.', 'allterrain-forms' ) );
	}

	$importers = atf_importers();

	if ( ! isset( $importers[ $source['importer'] ]['import_entries'] ) ) {
		return new WP_Error( 'atf_no_entry_import', __( 'That source cannot import stored submissions.', 'allterrain-forms' ) );
	}

	$result = call_user_func(
		$importers[ $source['importer'] ]['import_entries'],
		$source['source'],
		$form_id,
		max( 1, (int) $limit )
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$result = wp_parse_args(
		(array) $result,
		array(
			'imported'  => 0,
			'skipped'   => 0,
			'done'      => true,
			'remaining' => 0,
		)
	);

	/**
	 * Fires after a pass of entry importing.
	 *
	 * @param int   $form_id The form imported onto.
	 * @param array $result  Counts: imported, skipped, done, remaining.
	 * @param array $source  Where it came from: importer, source.
	 */
	do_action( 'atf_entries_imported', $form_id, $result, $source );

	return $result;
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

	// The outcome of an entry-import pass. Display only, same as above.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$entries      = isset( $_GET['atf_entries'] ) ? absint( $_GET['atf_entries'] ) : 0;
	$remaining    = isset( $_GET['atf_remaining'] ) ? absint( $_GET['atf_remaining'] ) : 0;
	$entry_error  = isset( $_GET['atf_entry_error'] ) ? sanitize_key( wp_unslash( $_GET['atf_entry_error'] ) ) : '';
	$showed_entry = isset( $_GET['atf_entries'] ) || '' !== $entry_error;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( '' !== $entry_error ) {
		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html__( 'Those submissions could not be brought across. Import the form again to record the field map, then try once more.', 'allterrain-forms' )
		);
	} elseif ( $showed_entry ) {
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s%3$s</p></div>',
			$remaining ? 'info' : 'success',
			esc_html(
				sprintf(
					/* translators: %d: number of submissions imported. */
					_n( '%d submission brought across.', '%d submissions brought across.', $entries, 'allterrain-forms' ),
					$entries
				)
			),
			esc_html(
				$remaining ? ' ' . sprintf(
					/* translators: %d: number still waiting. */
					_n( '%d still waiting — run it again.', '%d still waiting — run it again.', $remaining, 'allterrain-forms' ),
					$remaining
				) : ''
			)
		);
	}

	if ( ! $available ) {
		// Sources can vanish while their already-imported forms still have
		// submissions waiting -- deleting CF7 leaves every Flamingo message
		// exactly where it was -- so this section comes before the early return.
		atf_render_entry_import_section();

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

	// Forms already imported that still have submissions sitting in the old
	// plugin. Offered above the pickers, because somebody who has just imported
	// their forms is looking at an entries screen that says nothing yet, and
	// this is the answer to why.
	atf_render_entry_import_section();

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
 * Renders the "bring the submissions too" section of the Import page.
 *
 * Prints nothing at all when there is nothing to bring, so a site whose sources
 * never stored anything — a plain CF7 install with no Flamingo — sees the page
 * exactly as it was.
 *
 * @return void
 */
function atf_render_entry_import_section() {
	$forms = atf_forms_with_importable_entries();

	if ( ! $forms ) {
		return;
	}

	$total = 0;

	foreach ( $forms as $form ) {
		$total += (int) $form['count'];
	}

	printf( '<h2>%s</h2>', esc_html__( 'Stored submissions', 'allterrain-forms' ) );

	printf(
		'<p>%s</p>',
		esc_html(
			sprintf(
				/* translators: %d: number of stored submissions found. */
				_n(
					'%d stored submission is still held by the plugin it was submitted through. Bringing it across creates an entry with its original date; the original is left untouched.',
					'%d stored submissions are still held by the plugins they were submitted through. Bringing them across creates entries with their original dates; the originals are left untouched.',
					$total,
					'allterrain-forms'
				),
				$total
			)
		)
	);

	echo '<table class="widefat striped" style="max-width: 720px;"><tbody>';

	foreach ( $forms as $form_id => $form ) {
		printf(
			'<tr><td>%1$s<br /><span class="description">%2$s</span></td><td style="width: 12em; text-align: end;">
				<form method="post" action="%3$s" style="margin: 0;">
					<input type="hidden" name="action" value="atf_import_entries" />
					<input type="hidden" name="form" value="%4$d" />
					%5$s
					<button type="submit" class="button">%6$s</button>
				</form>
			</td></tr>',
			esc_html( get_the_title( $form_id ) ),
			esc_html(
				sprintf(
					/* translators: 1: source plugin name, 2: number of submissions. */
					__( 'from %1$s — %2$d waiting', 'allterrain-forms' ),
					$form['label'],
					(int) $form['count']
				)
			),
			esc_url( admin_url( 'admin-post.php' ) ),
			(int) $form_id,
			wp_nonce_field( 'atf-import', '_atf_nonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() builds escaped markup.
			esc_html__( 'Bring submissions', 'allterrain-forms' )
		);
	}

	echo '</tbody></table>';
}

/**
 * Handles the "bring the submissions too" POST.
 *
 * One pass per submit, and the page it returns to offers the next one. A form
 * with tens of thousands of stored messages therefore takes several clicks
 * rather than one request that times out halfway and leaves nobody sure how far
 * it got.
 *
 * @return void
 */
function atf_handle_import_entries_post() {
	if ( ! atf_can_edit_forms() ) {
		wp_die( esc_html__( 'You cannot import submissions.', 'allterrain-forms' ) );
	}

	check_admin_referer( 'atf-import', '_atf_nonce' );

	$form_id = isset( $_POST['form'] ) ? absint( wp_unslash( $_POST['form'] ) ) : 0;
	$result  = atf_import_form_entries( $form_id, 200 );

	$args = array( 'page' => 'allterrain-forms-import' );

	if ( is_wp_error( $result ) ) {
		$args['atf_entry_error'] = $result->get_error_code();
	} else {
		$args['atf_entries']   = (int) $result['imported'];
		$args['atf_remaining'] = (int) $result['remaining'];
	}

	wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_post_atf_import_entries', 'atf_handle_import_entries_post' );

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

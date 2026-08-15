<?php
/**
 * Reading, filtering and exporting entries.
 *
 * Every read of an entry goes through `atf_prepare_entry()`, which is where the
 * capability check lives and where raw stored values become the shapes the UI
 * and the exporter expect. Nothing else should touch `_atf_values` directly --
 * that is what stops a new surface from accidentally shipping somebody's
 * submissions to a user who may not read them.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Queries entries.
 *
 * @since 0.1.0
 *
 * @param array $args {
 *     Optional. Query arguments.
 *
 *     @type int      $form_id  Restrict to one form.
 *     @type string[] $status   Post statuses. Defaults to read and unread.
 *     @type string   $search   Free-text search across stored values.
 *     @type int      $page     One-based page number.
 *     @type int      $per_page How many per page.
 *     @type string   $orderby  `date` or `title`.
 *     @type string   $order    `ASC` or `DESC`.
 *     @type string   $after    Only entries on or after this date, `Y-m-d`.
 *     @type string   $before   Only entries on or before this date, `Y-m-d`.
 *     @type bool     $starred  Only starred entries.
 * }
 * @return array { entries: array[], total: int, pages: int }
 */
function atf_query_entries( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'form_id'  => 0,
			'status'   => array( ATF_STATUS_UNREAD, ATF_STATUS_READ ),
			'search'   => '',
			'page'     => 1,
			'per_page' => 25,
			'orderby'  => 'date',
			'order'    => 'DESC',
			'after'    => '',
			'before'   => '',
			'starred'  => false,
		)
	);

	if ( ! atf_can_read_entries( $args['form_id'] ) ) {
		return array(
			'entries' => array(),
			'total'   => 0,
			'pages'   => 0,
		);
	}

	$query_args = array(
		'post_type'      => ATF_ENTRY_TYPE,
		'post_status'    => (array) $args['status'],
		'posts_per_page' => min( 200, max( 1, (int) $args['per_page'] ) ),
		'paged'          => max( 1, (int) $args['page'] ),
		'orderby'        => in_array( $args['orderby'], array( 'date', 'title' ), true ) ? $args['orderby'] : 'date',
		'order'          => 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC',
		'meta_query'     => array(),
	);

	if ( $args['form_id'] ) {
		$query_args['meta_query'][] = array(
			'key'   => ATF_META_FORM,
			'value' => absint( $args['form_id'] ),
		);
	}

	if ( $args['starred'] ) {
		$query_args['meta_query'][] = array(
			'key'     => '_atf_starred',
			'compare' => 'EXISTS',
		);
	}

	if ( '' !== $args['after'] || '' !== $args['before'] ) {
		$date = array( 'inclusive' => true );

		if ( '' !== $args['after'] ) {
			$date['after'] = $args['after'];
		}

		if ( '' !== $args['before'] ) {
			$date['before'] = $args['before'];
		}

		$query_args['date_query'] = array( $date );
	}

	// Values live in one JSON meta blob, so a search over answers has to be a
	// `LIKE` against that blob. It is not indexed and it is not fast on a large
	// table, which is why it only runs when somebody actually typed something.
	if ( '' !== trim( (string) $args['search'] ) ) {
		$query_args['meta_query'][] = array(
			'key'     => ATF_META_VALUES,
			'value'   => sanitize_text_field( $args['search'] ),
			'compare' => 'LIKE',
		);
	}

	if ( count( $query_args['meta_query'] ) > 1 ) {
		$query_args['meta_query']['relation'] = 'AND';
	}

	$query   = new WP_Query( $query_args );
	$entries = array();

	foreach ( $query->posts as $post ) {
		$entry = atf_prepare_entry( $post );

		if ( $entry ) {
			$entries[] = $entry;
		}
	}

	return array(
		'entries' => $entries,
		'total'   => (int) $query->found_posts,
		'pages'   => (int) $query->max_num_pages,
	);
}

/**
 * Turns an entry post into the record every surface reads.
 *
 * The single door onto entry data. Returns an empty array when the current user
 * may not read entries, so a caller that forgets to check gets nothing rather
 * than a submission.
 *
 * @since 0.1.0
 *
 * @param int|WP_Post $entry The entry.
 * @return array The record, or an empty array.
 */
function atf_prepare_entry( $entry ) {
	$post = is_object( $entry ) ? $entry : get_post( absint( $entry ) );

	if ( ! $post || ATF_ENTRY_TYPE !== $post->post_type ) {
		return array();
	}

	$form_id = (int) get_post_meta( $post->ID, ATF_META_FORM, true );

	if ( ! atf_can_read_entries( $form_id ) ) {
		return array();
	}

	$values  = json_decode( (string) get_post_meta( $post->ID, ATF_META_VALUES, true ), true );
	$context = json_decode( (string) get_post_meta( $post->ID, ATF_META_CONTEXT, true ), true );

	$values  = is_array( $values ) ? $values : array();
	$context = is_array( $context ) ? $context : array();

	$schema = atf_get_form_schema( $form_id );

	$fields = array();

	foreach ( atf_input_fields( $schema ) as $field ) {
		$value = array_key_exists( $field['id'], $values ) ? $values[ $field['id'] ] : '';

		$fields[] = array(
			'id'        => $field['id'],
			'label'     => $field['label'],
			'type'      => $field['type'],
			'value'     => $value,
			'formatted' => atf_format_field_value( $value, $field, 'detail' ),
		);
	}

	$record = array(
		'id'        => $post->ID,
		'formId'    => $form_id,
		'formTitle' => get_the_title( $form_id ),
		'title'     => $post->post_title,
		'status'    => $post->post_status,
		'date'      => $post->post_date_gmt,
		'dateHuman' => get_the_date( '', $post ) . ' ' . get_the_time( '', $post ),
		'starred'   => (bool) get_post_meta( $post->ID, '_atf_starred', true ),
		'notes'     => (int) get_comments_number( $post->ID ),
		'values'    => $values,
		'fields'    => $fields,
		'ip'        => isset( $context['ip'] ) ? $context['ip'] : '',
		'userAgent' => isset( $context['userAgent'] ) ? $context['userAgent'] : '',
		'referrer'  => isset( $context['referrer'] ) ? $context['referrer'] : '',
		'userId'    => isset( $context['userId'] ) ? (int) $context['userId'] : 0,
		'spam'      => isset( $context['spam'] ) ? $context['spam'] : '',
		'quiz'      => isset( $context['quiz'] ) ? $context['quiz'] : null,
		'canDelete' => current_user_can( 'atf_delete_entries' ),
	);

	/**
	 * Filters a prepared entry record.
	 *
	 * @since 0.1.0
	 *
	 * @param array   $record The record.
	 * @param WP_Post $post   The entry post.
	 * @param array   $schema The form schema.
	 */
	return apply_filters( 'atf_prepare_entry', $record, $post, $schema );
}

/**
 * Changes an entry's status.
 *
 * Marking as spam or not-spam also tells Akismet, when it is installed, which is
 * what keeps the service learning about this particular site.
 *
 * @since 0.1.0
 *
 * @param int    $entry_id The entry.
 * @param string $status   One of the `ATF_STATUS_*` constants, or `trash`.
 * @return true|WP_Error
 */
function atf_set_entry_status( $entry_id, $status ) {
	$entry_id = absint( $entry_id );
	$post     = $entry_id ? get_post( $entry_id ) : null;

	if ( ! $post || ATF_ENTRY_TYPE !== $post->post_type ) {
		return new WP_Error( 'atf_entry_missing', __( 'That entry does not exist.', 'allterrain-forms' ), array( 'status' => 404 ) );
	}

	if ( ! atf_can_read_entries( (int) get_post_meta( $entry_id, ATF_META_FORM, true ) ) ) {
		return new WP_Error( 'atf_forbidden', __( 'You cannot change that entry.', 'allterrain-forms' ), array( 'status' => 403 ) );
	}

	$allowed = array( ATF_STATUS_UNREAD, ATF_STATUS_READ, ATF_STATUS_SPAM, 'trash' );

	if ( ! in_array( $status, $allowed, true ) ) {
		return new WP_Error( 'atf_bad_status', __( 'That is not a status an entry can have.', 'allterrain-forms' ), array( 'status' => 400 ) );
	}

	if ( 'trash' === $status ) {
		if ( ! current_user_can( 'atf_delete_entries' ) ) {
			return new WP_Error( 'atf_forbidden', __( 'You cannot delete entries.', 'allterrain-forms' ), array( 'status' => 403 ) );
		}

		wp_trash_post( $entry_id );

		return true;
	}

	$was = $post->post_status;

	wp_update_post(
		array(
			'ID'          => $entry_id,
			'post_status' => $status,
		)
	);

	if ( ATF_STATUS_SPAM === $status && ATF_STATUS_SPAM !== $was ) {
		atf_akismet_submit_correction( $entry_id, 'spam' );
	}

	if ( ATF_STATUS_SPAM === $was && ATF_STATUS_SPAM !== $status ) {
		atf_akismet_submit_correction( $entry_id, 'ham' );
	}

	/**
	 * Fires after an entry's status changes.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $entry_id The entry.
	 * @param string $status   The new status.
	 * @param string $was      The status it had.
	 */
	do_action( 'atf_entry_status_changed', $entry_id, $status, $was );

	return true;
}

/**
 * Stars or unstars an entry.
 *
 * @since 0.1.0
 *
 * @param int  $entry_id The entry.
 * @param bool $starred  Whether it should be starred.
 * @return true|WP_Error
 */
function atf_star_entry( $entry_id, $starred ) {
	$entry_id = absint( $entry_id );

	if ( ! atf_can_read_entries( (int) get_post_meta( $entry_id, ATF_META_FORM, true ) ) ) {
		return new WP_Error( 'atf_forbidden', __( 'You cannot change that entry.', 'allterrain-forms' ), array( 'status' => 403 ) );
	}

	if ( $starred ) {
		update_post_meta( $entry_id, '_atf_starred', 1 );
	} else {
		delete_post_meta( $entry_id, '_atf_starred' );
	}

	return true;
}

/**
 * The columns an entries export has, for one form.
 *
 * @since 0.1.0
 *
 * @param array $schema The form schema.
 * @return array<string, string> Column key => header.
 */
function atf_export_columns( $schema ) {
	$columns = array(
		'id'   => __( 'Entry ID', 'allterrain-forms' ),
		'date' => __( 'Submitted', 'allterrain-forms' ),
	);

	foreach ( atf_input_fields( $schema ) as $field ) {
		if ( 'password' === $field['type'] ) {
			continue;
		}

		$columns[ 'field:' . $field['id'] ] = '' !== $field['label'] ? $field['label'] : $field['id'];
	}

	$columns['status'] = __( 'Status', 'allterrain-forms' );
	$columns['ip']     = __( 'IP address', 'allterrain-forms' );

	/**
	 * Filters the columns of an entries export.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $columns Column key => header.
	 * @param array                 $schema  The form schema.
	 */
	return apply_filters( 'atf_export_columns', $columns, $schema );
}

/**
 * Builds a CSV of a form's entries.
 *
 * Prefixed with a UTF-8 BOM, because Excel on Windows reads a BOM-less UTF-8 CSV
 * as the local code page and turns every non-ASCII name into mojibake -- which
 * is the single most common complaint about every CSV export ever shipped.
 *
 * @since 0.1.0
 *
 * @param int   $form_id The form.
 * @param array $args    Query arguments, as `atf_query_entries()` takes.
 * @return string|WP_Error The CSV.
 */
function atf_export_entries_csv( $form_id, $args = array() ) {
	$form_id = absint( $form_id );

	if ( ! atf_can_read_entries( $form_id ) ) {
		return new WP_Error( 'atf_forbidden', __( 'You cannot export these entries.', 'allterrain-forms' ), array( 'status' => 403 ) );
	}

	$schema  = atf_get_form_schema( $form_id );
	$columns = atf_export_columns( $schema );

	$args = wp_parse_args(
		$args,
		array(
			'form_id'  => $form_id,
			'per_page' => 200,
			'page'     => 1,
		)
	);

	$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- An in-memory stream, not a file on disk; WP_Filesystem has no equivalent.

	if ( ! $handle ) {
		return new WP_Error( 'atf_export_failed', __( 'The export could not be built.', 'allterrain-forms' ) );
	}

	fputcsv( $handle, array_values( $columns ) );

	// Paged rather than fetched at once, so exporting fifty thousand entries
	// does not try to hold fifty thousand entries in memory.
	$page = 1;

	do {
		$args['page'] = $page;
		$result       = atf_query_entries( $args );

		foreach ( $result['entries'] as $entry ) {
			$row = array();

			foreach ( array_keys( $columns ) as $key ) {
				$row[] = atf_export_cell( $key, $entry, $schema );
			}

			fputcsv( $handle, $row );
		}

		++$page;
	} while ( $page <= $result['pages'] );

	rewind( $handle );

	$csv = stream_get_contents( $handle );

	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the in-memory stream opened above.

	return "\xEF\xBB\xBF" . $csv;
}

/**
 * Builds a JSON export of a form's entries.
 *
 * CSV is what people open; JSON is what they migrate with. A CSV flattens a
 * repeater to a line of text and a file field to a list of URLs, which is right
 * for a spreadsheet and lossy for anything that has to read the data back.
 *
 * @since 0.1.0
 *
 * @param int   $form_id The form.
 * @param array $args    Query arguments, as `atf_query_entries()` takes.
 * @return string|WP_Error The JSON.
 */
function atf_export_entries_json( $form_id, $args = array() ) {
	$form_id = absint( $form_id );

	if ( ! atf_can_read_entries( $form_id ) ) {
		return new WP_Error( 'atf_forbidden', __( 'You cannot export these entries.', 'allterrain-forms' ), array( 'status' => 403 ) );
	}

	$schema  = atf_get_form_schema( $form_id );
	$entries = array();

	$args = wp_parse_args(
		$args,
		array(
			'form_id'  => $form_id,
			'per_page' => 200,
		)
	);

	// Paged for the same reason the CSV is: a form with fifty thousand entries
	// must not try to hold fifty thousand entries in memory.
	$page = 1;

	do {
		$args['page'] = $page;
		$result       = atf_query_entries( $args );

		foreach ( $result['entries'] as $entry ) {
			$values = $entry['values'];

			// A password is never stored, but a form that used to have one may
			// have older entries from before that was true.
			foreach ( atf_input_fields( $schema ) as $field ) {
				if ( 'password' === $field['type'] ) {
					unset( $values[ $field['id'] ] );
				}
			}

			$entries[] = array(
				'id'     => $entry['id'],
				'date'   => $entry['date'],
				'status' => $entry['status'],
				'values' => $values,
			);
		}

		++$page;
	} while ( $page <= $result['pages'] );

	return (string) wp_json_encode(
		array(
			'plugin'  => 'allterrain-forms',
			'form'    => array(
				'id'    => $form_id,
				'title' => get_the_title( $form_id ),
			),
			'fields'  => array_map(
				static function ( $field ) {
					return array(
						'id'    => $field['id'],
						'type'  => $field['type'],
						'label' => $field['label'],
					);
				},
				atf_input_fields( $schema )
			),
			'entries' => $entries,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	);
}

/**
 * One cell of an export row.
 *
 * @since 0.1.0
 *
 * @param string $key    Column key.
 * @param array  $entry  The prepared entry.
 * @param array  $schema The form schema.
 * @return string
 */
function atf_export_cell( $key, $entry, $schema ) {
	if ( 'id' === $key ) {
		return (string) $entry['id'];
	}

	if ( 'date' === $key ) {
		return (string) $entry['date'];
	}

	if ( 'status' === $key ) {
		return (string) $entry['status'];
	}

	if ( 'ip' === $key ) {
		return (string) $entry['ip'];
	}

	if ( 0 === strpos( $key, 'field:' ) ) {
		$field_id = substr( $key, 6 );
		$field    = atf_find_field( $schema, $field_id );

		if ( ! $field ) {
			return '';
		}

		$value = isset( $entry['values'][ $field_id ] ) ? $entry['values'][ $field_id ] : '';

		return atf_sanitize_csv_cell( atf_format_field_value( $value, $field, 'csv' ) );
	}

	/**
	 * Filters one cell of an entries export.
	 *
	 * @since 0.1.0
	 *
	 * @param string $cell  The cell's contents.
	 * @param string $key   Column key.
	 * @param array  $entry The prepared entry.
	 */
	return (string) apply_filters( 'atf_export_cell', '', $key, $entry );
}

/**
 * Defuses a spreadsheet formula in an exported cell.
 *
 * A value beginning `=`, `+`, `-` or `@` is executed as a formula when the CSV
 * is opened in Excel, Numbers or Sheets. That turns "fill in this form" into
 * "run this on the site owner's machine", and it is the reason CSV injection has
 * its own CVE class. A leading apostrophe makes the cell text.
 *
 * @since 0.1.0
 *
 * @param string $value The cell's value.
 * @return string
 */
function atf_sanitize_csv_cell( $value ) {
	$value = (string) $value;

	if ( '' === $value ) {
		return $value;
	}

	if ( in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
		return "'" . $value;
	}

	return $value;
}

/**
 * Deletes entries that have outlived their form's retention policy.
 *
 * Runs daily. A retention of 0 means keep forever, which is the default -- a
 * plugin must not start deleting somebody's data on a schedule they did not
 * choose.
 *
 * @since 0.1.0
 *
 * @return int How many entries were deleted.
 */
function atf_apply_retention() {
	$forms = get_posts(
		array(
			'post_type'        => ATF_FORM_TYPE,
			'post_status'      => 'any',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'suppress_filters' => false,
		)
	);

	$deleted = 0;

	foreach ( $forms as $form_id ) {
		$schema = atf_get_form_schema( $form_id );
		$days   = (int) $schema['settings']['storage']['retention'];

		if ( $days < 1 ) {
			continue;
		}

		$old = get_posts(
			array(
				'post_type'      => ATF_ENTRY_TYPE,
				// Named, not `'any'` -- see atf_entry_statuses(). With `'any'`
				// this query matched nothing and the sweep silently deleted
				// nothing, on sites that had asked for a retention policy.
				'post_status'    => atf_entry_statuses(),
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'date_query'     => array(
					array(
						'before' => gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) ),
					),
				),
				'meta_query'     => array(
					array(
						'key'   => ATF_META_FORM,
						'value' => $form_id,
					),
				),
			)
		);

		foreach ( $old as $entry_id ) {
			atf_delete_entry_completely( $entry_id );
			++$deleted;
		}
	}

	/**
	 * Fires after a retention sweep.
	 *
	 * @since 0.1.0
	 *
	 * @param int $deleted How many entries were removed.
	 */
	do_action( 'atf_retention_applied', $deleted );

	return $deleted;
}
add_action( 'atf_apply_retention', 'atf_apply_retention' );

/**
 * Deletes an entry and everything attached to it.
 *
 * Uploads are deleted too. An entry deleted for retention that left the
 * applicant's passport scan on disk would defeat the entire point of having a
 * retention policy.
 *
 * @since 0.1.0
 *
 * @param int $entry_id The entry.
 * @return void
 */
function atf_delete_entry_completely( $entry_id ) {
	$entry_id = absint( $entry_id );

	$attachments = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'post_parent'    => $entry_id,
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);

	foreach ( $attachments as $attachment_id ) {
		wp_delete_attachment( $attachment_id, true );
	}

	wp_delete_post( $entry_id, true );
}

/**
 * Schedules the daily retention sweep.
 *
 * @since 0.1.0
 *
 * @return void
 */
function atf_schedule_retention() {
	if ( ! wp_next_scheduled( 'atf_apply_retention' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'atf_apply_retention' );
	}
}
add_action( 'init', 'atf_schedule_retention' );

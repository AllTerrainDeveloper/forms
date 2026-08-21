<?php
/**
 * Archiving.
 *
 * A finished form is not a deleted form. The campaign ended, the event
 * happened, the survey closed — but the entries are records and the stats are
 * history, and the trash is a place things go to die. Archiving retires the
 * form and everything that belongs to it in one move: the form leaves every
 * picker, its entries leave every list, export and badge, its stats go with
 * the form they hang off — and unarchiving puts all of it back exactly as it
 * was, down to whether the form was published or a draft.
 *
 * # How the pieces move
 *
 * The form itself changes post status, to `atf-archived` — a real registered
 * status, so revisions, meta and the trash all keep working and nothing about
 * the post is copied anywhere. Its previous status is kept in meta, because
 * "unarchive" must mean "put it back", not "publish it": archiving a draft
 * and getting a live form back would be the feature quietly publishing
 * something nobody finished.
 *
 * The entries stay in place and keep their own statuses — unread, read, spam
 * are *facts about the entry* that archiving must not erase — and are marked
 * with `_atf_archived` instead. Every surface that reaches entries through
 * their form is covered the moment the form leaves the pickers; the marker is
 * for the surfaces that do not: the all-forms entry list and the all-forms
 * export, which `atf_query_entries()` filters by it.
 *
 * The stats are post meta on the form, so they need no moving at all: they
 * vanish with the form and return with it, untouched.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/** Where an archived form's previous status waits for it. */
const ATF_META_PREARCHIVE = '_atf_prearchive_status';

/** The marker on an archived form's entries. */
const ATF_META_ARCHIVED = '_atf_archived';

/**
 * Whether a form is archived.
 *
 * @since 0.1.0
 *
 * @param int $form_id The form.
 * @return bool
 */
function atf_form_is_archived( $form_id ) {
	return ATF_STATUS_ARCHIVED === get_post_status( absint( $form_id ) );
}

/**
 * Archives a form, its entries and its stats.
 *
 * @since 0.1.0
 *
 * @param int $form_id The form.
 * @return true|WP_Error
 */
function atf_archive_form( $form_id ) {
	$form_id = absint( $form_id );
	$form    = $form_id ? get_post( $form_id ) : null;

	if ( ! $form || ATF_FORM_TYPE !== $form->post_type ) {
		return new WP_Error( 'atf_form_missing', __( 'That form does not exist.', 'allterrain-forms' ), array( 'status' => 404 ) );
	}

	if ( ATF_STATUS_ARCHIVED === $form->post_status ) {
		return new WP_Error( 'atf_already_archived', __( 'That form is already archived.', 'allterrain-forms' ), array( 'status' => 409 ) );
	}

	// Remembered before the change, so unarchiving restores what was true —
	// a draft comes back a draft, never surprise-published.
	update_post_meta( $form_id, ATF_META_PREARCHIVE, $form->post_status );

	$updated = wp_update_post(
		array(
			'ID'          => $form_id,
			'post_status' => ATF_STATUS_ARCHIVED,
		),
		true
	);

	if ( is_wp_error( $updated ) ) {
		return $updated;
	}

	atf_mark_form_entries_archived( $form_id, true );

	/**
	 * Fires after a form is archived.
	 *
	 * Its entries carry the `_atf_archived` marker and its stats sit
	 * untouched in the form's own meta.
	 *
	 * @since 0.1.0
	 *
	 * @param int $form_id The form.
	 */
	do_action( 'atf_form_archived', $form_id );

	return true;
}

/**
 * Unarchives a form, bringing its entries and stats back with it.
 *
 * @since 0.1.0
 *
 * @param int $form_id The form.
 * @return true|WP_Error
 */
function atf_unarchive_form( $form_id ) {
	$form_id = absint( $form_id );
	$form    = $form_id ? get_post( $form_id ) : null;

	if ( ! $form || ATF_FORM_TYPE !== $form->post_type ) {
		return new WP_Error( 'atf_form_missing', __( 'That form does not exist.', 'allterrain-forms' ), array( 'status' => 404 ) );
	}

	if ( ATF_STATUS_ARCHIVED !== $form->post_status ) {
		return new WP_Error( 'atf_not_archived', __( 'That form is not archived.', 'allterrain-forms' ), array( 'status' => 409 ) );
	}

	// The status it had when it went in. A form archived by something other
	// than `atf_archive_form()` has no note, and `draft` is the safe reading:
	// wrongly-draft is a visible inconvenience, wrongly-published is live.
	$previous = (string) get_post_meta( $form_id, ATF_META_PREARCHIVE, true );
	$previous = in_array( $previous, array( 'publish', 'draft' ), true ) ? $previous : 'draft';

	$updated = wp_update_post(
		array(
			'ID'          => $form_id,
			'post_status' => $previous,
		),
		true
	);

	if ( is_wp_error( $updated ) ) {
		return $updated;
	}

	delete_post_meta( $form_id, ATF_META_PREARCHIVE );
	atf_mark_form_entries_archived( $form_id, false );

	/**
	 * Fires after a form is unarchived.
	 *
	 * @since 0.1.0
	 *
	 * @param int $form_id The form.
	 */
	do_action( 'atf_form_unarchived', $form_id );

	return true;
}

/**
 * Marks or unmarks every one of a form's entries as archived.
 *
 * A marker rather than a status change, deliberately: unread, read and spam
 * are facts about the entry that have to survive the round trip, and a
 * status swap would need a second meta key to remember each one — the same
 * dance as the form, multiplied by every entry.
 *
 * Trash and partials included, because "everything that belongs to this
 * form" is not a list with exceptions: an entry restored from the trash
 * after the archive must not pop up in the all-forms list alone.
 *
 * @since 0.1.0
 *
 * @param int  $form_id  The form.
 * @param bool $archived Whether the entries are entering or leaving the archive.
 * @return void
 */
function atf_mark_form_entries_archived( $form_id, $archived ) {
	$entry_ids = get_posts(
		array(
			'post_type'      => ATF_ENTRY_TYPE,
			'post_status'    => atf_entry_statuses(),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => ATF_META_FORM,
					'value' => absint( $form_id ),
				),
			),
		)
	);

	foreach ( $entry_ids as $entry_id ) {
		if ( $archived ) {
			update_post_meta( $entry_id, ATF_META_ARCHIVED, 1 );
		} else {
			delete_post_meta( $entry_id, ATF_META_ARCHIVED );
		}
	}
}

/**
 * Lists archived forms, shaped like the active list.
 *
 * @since 0.1.0
 *
 * @return array[] Form summaries.
 */
function atf_archived_form_ids() {
	return get_posts(
		array(
			'post_type'        => ATF_FORM_TYPE,
			'post_status'      => ATF_STATUS_ARCHIVED,
			'numberposts'      => 200,
			'orderby'          => 'modified',
			'order'            => 'DESC',
			'fields'           => 'ids',
			'suppress_filters' => false,
		)
	);
}

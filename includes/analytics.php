<?php
/**
 * Per-form analytics.
 *
 * Views, submissions and a conversion rate, kept as a small counter blob per
 * form. Not an events table: a row per view would make a busy form's analytics
 * larger than its entries, and the questions people actually ask of a form --
 * "how many saw it, how many finished it, which field are they giving up on" --
 * are all answerable from counters and the entries that already exist.
 *
 * Nothing here identifies a visitor. No cookie, no fingerprint, no per-visitor
 * row. Analytics that need consent are analytics most sites cannot switch on.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * The counters a form starts with.
 *
 * @since 0.1.0
 *
 * @return array<string, int>
 */
function atf_default_stats() {
	return array(
		'views'       => 0,
		'starts'      => 0,
		'submissions' => 0,
		'abandons'    => 0,
	);
}

/**
 * Reads a form's counters.
 *
 * @since 0.1.0
 *
 * @param int $form_id The form.
 * @return array<string, int>
 */
function atf_get_stats( $form_id ) {
	$stats = json_decode( (string) get_post_meta( absint( $form_id ), ATF_META_STATS, true ), true );

	return array_merge( atf_default_stats(), is_array( $stats ) ? array_map( 'absint', $stats ) : array() );
}

/**
 * Adds to one of a form's counters.
 *
 * @since 0.1.0
 *
 * @param int    $form_id The form.
 * @param string $key     Counter name.
 * @param int    $by      How much to add.
 * @return void
 */
function atf_bump_stat( $form_id, $key, $by = 1 ) {
	$form_id = absint( $form_id );

	if ( ! $form_id ) {
		return;
	}

	$stats = atf_get_stats( $form_id );

	if ( ! array_key_exists( $key, $stats ) ) {
		return;
	}

	$stats[ $key ] = max( 0, $stats[ $key ] + (int) $by );

	update_post_meta( $form_id, ATF_META_STATS, wp_slash( wp_json_encode( $stats ) ) );
}

/**
 * Counts a view of a form.
 *
 * Skipped for anyone who can edit forms, so building and previewing a form does
 * not inflate its own conversion rate -- which would make the number useless
 * for exactly the person looking at it.
 *
 * @since 0.1.0
 *
 * @param int $form_id The form.
 * @return void
 */
function atf_record_view( $form_id ) {
	if ( atf_can_edit_forms() ) {
		return;
	}

	/**
	 * Filters whether a form view is counted.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $record  Whether to count it.
	 * @param int  $form_id The form.
	 */
	if ( ! apply_filters( 'atf_record_view', true, $form_id ) ) {
		return;
	}

	atf_bump_stat( $form_id, 'views' );
}

/**
 * Counts an accepted submission.
 *
 * @since 0.1.0
 *
 * @param int $form_id The form.
 * @return void
 */
function atf_record_submission( $form_id ) {
	atf_bump_stat( $form_id, 'submissions' );
}

/**
 * Counts somebody beginning to fill a form in.
 *
 * Sent by the bundle on the first interaction with any field. The gap between
 * starts and submissions is the number that tells a site something actionable:
 * a form nobody starts has a presentation problem, and a form everybody starts
 * and nobody finishes has a question problem.
 *
 * @since 0.1.0
 *
 * @param int $form_id The form.
 * @return void
 */
function atf_record_start( $form_id ) {
	atf_bump_stat( $form_id, 'starts' );
}

/**
 * A form's analytics, assembled for the UI.
 *
 * @since 0.1.0
 *
 * @param int $form_id The form.
 * @return array
 */
function atf_form_analytics( $form_id ) {
	$form_id = absint( $form_id );
	$stats   = atf_get_stats( $form_id );
	$schema  = atf_get_form_schema( $form_id );

	$views       = $stats['views'];
	$submissions = $stats['submissions'];

	$report = array(
		'views'       => $views,
		'starts'      => $stats['starts'],
		'submissions' => $submissions,
		// Floored rather than rounded, so 199 submissions from 200 views reads
		// as 99% and never as the 100% that would suggest nobody ever left.
		//
		// Capped at 100 because the two counters are deliberately asymmetric: a
		// view by somebody who can edit forms is not counted, so that building
		// and previewing does not inflate the rate -- but their *submissions*
		// are, because a real submission is real whoever made it. Testing a form
		// a few times therefore produces more submissions than views, and a
		// conversion rate of 200% is not a number anybody can act on.
		'conversion'  => $views > 0 ? min( 100, (int) floor( ( $submissions / $views ) * 100 ) ) : 0,
		'completion'  => $stats['starts'] > 0 ? min( 100, (int) floor( ( $submissions / $stats['starts'] ) * 100 ) ) : 0,
		'unread'      => 0,
		'spam'        => 0,
		'fields'      => array(),
	);

	$counts = wp_count_posts( ATF_ENTRY_TYPE );

	// The per-status counts are site-wide, so a form-specific number needs its
	// own query. Only done for the two that are shown as badges.
	$report['unread'] = atf_count_entries_by_status( $form_id, ATF_STATUS_UNREAD );
	$report['spam']   = atf_count_entries_by_status( $form_id, ATF_STATUS_SPAM );

	unset( $counts );

	$report['fields'] = atf_field_response_rates( $form_id, $schema );

	/**
	 * Filters a form's analytics report.
	 *
	 * @since 0.1.0
	 *
	 * @param array $report  The report.
	 * @param int   $form_id The form.
	 */
	return apply_filters( 'atf_form_analytics', $report, $form_id );
}

/**
 * How many entries a form has in one status.
 *
 * @since 0.1.0
 *
 * @param int    $form_id The form.
 * @param string $status  The status.
 * @return int
 */
function atf_count_entries_by_status( $form_id, $status ) {
	$query = new WP_Query(
		array(
			'post_type'      => ATF_ENTRY_TYPE,
			'post_status'    => $status,
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'   => ATF_META_FORM,
					'value' => absint( $form_id ),
				),
			),
		)
	);

	return (int) $query->found_posts;
}

/**
 * How often each field was actually answered.
 *
 * The per-field drop-off report. A required field with a low response rate is a
 * field people are abandoning the form at, and it is usually the one asking for
 * a phone number.
 *
 * Sampled rather than exhaustive, because this runs when somebody opens a report
 * and a form with a hundred thousand entries should still answer in a moment.
 *
 * @since 0.1.0
 *
 * @param int   $form_id The form.
 * @param array $schema  The form schema.
 * @return array[] One row per input field.
 */
function atf_field_response_rates( $form_id, $schema ) {
	/**
	 * Filters how many entries the per-field report samples.
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit   Number of entries.
	 * @param int $form_id The form.
	 */
	$limit = (int) apply_filters( 'atf_analytics_sample_size', 500, $form_id );

	$query = new WP_Query(
		array(
			'post_type'      => ATF_ENTRY_TYPE,
			'post_status'    => array( ATF_STATUS_UNREAD, ATF_STATUS_READ ),
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'   => ATF_META_FORM,
					'value' => absint( $form_id ),
				),
			),
		)
	);

	$sampled = count( $query->posts );
	$answers = array();
	$choices = array();

	foreach ( $query->posts as $entry_id ) {
		$values = json_decode( (string) get_post_meta( $entry_id, ATF_META_VALUES, true ), true );

		if ( ! is_array( $values ) ) {
			continue;
		}

		foreach ( $values as $field_id => $value ) {
			if ( atf_value_is_empty( $value ) ) {
				continue;
			}

			$answers[ $field_id ] = isset( $answers[ $field_id ] ) ? $answers[ $field_id ] + 1 : 1;

			// Choice tallies are what turn a survey into a report. Collected in
			// the same pass rather than a second query over the same rows.
			foreach ( (array) $value as $item ) {
				if ( ! is_scalar( $item ) || '' === $item ) {
					continue;
				}

				$key = (string) $item;

				if ( ! isset( $choices[ $field_id ] ) ) {
					$choices[ $field_id ] = array();
				}

				$choices[ $field_id ][ $key ] = isset( $choices[ $field_id ][ $key ] ) ? $choices[ $field_id ][ $key ] + 1 : 1;
			}
		}
	}

	$rows = array();

	foreach ( atf_input_fields( $schema ) as $field ) {
		$answered = isset( $answers[ $field['id'] ] ) ? $answers[ $field['id'] ] : 0;

		$row = array(
			'id'       => $field['id'],
			'label'    => '' !== $field['label'] ? $field['label'] : $field['id'],
			'type'     => $field['type'],
			'answered' => $answered,
			'rate'     => $sampled > 0 ? (int) floor( ( $answered / $sampled ) * 100 ) : 0,
			'choices'  => array(),
		);

		if ( $field['choices'] && isset( $choices[ $field['id'] ] ) ) {
			foreach ( $field['choices'] as $choice ) {
				$count = isset( $choices[ $field['id'] ][ $choice['value'] ] ) ? $choices[ $field['id'] ][ $choice['value'] ] : 0;

				$row['choices'][] = array(
					'label'   => $choice['label'],
					'value'   => $choice['value'],
					'count'   => $count,
					'percent' => $answered > 0 ? (int) floor( ( $count / $answered ) * 100 ) : 0,
				);
			}
		}

		// Numeric fields get an average instead of a tally, which is the only
		// summary that means anything for a rating or a scale.
		if ( in_array( $field['type'], array( 'rating', 'scale', 'number', 'range' ), true ) && isset( $choices[ $field['id'] ] ) ) {
			$sum   = 0;
			$total = 0;

			foreach ( $choices[ $field['id'] ] as $value => $count ) {
				if ( is_numeric( $value ) ) {
					$sum   += (float) $value * $count;
					$total += $count;
				}
			}

			$row['average'] = $total > 0 ? round( $sum / $total, 2 ) : null;
		}

		$rows[] = $row;
	}

	return $rows;
}

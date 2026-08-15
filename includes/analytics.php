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
 * @param int    $form_id   The form.
 * @param string $dimension The field to break the numbers down by. Empty picks
 *                          the first one that is suitable.
 * @return array
 */
function atf_form_analytics( $form_id, $dimension = '' ) {
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

	$sample = atf_analytics_sample( $form_id );

	$report['sampled']  = $sample['sampled'];
	$report['timeline'] = atf_analytics_timeline( $sample['rows'] );

	// The numeric summaries are attached to the fields they belong to rather than
	// listed separately, so a client drawing the per-field report has everything
	// about a field in one place and never has to join two lists by id.
	$by_id = array();

	foreach ( atf_input_fields( $schema ) as $field ) {
		$by_id[ $field['id'] ] = $field;
	}

	foreach ( $report['fields'] as $index => $row ) {
		$field = isset( $by_id[ $row['id'] ] ) ? $by_id[ $row['id'] ] : null;

		if ( ! $field || ! atf_analytics_is_numeric_field( $field ) ) {
			continue;
		}

		$numbers = array();

		foreach ( $sample['rows'] as $entry ) {
			$value = isset( $entry['values'][ $row['id'] ] ) ? $entry['values'][ $row['id'] ] : null;

			if ( is_numeric( $value ) ) {
				$numbers[] = (float) $value;
			}
		}

		$report['fields'][ $index ]['numbers'] = atf_analytics_numbers( $numbers );
		$report['fields'][ $index ]['nps']     = atf_analytics_is_nps_field( $field )
			? atf_analytics_nps( $numbers )
			: null;
	}

	$dimensions = atf_analytics_dimensions( $schema );
	$dimension  = (string) $dimension;

	// An unknown or absent grouping falls back to the first suitable field rather
	// than to nothing, so the panel arrives showing a breakdown instead of a
	// dropdown somebody has to discover.
	$known = wp_list_pluck( $dimensions, 'id' );

	if ( ! in_array( $dimension, $known, true ) ) {
		$dimension = $known ? (string) $known[0] : '';
	}

	$report['dimensions'] = $dimensions;
	$report['breakdown']  = '' !== $dimension
		? atf_analytics_breakdown( $sample['rows'], $schema, $dimension )
		: null;

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
	$sample  = atf_analytics_sample( $form_id );
	$sampled = $sample['sampled'];
	$answers = array();
	$choices = array();

	foreach ( $sample['rows'] as $row ) {
		$values = $row['values'];

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

/**
 * The entries a report is computed from, loaded once.
 *
 * Every statistic below needs the same thing — the answers, and when they were
 * given — and each one querying for itself would mean five passes over the same
 * few hundred rows to draw one screen. This is the single pass they share.
 *
 * Spam is excluded and so are partials. Both are deliberate: spam is not an
 * opinion, and a partial is a form somebody has not finished, so counting it
 * would make the response rate of the first question look like the response rate
 * of the form.
 *
 * @since 0.1.0
 *
 * @param int $form_id The form.
 * @return array { sampled, rows: array of { values, time } }.
 */
function atf_analytics_sample( $form_id ) {
	/**
	 * Filters how many entries a report samples.
	 *
	 * The cap is what keeps a report on a form with a hundred thousand entries
	 * answering in a moment. Raising it trades that for exactness.
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
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'   => ATF_META_FORM,
					'value' => absint( $form_id ),
				),
			),
		)
	);

	$rows = array();

	foreach ( $query->posts as $entry ) {
		$values = json_decode( (string) get_post_meta( $entry->ID, ATF_META_VALUES, true ), true );

		if ( ! is_array( $values ) ) {
			continue;
		}

		$rows[] = array(
			'values' => $values,
			// GMT, because a timeline bucketed by local time would shift every
			// bar when somebody changed the site's timezone.
			'time'   => strtotime( $entry->post_date_gmt . ' UTC' ),
		);
	}

	return array(
		'sampled' => count( $rows ),
		'rows'    => $rows,
	);
}

/**
 * Submissions per day.
 *
 * Every day in the window is present, including the empty ones. A timeline built
 * only from days that had a submission is the classic sparse-series bug: the gaps
 * close up, a quiet fortnight renders the same width as a busy one, and the chart
 * shows a steady trickle where the truth was one spike and three weeks of
 * silence.
 *
 * @since 0.1.0
 *
 * @param array $rows Sampled rows.
 * @param int   $days How far back to go.
 * @return array[] One row per day, oldest first: { date, count }.
 */
function atf_analytics_timeline( $rows, $days = 90 ) {
	$days   = max( 1, (int) $days );
	$today  = (int) ( floor( time() / DAY_IN_SECONDS ) * DAY_IN_SECONDS );
	$counts = array();

	for ( $i = $days - 1; $i >= 0; $i-- ) {
		$counts[ gmdate( 'Y-m-d', $today - $i * DAY_IN_SECONDS ) ] = 0;
	}

	foreach ( $rows as $row ) {
		if ( ! $row['time'] ) {
			continue;
		}

		$key = gmdate( 'Y-m-d', $row['time'] );

		if ( isset( $counts[ $key ] ) ) {
			++$counts[ $key ];
		}
	}

	$out = array();

	foreach ( $counts as $date => $count ) {
		$out[] = array(
			'date'  => $date,
			'count' => $count,
		);
	}

	return $out;
}

/**
 * Summary statistics for a set of numbers.
 *
 * The median is here because the mean alone is misleading on exactly the
 * questions people put on surveys: a 0–10 score where most answers are 8 and a
 * handful are 0 has a mean that describes nobody.
 *
 * The distribution is returned as well, and is not a nicety — a mean of 3 can be
 * everybody answering 3, or half answering 1 and half answering 5, and those are
 * opposite findings.
 *
 * @since 0.1.0
 *
 * @param float[] $numbers The values.
 * @return array|null { count, mean, median, min, max, distribution }, or null.
 */
function atf_analytics_numbers( $numbers ) {
	$numbers = array_values( array_filter( $numbers, 'is_numeric' ) );

	if ( ! $numbers ) {
		return null;
	}

	sort( $numbers, SORT_NUMERIC );

	$count  = count( $numbers );
	$middle = (int) floor( $count / 2 );

	$median = 0 === $count % 2
		? ( $numbers[ $middle - 1 ] + $numbers[ $middle ] ) / 2
		: $numbers[ $middle ];

	$distribution = array();

	foreach ( $numbers as $number ) {
		$key = (string) ( 0 === fmod( (float) $number, 1 ) ? (int) $number : round( (float) $number, 2 ) );

		$distribution[ $key ] = isset( $distribution[ $key ] ) ? $distribution[ $key ] + 1 : 1;
	}

	return array(
		'count'        => $count,
		'mean'         => round( array_sum( $numbers ) / $count, 2 ),
		'median'       => round( $median, 2 ),
		'min'          => (float) $numbers[0],
		'max'          => (float) $numbers[ $count - 1 ],
		'distribution' => $distribution,
	);
}

/**
 * Net Promoter Score for a 0–10 question.
 *
 * NPS is the one summary on a survey that is *not* an average, and computing it
 * as one is the mistake it invites: the score is the percentage of promoters
 * minus the percentage of detractors, and the sevens and eights in the middle
 * count for nothing at all. A mean of the same answers is a different number that
 * moves differently, and reporting it as NPS makes every benchmark meaningless.
 *
 * The result runs from -100 to 100.
 *
 * @since 0.1.0
 *
 * @param float[] $numbers Answers, 0 to 10.
 * @return array|null { score, promoters, passives, detractors, responses }.
 */
function atf_analytics_nps( $numbers ) {
	$numbers = array_values( array_filter( $numbers, 'is_numeric' ) );

	if ( ! $numbers ) {
		return null;
	}

	$promoters  = 0;
	$passives   = 0;
	$detractors = 0;

	foreach ( $numbers as $number ) {
		$number = (float) $number;

		if ( $number >= 9 ) {
			++$promoters;
		} elseif ( $number >= 7 ) {
			++$passives;
		} else {
			++$detractors;
		}
	}

	$total = count( $numbers );

	return array(
		'responses'  => $total,
		'promoters'  => $promoters,
		'passives'   => $passives,
		'detractors' => $detractors,
		'score'      => (int) round( ( ( $promoters - $detractors ) / $total ) * 100 ),
	);
}

/**
 * Whether a field's answers are numbers worth summarising.
 *
 * @since 0.1.0
 *
 * @param array $field The field.
 * @return bool
 */
function atf_analytics_is_numeric_field( $field ) {
	return in_array( $field['type'], array( 'rating', 'scale', 'number', 'range', 'total' ), true );
}

/**
 * Whether a field is a 0–10 recommendation question.
 *
 * Recognised by its shape rather than by a flag somebody has to remember to set:
 * a scale from 0 to 10 is what an NPS question is, whatever it has been called.
 *
 * @since 0.1.0
 *
 * @param array $field The field.
 * @return bool
 */
function atf_analytics_is_nps_field( $field ) {
	if ( 'scale' !== $field['type'] ) {
		return false;
	}

	$min = isset( $field['min'] ) ? (int) $field['min'] : 0;
	$max = isset( $field['max'] ) ? (int) $field['max'] : 10;

	return 0 === $min && 10 === $max;
}

/**
 * The fields a report can group by.
 *
 * A grouping field has to have few enough distinct answers to be readable and
 * more than one to be worth doing — a dimension with forty values is not a
 * breakdown, it is the raw data with extra steps.
 *
 * @since 0.1.0
 *
 * @param array $schema The form schema.
 * @return array[] { id, label }.
 */
function atf_analytics_dimensions( $schema ) {
	$out = array();

	foreach ( atf_input_fields( $schema ) as $field ) {
		if ( ! in_array( $field['type'], array( 'select', 'radio', 'country' ), true ) ) {
			continue;
		}

		$count = count( $field['choices'] );

		if ( $count < 2 || $count > 12 ) {
			continue;
		}

		$out[] = array(
			'id'    => $field['id'],
			'label' => '' !== $field['label'] ? $field['label'] : $field['id'],
		);
	}

	/**
	 * Filters the fields a report offers to group by.
	 *
	 * @since 0.1.0
	 *
	 * @param array[] $dimensions { id, label }.
	 * @param array   $schema     The form schema.
	 */
	return apply_filters( 'atf_analytics_dimensions', $out, $schema );
}

/**
 * Every numeric answer broken down by one categorical answer.
 *
 * The cross-tab, and the reason the rest of this exists. "The average score is
 * 7.2" is a fact nobody can act on; "Support scores 4.1 and everyone else is
 * above 7" is a fact with an obvious next step.
 *
 * Groups are returned in the order the field lists its choices, not in order of
 * size — a bar chart whose categories reshuffle between two loads is one nobody
 * can read twice.
 *
 * @since 0.1.0
 *
 * @param array  $rows      Sampled rows.
 * @param array  $schema    The form schema.
 * @param string $dimension The field to group by.
 * @return array { id, label, groups }.
 */
function atf_analytics_breakdown( $rows, $schema, $dimension ) {
	$fields = array();

	foreach ( atf_input_fields( $schema ) as $field ) {
		$fields[ $field['id'] ] = $field;
	}

	if ( ! isset( $fields[ $dimension ] ) ) {
		return array(
			'id'     => '',
			'label'  => '',
			'groups' => array(),
		);
	}

	$by = $fields[ $dimension ];

	// Seeded from the choice list rather than from the answers, so a group nobody
	// picked shows as an honest zero instead of vanishing — "no member of the
	// leadership team answered" being a finding in its own right.
	$buckets = array();

	foreach ( $by['choices'] as $choice ) {
		$buckets[ (string) $choice['value'] ] = array(
			'label'   => '' !== $choice['label'] ? $choice['label'] : (string) $choice['value'],
			'count'   => 0,
			'answers' => array(),
		);
	}

	foreach ( $rows as $row ) {
		$key = isset( $row['values'][ $dimension ] ) ? $row['values'][ $dimension ] : null;

		if ( ! is_scalar( $key ) || ! isset( $buckets[ (string) $key ] ) ) {
			continue;
		}

		$key = (string) $key;

		++$buckets[ $key ]['count'];

		foreach ( $fields as $field_id => $field ) {
			if ( ! atf_analytics_is_numeric_field( $field ) ) {
				continue;
			}

			$value = isset( $row['values'][ $field_id ] ) ? $row['values'][ $field_id ] : null;

			if ( is_numeric( $value ) ) {
				$buckets[ $key ]['answers'][ $field_id ][] = (float) $value;
			}
		}
	}

	$groups = array();

	foreach ( $buckets as $value => $bucket ) {
		$metrics = array();

		foreach ( $bucket['answers'] as $field_id => $numbers ) {
			$summary = atf_analytics_numbers( $numbers );

			if ( ! $summary ) {
				continue;
			}

			$metrics[] = array(
				'id'    => $field_id,
				'label' => '' !== $fields[ $field_id ]['label'] ? $fields[ $field_id ]['label'] : $field_id,
				'mean'  => $summary['mean'],
				'nps'   => atf_analytics_is_nps_field( $fields[ $field_id ] )
					? atf_analytics_nps( $numbers )['score']
					: null,
			);
		}

		$groups[] = array(
			'value'   => $value,
			'label'   => $bucket['label'],
			'count'   => $bucket['count'],
			'metrics' => $metrics,
		);
	}

	return array(
		'id'     => $dimension,
		'label'  => '' !== $by['label'] ? $by['label'] : $dimension,
		'groups' => $groups,
	);
}

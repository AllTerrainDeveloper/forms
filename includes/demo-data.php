<?php
/**
 * Demo data — a survey, and several hundred people who answered it.
 *
 * Analytics are impossible to build against an empty database and misleading to
 * build against a handful of test rows. Five submissions make every chart look
 * fine: no long tail, no skew, no segment small enough to be noisy, no date range
 * wide enough to need a scale. The first honest look at a report is the moment
 * somebody points it at a real form with four hundred responses, which is much
 * too late to discover that the bar chart cannot cope with a label longer than
 * twelve characters.
 *
 * So this generates a survey and a plausible population that answered it, and the
 * analytics are built against that.
 *
 * # These are real submissions
 *
 * Every one goes through `atf_process_submission()` — the same function a
 * stranger's POST reaches. Sanitising, validation, calculations, spam screening,
 * storage, the stats counters and `atf_entry_created` all run exactly as they do
 * in production. Nothing is written straight into the database.
 *
 * That is the whole point. A seeder that inserted rows directly would produce
 * data the pipeline could never actually have produced, and the first thing it
 * would hide is a bug in the pipeline. The one price is that the demo form must
 * post something the anti-spam screening accepts, which it does honestly: the
 * request carries a properly signed timestamp, made by the same
 * `atf_sign_timestamp()` the rendered form uses, dated far enough back to clear
 * the time trap.
 *
 * # The population is not random
 *
 * Random answers produce flat charts. Every question would come out at 1/n, every
 * segment identical, every correlation zero — which looks like a working report
 * and tells you nothing about whether it would show a real pattern if there were
 * one.
 *
 * Each respondent instead gets a latent **morale**, drawn from a distribution
 * that depends on their team and how long they have been there, and their answers
 * follow from it: how they rate the place, how likely they are to recommend it,
 * what they say would improve their week, and whether they write a comment at all.
 * That gives the analytics something true to find — satisfaction really does track
 * NPS, one team really is unhappier than the others, and long-tenured people
 * really do answer differently — and makes it obvious when a report fails to show
 * it.
 *
 * # It is reproducible
 *
 * The generator is a seeded PRNG, so the same seed produces the same people. Two
 * runs are comparable, a screenshot can be regenerated, and a test can assert a
 * distribution rather than a range.
 *
 * # It comes back out
 *
 * The form and every entry carry `_atf_demo`. Removal deletes exactly what has
 * that marker and nothing else — never "everything on this form", which would
 * take real submissions with it if somebody had used the demo form for something.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Marks a form or entry as generated.
 *
 * On the form it holds the batch's seed; on an entry it is simply `1`. Removal
 * matches on the key alone, so an entry made by any batch is removed by any
 * removal — which is what somebody clicking "Remove demo data" means.
 */
const ATF_META_DEMO = '_atf_demo';

/** How many submissions a full batch makes. */
const ATF_DEMO_TARGET = 500;

/**
 * How many are generated per request.
 *
 * Each one runs the whole submission pipeline, so a full batch is five hundred
 * passes through validation, spam screening and storage. That is comfortably more
 * than a PHP request should do at once on modest hosting, so the client asks
 * repeatedly and watches the count come down — which also gives it something
 * truthful to put in a progress bar.
 *
 * Twenty-five rather than fifty, after fifty exhausted a 128MB limit partway
 * through a batch — see `atf_demo_seed()` for what was accumulating and what is
 * now freed. The limit is the common one on shared hosting, so being comfortable
 * inside it matters more than the handful of extra requests.
 */
const ATF_DEMO_CHUNK = 25;

/**
 * The survey.
 *
 * Seven questions, chosen for what they make possible downstream rather than for
 * what a pulse survey would ideally ask. Between them they cover every shape a
 * report has to handle:
 *
 * - **Two categorical dimensions** (team, tenure) to break every other answer
 *   down by, one unordered and one ordered.
 * - **A 0–10 recommendation score**, which is an NPS question — the one summary
 *   that is not a mean, and the one most likely to be computed wrongly.
 * - **A 1–5 rating**, whose mean is meaningful and which should visibly track the
 *   NPS question without being identical to it.
 * - **A small integer** (days in the office) with a genuinely lumpy distribution,
 *   because a histogram that only ever sees a bell curve is untested.
 * - **A multi-select**, where the percentages deliberately sum past 100 and a
 *   report that divides by the number of answers instead of the number of people
 *   is wrong in a way nobody notices.
 * - **An optional free-text box**, answered by well under half — so response rate
 *   is a number that varies per field instead of always being 100.
 *
 * @since 0.1.0
 *
 * @return array The schema.
 */
function atf_demo_survey_schema() {
	return array(
		'fields'   => array(
			array(
				'id'    => 'intro',
				'type'  => 'heading',
				'label' => __( 'How has the last quarter been?', 'allterrain-forms' ),
				'level' => 3,
			),
			array(
				'id'       => 'team',
				'type'     => 'select',
				'label'    => __( 'Which team are you on?', 'allterrain-forms' ),
				'required' => true,
				'hint'     => __( 'Used only to group answers. Nothing here is attributed to a person.', 'allterrain-forms' ),
				'choices'  => array(
					__( 'Engineering', 'allterrain-forms' ),
					__( 'Design', 'allterrain-forms' ),
					__( 'Product', 'allterrain-forms' ),
					__( 'Data', 'allterrain-forms' ),
					__( 'Support', 'allterrain-forms' ),
					__( 'Sales', 'allterrain-forms' ),
					__( 'Operations', 'allterrain-forms' ),
				),
			),
			array(
				'id'       => 'tenure',
				'type'     => 'radio',
				'label'    => __( 'How long have you been here?', 'allterrain-forms' ),
				'required' => true,
				'choices'  => array(
					__( 'Less than a year', 'allterrain-forms' ),
					__( '1 to 2 years', 'allterrain-forms' ),
					__( '3 to 5 years', 'allterrain-forms' ),
					__( 'More than 5 years', 'allterrain-forms' ),
				),
			),
			array(
				'id'       => 'office_days',
				'type'     => 'scale',
				'label'    => __( 'Days a week you work from the office', 'allterrain-forms' ),
				'min'      => 0,
				'max'      => 5,
				'minLabel' => __( 'Never', 'allterrain-forms' ),
				'maxLabel' => __( 'Every day', 'allterrain-forms' ),
			),
			array(
				'id'       => 'recommend',
				'type'     => 'scale',
				'label'    => __( 'How likely are you to recommend working here to a friend?', 'allterrain-forms' ),
				'required' => true,
				'min'      => 0,
				'max'      => 10,
				'minLabel' => __( 'Not at all likely', 'allterrain-forms' ),
				'maxLabel' => __( 'Extremely likely', 'allterrain-forms' ),
			),
			array(
				'id'       => 'satisfaction',
				'type'     => 'rating',
				'label'    => __( 'Overall, how has this quarter been?', 'allterrain-forms' ),
				'required' => true,
				'max'      => 5,
			),
			array(
				'id'      => 'improve',
				'type'    => 'checkboxes',
				'label'   => __( 'What would most improve your week?', 'allterrain-forms' ),
				'hint'    => __( 'Pick as many as apply.', 'allterrain-forms' ),
				'choices' => array(
					__( 'Clearer priorities', 'allterrain-forms' ),
					__( 'Faster decisions', 'allterrain-forms' ),
					__( 'Fewer meetings', 'allterrain-forms' ),
					__( 'Better tooling', 'allterrain-forms' ),
					__( 'More flexible hours', 'allterrain-forms' ),
					__( 'A quieter place to work', 'allterrain-forms' ),
					__( 'More time off', 'allterrain-forms' ),
				),
			),
			array(
				'id'          => 'comment',
				'type'        => 'textarea',
				'label'       => __( 'Anything else you want to say?', 'allterrain-forms' ),
				'placeholder' => __( 'Optional, and read by a person.', 'allterrain-forms' ),
				'rows'        => 4,
			),
		),
		'settings'      => array(
			'title'       => 'show',
			'submitLabel' => __( 'Send it', 'allterrain-forms' ),
		),
		// Top level, beside `fields` — not inside `settings`, where they would be
		// silently dropped by normalisation and the form would fall back to
		// whatever the defaults are. Empty here is deliberate rather than
		// incidental: five hundred submissions with a notification configured is
		// five hundred e-mails, sent for a demo, to whoever runs the site.
		'notifications' => array(),
		'confirmations' => array(
			array(
				'id'      => 'c1',
				'type'    => 'message',
				'message' => __( 'Thank you — that is genuinely useful.', 'allterrain-forms' ),
			),
		),
	);
}

/**
 * A deterministic pseudo-random number generator.
 *
 * `wp_rand()` is the right function for anything that must be unguessable and the
 * wrong one here: the point of this data is that the same seed gives the same
 * people, so a chart can be regenerated and a test can assert a distribution
 * rather than a range.
 *
 * xorshift32 — small, fast, and far better distributed than the
 * `seed = ( seed * 1103515245 + 12345 )` that usually turns up in a seeder, whose
 * low bits famously alternate and would make every "one in two" decision here
 * correlate with the one before it.
 *
 * @since 0.1.0
 *
 * @param int $state The generator state, updated in place.
 * @return float A number in [0, 1).
 */
function atf_demo_random( &$state ) {
	$state ^= ( $state << 13 ) & 0xFFFFFFFF;
	$state ^= ( $state >> 17 );
	$state ^= ( $state << 5 ) & 0xFFFFFFFF;
	$state &= 0xFFFFFFFF;

	// A zero state is xorshift's one fixed point: it would return zero forever.
	if ( 0 === $state ) {
		$state = 0x2545F491;
	}

	return $state / 4294967296;
}

/**
 * Picks one of a set of options by weight.
 *
 * @since 0.1.0
 *
 * @param array $weights Option => relative weight.
 * @param int   $state   The generator state.
 * @return string|int The chosen key.
 */
function atf_demo_weighted( $weights, &$state ) {
	$total = array_sum( $weights );

	if ( $total <= 0 ) {
		return key( $weights );
	}

	$roll = atf_demo_random( $state ) * $total;

	foreach ( $weights as $option => $weight ) {
		$roll -= $weight;

		if ( $roll <= 0 ) {
			return $option;
		}
	}

	return array_key_last( $weights );
}

/**
 * A number from a roughly normal distribution, clamped.
 *
 * The average of three uniform draws, which is close enough to a bell for people
 * and much cheaper than a Box-Muller transform. Used for the latent morale that
 * the answers hang off: a uniform draw would give the same number of miserable
 * people as middling ones, and no real population looks like that.
 *
 * @since 0.1.0
 *
 * @param float $mean   Where the middle sits, 0 to 1.
 * @param float $spread How wide it is.
 * @param int   $state  The generator state.
 * @return float A number in [0, 1].
 */
function atf_demo_bell( $mean, $spread, &$state ) {
	$sum = 0;

	for ( $i = 0; $i < 3; $i++ ) {
		$sum += atf_demo_random( $state );
	}

	return max( 0, min( 1, $mean + ( ( $sum / 3 ) - 0.5 ) * 2 * $spread ) );
}

/**
 * How each team feels, and how big it is.
 *
 * The two are separate on purpose. Support is small and unhappy; Engineering is
 * large and middling. A report that only ever sees equal-sized, equally-happy
 * segments will not reveal that its "worst team" panel is really just showing
 * whichever team has three responses.
 *
 * @since 0.1.0
 *
 * @return array Team label => { size, morale, office }.
 */
function atf_demo_teams() {
	return array(
		'Engineering' => array(
			'size'   => 30,
			'morale' => 0.60,
			'office' => 0.25,
		),
		'Design'      => array(
			'size'   => 9,
			'morale' => 0.68,
			'office' => 0.35,
		),
		'Product'     => array(
			'size'   => 11,
			'morale' => 0.63,
			'office' => 0.40,
		),
		'Data'        => array(
			'size'   => 8,
			'morale' => 0.66,
			'office' => 0.30,
		),
		'Support'     => array(
			'size'   => 16,
			'morale' => 0.38,
			'office' => 0.55,
		),
		'Sales'       => array(
			'size'   => 14,
			'morale' => 0.55,
			'office' => 0.70,
		),
		'Operations'  => array(
			'size'   => 12,
			'morale' => 0.52,
			'office' => 0.65,
		),
	);
}

/**
 * The comments people leave, banded by how they feel.
 *
 * Written out rather than assembled from fragments, because "lorem ipsum" in a
 * demo makes every text panel look like it works — nothing to truncate, no
 * awkward line break, no sentence long enough to overflow. These are the length
 * and tone of real survey comments, which is what the panel has to survive.
 *
 * @since 0.1.0
 *
 * @return array Band => list of comments.
 */
function atf_demo_comments() {
	return array(
		'low'    => array(
			'Three reorganisations in eighteen months. Nobody knows who decides anything any more.',
			'I like the people. I have stopped being able to explain what we are building.',
			'Every priority is the top priority, so nothing is.',
			'Handover from Sales is still a spreadsheet emailed on a Friday afternoon.',
			'Asked for a second monitor in March. Still waiting.',
			'The on-call rota has not been rebalanced since two people left.',
			'Honestly I am looking. I did not want to be.',
			'Meetings have eaten Tuesday and Wednesday entirely.',
		),
		'middle' => array(
			'Good quarter overall. The planning process still takes a week longer than it should.',
			'No complaints, but I would not describe the last three months as exciting.',
			'Things are better than they were. Slowly.',
			'Fine. The new starter onboarding is much improved.',
			'I would like more notice when priorities change, that is all.',
			'The office is nice on the days it is not full.',
		),
		'high'   => array(
			'Best quarter I have had here. The team is in a genuinely good place.',
			'Shipping again, and it shows. Everyone is noticeably happier.',
			'My manager is the reason I am still here, and I mean that as a compliment to her.',
			'Whatever changed in how we plan, please keep doing it.',
			'Genuinely enjoyed this one. More of the same.',
			'The four-day trial made a bigger difference than anything else we tried.',
		),
	);
}

/**
 * One respondent's answers.
 *
 * Everything follows from a single latent morale, which is what makes the data
 * worth having: satisfaction and the recommendation score both track it, so a
 * report that cross-tabs them shows a real relationship, and one that has got its
 * grouping wrong shows noise.
 *
 * @since 0.1.0
 *
 * @param int $state The generator state.
 * @return array Field id => value, as a browser would have posted it.
 */
function atf_demo_respondent( &$state ) {
	$teams   = atf_demo_teams();
	$weights = array();

	foreach ( $teams as $name => $team ) {
		$weights[ $name ] = $team['size'];
	}

	$team = atf_demo_weighted( $weights, $state );

	// Longer-serving people are slightly happier here — partly because the
	// unhappy ones left, which is the survivorship the chart should show.
	$tenure = atf_demo_weighted(
		array(
			'Less than a year' => 26,
			'1 to 2 years'     => 32,
			'3 to 5 years'     => 27,
			'More than 5 years' => 15,
		),
		$state
	);

	$tenure_lift = array(
		'Less than a year'  => 0.04,
		'1 to 2 years'      => -0.02,
		'3 to 5 years'      => 0.01,
		'More than 5 years' => 0.06,
	);

	$morale = atf_demo_bell( $teams[ $team ]['morale'] + $tenure_lift[ $tenure ], 0.55, $state );

	// Satisfaction, 1 to 5. Rounded from morale with a little noise, so it is a
	// coarse view of the same thing rather than a copy of it.
	$satisfaction = (int) max( 1, min( 5, round( 1 + $morale * 4 + ( atf_demo_random( $state ) - 0.5 ) * 0.9 ) ) );

	// The recommendation score, 0 to 10.
	//
	// Scaled so the population straddles the NPS bands rather than sitting inside
	// one of them: the unhappiest team lands around 5 and the happiest around 9,
	// which puts real numbers of promoters, passives *and* detractors on the
	// chart. A population that is uniformly one band renders as a single bar and
	// proves nothing about whether the split works.
	//
	// Deliberately not a rescaled satisfaction: people rate their own quarter more
	// kindly than they recommend an employer, and the gap between the two is
	// exactly the sort of thing a cross-tab exists to show.
	$recommend = (int) max( 0, min( 10, round( $morale * 10.3 + 1.6 + ( atf_demo_random( $state ) - 0.5 ) * 2.4 ) ) );

	// Days in the office. Lumpy on purpose — nobody works 2.5 days, and the modes
	// at 0 and 5 are what a histogram has to render without pretending it is a
	// bell curve.
	//
	// The team's bias is raised to a power rather than used directly, because a
	// linear weighting produced means of 1.9 and 2.4 for teams meant to be
	// obviously different — a real effect that no chart would ever show.
	$office_bias = $teams[ $team ]['office'];
	$office      = atf_demo_weighted(
		array(
			'0' => 38 * pow( 1 - $office_bias, 1.6 ),
			'1' => 14,
			'2' => 18,
			'3' => 18,
			'4' => 12,
			'5' => 38 * pow( $office_bias, 1.6 ),
		),
		$state
	);

	// What would improve the week. Unhappy people ask for decisions and clarity;
	// happier people ask for equipment and hours. Everyone can pick several, so
	// these percentages sum well past a hundred.
	$wants = array(
		'Clearer priorities'      => 0.62 - $morale * 0.45,
		'Faster decisions'        => 0.54 - $morale * 0.38,
		'Fewer meetings'          => 0.46 - $morale * 0.16,
		'Better tooling'          => 0.30 + $morale * 0.05,
		'More flexible hours'     => 0.18 + $morale * 0.22,
		'A quieter place to work' => 0.14 + $morale * 0.10,
		'More time off'           => 0.24 + $morale * 0.04,
	);

	$improve = array();

	foreach ( $wants as $option => $chance ) {
		if ( atf_demo_random( $state ) < $chance ) {
			$improve[] = $option;
		}
	}

	// A comment is likelier at both ends: people write when they are delighted or
	// fed up, and say nothing in the middle. A response rate that is flat across
	// the population is the one thing a free-text panel should never assume.
	$strength = abs( $morale - 0.5 ) * 2;
	$comment  = '';

	if ( atf_demo_random( $state ) < 0.18 + $strength * 0.42 ) {
		$comments = atf_demo_comments();
		$band     = 'middle';

		if ( $morale < 0.35 ) {
			$band = 'low';
		} elseif ( $morale > 0.68 ) {
			$band = 'high';
		}

		$pool    = $comments[ $band ];
		$comment = $pool[ (int) floor( atf_demo_random( $state ) * count( $pool ) ) ];
	}

	return array(
		'team'         => $team,
		'tenure'       => $tenure,
		'office_days'  => (string) $office,
		'recommend'    => (string) $recommend,
		'satisfaction' => (string) $satisfaction,
		'improve'      => $improve,
		'comment'      => $comment,
	);
}

/**
 * When a respondent answered.
 *
 * Spread over the last twelve weeks, with the shape a survey actually has: a
 * spike when it is announced, a long tail, a second bump when somebody sends the
 * reminder, and almost nothing at weekends. A timeline chart drawn against dates
 * scattered uniformly looks like static and proves nothing.
 *
 * @since 0.1.0
 *
 * @param int $state The generator state.
 * @return int A Unix timestamp.
 */
function atf_demo_answered_at( &$state ) {
	$days = 84;

	// The shape is a survey's, not a website's: a spike when it goes out, a long
	// decay, and a second bump when the reminder lands. Since the offset counts
	// *backwards* from today, the spike belongs at the far end — compressing the
	// draw towards zero instead put the busiest day today and the quiet tail
	// twelve weeks ago, which is the shape of a survey nobody has sent yet.
	if ( atf_demo_random( $state ) < 0.17 ) {
		$offset = 40 + atf_demo_random( $state ) * 6;
	} else {
		$offset = $days - pow( atf_demo_random( $state ), 2.1 ) * $days;
	}

	$when = time() - (int) round( $offset * DAY_IN_SECONDS );

	// Nudge weekends onto the Friday before. Not skipped entirely — a few people
	// really do answer on a Sunday — so the chart has small weekend bars rather
	// than gaps, which is the harder thing to render.
	$weekday = (int) gmdate( 'N', $when );

	if ( $weekday >= 6 && atf_demo_random( $state ) < 0.8 ) {
		$when -= ( $weekday - 5 ) * DAY_IN_SECONDS;
	}

	// Office hours, roughly, with a lunchtime lull.
	$hour = (int) atf_demo_weighted(
		array(
			'8'  => 6,
			'9'  => 14,
			'10' => 17,
			'11' => 15,
			'12' => 7,
			'13' => 8,
			'14' => 13,
			'15' => 12,
			'16' => 10,
			'17' => 7,
			'20' => 3,
		),
		$state
	);

	return (int) ( floor( $when / DAY_IN_SECONDS ) * DAY_IN_SECONDS )
		+ $hour * HOUR_IN_SECONDS
		+ (int) ( atf_demo_random( $state ) * 3600 );
}

/**
 * The demo form, if there is one.
 *
 * @since 0.1.0
 *
 * @return int The form id, or 0.
 */
function atf_demo_form_id() {
	$found = get_posts(
		array(
			'post_type'        => ATF_FORM_TYPE,
			'post_status'      => 'any',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'meta_query'       => array(
				array(
					'key'     => ATF_META_DEMO,
					'compare' => 'EXISTS',
				),
			),
		)
	);

	return $found ? (int) $found[0] : 0;
}

/**
 * What demo data exists right now.
 *
 * @since 0.1.0
 *
 * @return array { formId, title, entries, target, remaining }.
 */
function atf_demo_status() {
	$form_id = atf_demo_form_id();

	if ( ! $form_id ) {
		return array(
			'formId'    => 0,
			'title'     => '',
			'entries'   => 0,
			'target'    => ATF_DEMO_TARGET,
			'remaining' => ATF_DEMO_TARGET,
		);
	}

	$entries = atf_demo_entry_count();
	$target  = (int) get_post_meta( $form_id, ATF_META_DEMO . '_target', true );
	$target  = $target > 0 ? $target : ATF_DEMO_TARGET;

	return array(
		'formId'    => $form_id,
		'title'     => get_the_title( $form_id ),
		'entries'   => $entries,
		'target'    => $target,
		'remaining' => max( 0, $target - $entries ),
	);
}

/**
 * How many generated entries exist.
 *
 * Counted across every status, spam included, because the point of the number is
 * "how much of this is mine to clean up" rather than "how many are readable".
 *
 * @since 0.1.0
 *
 * @return int
 */
function atf_demo_entry_count() {
	$query = new WP_Query(
		array(
			'post_type'      => ATF_ENTRY_TYPE,
			'post_status'    => atf_entry_statuses(),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => ATF_META_DEMO,
					'compare' => 'EXISTS',
				),
			),
		)
	);

	return (int) $query->found_posts;
}

/**
 * Creates the demo survey, or returns the one that is already there.
 *
 * @since 0.1.0
 *
 * @param int $seed The generator seed, stored so a batch can be repeated.
 * @return int|WP_Error The form id.
 */
function atf_demo_create_form( $seed ) {
	$existing = atf_demo_form_id();

	if ( $existing ) {
		return $existing;
	}

	$form_id = wp_insert_post(
		array(
			'post_type'   => ATF_FORM_TYPE,
			'post_title'  => __( 'Team pulse survey (demo data)', 'allterrain-forms' ),
			'post_status' => 'publish',
			'post_author' => get_current_user_id(),
		),
		true
	);

	if ( is_wp_error( $form_id ) ) {
		return $form_id;
	}

	atf_save_form_schema( $form_id, atf_demo_survey_schema() );

	update_post_meta( $form_id, ATF_META_DEMO, absint( $seed ) );
	update_post_meta( $form_id, ATF_META_DEMO . '_target', ATF_DEMO_TARGET );

	return (int) $form_id;
}

/**
 * Generates a chunk of submissions.
 *
 * Called repeatedly until nothing is left, because five hundred passes through
 * the submission pipeline is more than one request should attempt.
 *
 * @since 0.1.0
 *
 * @param int $count How many to make, at most.
 * @return array|WP_Error The status afterwards.
 */
function atf_demo_seed( $count = ATF_DEMO_CHUNK ) {
	if ( ! atf_can_use_developer_tools() ) {
		return new WP_Error(
			'atf_forbidden',
			__( 'Demo data needs developer mode and permission to edit forms.', 'allterrain-forms' ),
			array( 'status' => 403 )
		);
	}

	$seed    = 0x5EED1234;
	$form_id = atf_demo_create_form( $seed );

	if ( is_wp_error( $form_id ) ) {
		return $form_id;
	}

	$status = atf_demo_status();
	$count  = max( 1, min( (int) $count, ATF_DEMO_CHUNK, $status['remaining'] ) );

	if ( $status['remaining'] < 1 ) {
		return $status;
	}

	// The generator's state is carried between chunks rather than re-derived
	// from the seed, and that is the difference between a survey and one group of
	// people repeated ten times: restarting from the seed each call would make
	// every chunk generate the *same* fifty respondents.
	//
	// Replaying the seed forward past the people already made would work too, and
	// would do it in time quadratic in the batch size for no benefit.
	$state = (int) get_post_meta( $form_id, ATF_META_DEMO . '_state', true );
	$state = $state > 0 ? $state : $seed;

	$made = 0;

	// Notifications are off in the schema, but an action or a filter added by
	// something else on the site could still try to send mail five hundred times.
	// This is belt and braces around somebody else's plugin, not around ours.
	add_filter( 'pre_wp_mail', '__return_false', 99 );

	// Every post WordPress touches is added to the in-memory object cache and
	// nothing in a single request ever evicts it. Fifty submissions — each one an
	// entry, its meta, the form and its schema — exhausted a 128MB limit partway
	// through a batch, and the failure was a bare 500 with the entries already
	// written, which is the worst shape a batch job can fail in.
	//
	// Suspending additions is the documented tool for exactly this and is what
	// Core's own importer does. It is not `wp_cache_flush()`: this site may have a
	// persistent object cache, and emptying the whole site's cache to seed a demo
	// form would be a rude way to fix a memory leak.
	$suspended = wp_suspend_cache_addition();

	wp_suspend_cache_addition( true );

	global $wpdb;

	for ( $i = 0; $i < $count; $i++ ) {
		$answers = atf_demo_respondent( $state );
		$when    = atf_demo_answered_at( $state );

		$entry_id = atf_demo_submit( $form_id, $answers, $when, $state );

		if ( ! is_wp_error( $entry_id ) ) {
			++$made;
		}

		// Under `SAVEQUERIES` — which is on whenever `WP_DEBUG` is, so on every
		// developer's machine, which is the only place this runs — `$wpdb` keeps
		// every query *and a backtrace for each*. Across a batch that is the
		// single largest thing in memory, and it exists to be read after a page
		// load that in this case never ends.
		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			$wpdb->queries = array();
		}
	}

	wp_suspend_cache_addition( $suspended );

	remove_filter( 'pre_wp_mail', '__return_false', 99 );

	update_post_meta( $form_id, ATF_META_DEMO . '_state', $state );

	// Views and starts, so the conversion and completion rates are numbers rather
	// than zeroes. Roughly two in five people who saw it answered, and four in
	// five who began finished — both plausible for an internal survey, and both
	// visibly *not* 100%, which is what makes the panel worth reading.
	atf_bump_stat( $form_id, 'views', (int) round( $made / 0.42 ) );
	atf_bump_stat( $form_id, 'starts', (int) round( $made / 0.78 ) );

	return atf_demo_status();
}

/**
 * Posts one demo submission through the real pipeline.
 *
 * @since 0.1.0
 *
 * @param int   $form_id The demo form.
 * @param array $answers Field id => value.
 * @param int   $when    When it was submitted.
 * @param int   $state   The generator state.
 * @return int|WP_Error The entry id.
 */
function atf_demo_submit( $form_id, $answers, $when, &$state ) {
	// Dated far enough back to clear the time trap honestly, and signed with the
	// same function the rendered form uses. The screening is not bypassed — the
	// request simply is what a real one looks like.
	$issued = time() - 90;

	$request = array(
		'atf_form_id' => $form_id,
		'atf_t'       => $issued,
		'atf_ts'      => atf_sign_timestamp( $form_id, $issued ),
		'atf_website' => '',
		'atf'         => $answers,
	);

	$result = atf_process_submission( $form_id, $request );

	if ( empty( $result['success'] ) || empty( $result['entry_id'] ) ) {
		return new WP_Error( 'atf_demo_failed', __( 'A demo submission was rejected.', 'allterrain-forms' ) );
	}

	$entry_id = (int) $result['entry_id'];

	update_post_meta( $entry_id, ATF_META_DEMO, 1 );

	// Backdated after the fact rather than before: the pipeline stamps an entry
	// with the moment it was stored, which is correct for every real submission
	// and is the one thing about a generated one that has to be a lie.
	wp_update_post(
		array(
			'ID'            => $entry_id,
			'post_date'     => gmdate( 'Y-m-d H:i:s', $when + ( (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) ),
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $when ),
			'edit_date'     => true,
		)
	);

	// A realistic spread of what has been looked at. Roughly a third still unread,
	// a handful starred, and a couple of percent caught as spam — so the status
	// filters and badges have something to show that is not all one value.
	$roll = atf_demo_random( $state );

	if ( $roll < 0.02 ) {
		atf_set_entry_status( $entry_id, ATF_STATUS_SPAM );
	} elseif ( $roll < 0.68 ) {
		atf_set_entry_status( $entry_id, ATF_STATUS_READ );
	}

	if ( atf_demo_random( $state ) < 0.06 ) {
		atf_star_entry( $entry_id, true );
	}

	return $entry_id;
}

/**
 * Deletes everything this generated.
 *
 * Matches on the marker and nothing else. Deleting "every entry on the demo form"
 * would be easier and would take a real submission with it the moment somebody
 * used the demo form to try something out — which, since it is a working form
 * with a working shortcode, is exactly what will happen.
 *
 * @since 0.1.0
 *
 * @return array|WP_Error { entries, forms }.
 */
function atf_demo_remove() {
	if ( ! atf_can_use_developer_tools() ) {
		return new WP_Error(
			'atf_forbidden',
			__( 'Demo data needs developer mode and permission to edit forms.', 'allterrain-forms' ),
			array( 'status' => 403 )
		);
	}

	$removed = 0;

	// In pages, because the whole point is that there are hundreds and a single
	// unbounded query over every entry on a busy site is how a removal becomes a
	// timeout that leaves half the data behind.
	do {
		$batch = get_posts(
			array(
				'post_type'      => ATF_ENTRY_TYPE,
				'post_status'    => atf_entry_statuses(),
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => ATF_META_DEMO,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$found = count( $batch );

		foreach ( $batch as $entry_id ) {
			atf_delete_entry_completely( $entry_id );
			++$removed;
		}
	} while ( $found >= 100 );

	$forms = get_posts(
		array(
			'post_type'      => ATF_FORM_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'     => ATF_META_DEMO,
					'compare' => 'EXISTS',
				),
			),
		)
	);

	foreach ( $forms as $demo_form ) {
		wp_delete_post( $demo_form, true );
	}

	return array(
		'entries' => $removed,
		'forms'   => count( $forms ),
	);
}

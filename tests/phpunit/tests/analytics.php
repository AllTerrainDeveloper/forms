<?php
/**
 * The statistics behind a report.
 *
 * These are pure functions over arrays of numbers, and they are tested that way
 * rather than through a rendered report, because the failures that matter here
 * are arithmetic and would survive any amount of end-to-end checking that only
 * asserts a chart appeared.
 *
 * The one worth stating outright: **NPS is not an average.** It is the percentage
 * of promoters minus the percentage of detractors, the passives in the middle
 * count for nothing, and the number that comes out is on a different scale
 * entirely. A mean of the same answers is a plausible-looking number that moves
 * differently, and reporting it as NPS makes every benchmark anybody compares it
 * against meaningless.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * The statistics behind a report.
 *
 * @group allterrain-forms
 */
class ATF_Test_Analytics extends WP_UnitTestCase {

	/**
	 * Nothing to summarise is null, not a zero.
	 *
	 * A mean of 0 for a question nobody answered would draw a bar at the bottom of
	 * the chart, which reads as "everybody chose the lowest option".
	 *
	 * @covers ::atf_analytics_numbers
	 * @covers ::atf_analytics_nps
	 */
	public function test_no_answers_summarise_to_nothing() {
		$this->assertNull( atf_analytics_numbers( array() ) );
		$this->assertNull( atf_analytics_nps( array() ) );
		$this->assertNull( atf_analytics_numbers( array( 'x', '', null ) ) );
	}

	/**
	 * The mean, and the median that stops it lying.
	 *
	 * @covers ::atf_analytics_numbers
	 */
	public function test_summary_of_numbers() {
		$summary = atf_analytics_numbers( array( 1, 2, 2, 3, 10 ) );

		$this->assertSame( 5, $summary['count'] );
		$this->assertEqualsWithDelta( 3.6, $summary['mean'], 0.001 );
		$this->assertEqualsWithDelta( 2.0, $summary['median'], 0.001, 'The outlier must not drag the median.' );
		$this->assertEqualsWithDelta( 1.0, $summary['min'], 0.001 );
		$this->assertEqualsWithDelta( 10.0, $summary['max'], 0.001 );
	}

	/**
	 * An even number of answers averages the middle pair.
	 *
	 * The off-by-one that a median written in a hurry always has.
	 *
	 * @covers ::atf_analytics_numbers
	 */
	public function test_median_of_an_even_count() {
		$summary = atf_analytics_numbers( array( 1, 2, 3, 4 ) );

		$this->assertEqualsWithDelta( 2.5, $summary['median'], 0.001 );
	}

	/**
	 * The distribution, which is the reason a mean is not enough.
	 *
	 * Everybody answering 3, and half answering 1 with half answering 5, are
	 * opposite findings with the same mean.
	 *
	 * @covers ::atf_analytics_numbers
	 */
	public function test_distribution_separates_agreement_from_a_split() {
		$agreed = atf_analytics_numbers( array( 3, 3, 3, 3 ) );
		$split  = atf_analytics_numbers( array( 1, 1, 5, 5 ) );

		$this->assertSame( $agreed['mean'], $split['mean'], 'The premise: identical means.' );

		$this->assertSame( array( '3' => 4 ), $agreed['distribution'] );
		$this->assertSame(
			array(
				'1' => 2,
				'5' => 2,
			),
			$split['distribution']
		);
	}

	/**
	 * NPS is promoters minus detractors, as percentages.
	 *
	 * @dataProvider data_nps
	 * @covers ::atf_analytics_nps
	 *
	 * @param array $answers  Scores, 0 to 10.
	 * @param int   $expected The score.
	 */
	public function test_nps( $answers, $expected ) {
		$this->assertSame( $expected, atf_analytics_nps( $answers )['score'] );
	}

	/**
	 * Cases chosen so an implementation that averages fails every one.
	 *
	 * @return array[]
	 */
	public function data_nps() {
		return array(
			'everybody promotes'       => array( array( 10, 10, 9, 9 ), 100 ),
			'everybody detracts'       => array( array( 0, 3, 6, 6 ), -100 ),
			// The case that catches a mean: four sevens and four eights average to
			// 7.5, which looks like a healthy score. As an NPS it is exactly zero,
			// because nobody would recommend it and nobody would warn you off.
			'all passive'              => array( array( 7, 7, 7, 8, 8, 8 ), 0 ),
			'evenly split'             => array( array( 10, 10, 0, 0 ), 0 ),
			'one in four promotes'     => array( array( 9, 8, 8, 8 ), 25 ),
			'passives dilute the rest' => array( array( 9, 7, 7, 0 ), 0 ),
		);
	}

	/**
	 * The three bands are counted at the right boundaries.
	 *
	 * 6 and 7 are the boundary everyone gets wrong: 6 is a detractor and 7 is not.
	 *
	 * @covers ::atf_analytics_nps
	 */
	public function test_nps_bands() {
		$nps = atf_analytics_nps( array( 0, 6, 7, 8, 9, 10 ) );

		$this->assertSame( 2, $nps['detractors'], '0 and 6.' );
		$this->assertSame( 2, $nps['passives'], '7 and 8.' );
		$this->assertSame( 2, $nps['promoters'], '9 and 10.' );
		$this->assertSame( 6, $nps['responses'] );
	}

	/**
	 * A timeline includes the days nobody answered.
	 *
	 * The sparse-series bug: keep only the days that had a submission and the gaps
	 * close up, so a quiet fortnight renders the same width as a busy one and the
	 * chart shows a steady trickle where the truth was one spike and silence.
	 *
	 * @covers ::atf_analytics_timeline
	 */
	public function test_timeline_keeps_the_empty_days() {
		$rows = array(
			array(
				'values' => array(),
				'time'   => time(),
			),
			array(
				'values' => array(),
				'time'   => time() - 4 * DAY_IN_SECONDS,
			),
		);

		$timeline = atf_analytics_timeline( $rows, 7 );

		$this->assertCount( 7, $timeline );
		$this->assertSame( 2, array_sum( wp_list_pluck( $timeline, 'count' ) ) );

		// Oldest first, so a chart drawn left to right reads forwards in time.
		$dates = wp_list_pluck( $timeline, 'date' );
		$sorted = $dates;
		sort( $sorted );

		$this->assertSame( $sorted, $dates );
	}

	/**
	 * Anything older than the window is left out rather than piled onto day one.
	 *
	 * @covers ::atf_analytics_timeline
	 */
	public function test_timeline_ignores_what_is_off_the_end() {
		$rows = array(
			array(
				'values' => array(),
				'time'   => time() - 400 * DAY_IN_SECONDS,
			),
		);

		$this->assertSame( 0, array_sum( wp_list_pluck( atf_analytics_timeline( $rows, 7 ), 'count' ) ) );
	}

	/**
	 * A 0–10 scale is recognised as an NPS question; other scales are not.
	 *
	 * @covers ::atf_analytics_is_nps_field
	 */
	public function test_nps_field_is_recognised_by_its_shape() {
		$this->assertTrue(
			atf_analytics_is_nps_field(
				array(
					'type' => 'scale',
					'min'  => 0,
					'max'  => 10,
				)
			)
		);

		$this->assertFalse(
			atf_analytics_is_nps_field(
				array(
					'type' => 'scale',
					'min'  => 1,
					'max'  => 5,
				)
			),
			'A 1-5 scale is a rating, and NPS bands would be nonsense on it.'
		);

		$this->assertFalse( atf_analytics_is_nps_field( array( 'type' => 'rating' ) ) );
	}

	/**
	 * Only fields with a readable number of options can be grouped by.
	 *
	 * @covers ::atf_analytics_dimensions
	 */
	public function test_dimensions_are_the_categorical_fields() {
		$schema = atf_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'      => 'team',
						'type'    => 'select',
						'label'   => 'Team',
						'choices' => array( 'A', 'B', 'C' ),
					),
					array(
						'id'    => 'name',
						'type'  => 'text',
						'label' => 'Name',
					),
					array(
						'id'    => 'score',
						'type'  => 'scale',
						'label' => 'Score',
					),
				),
			)
		);

		$this->assertSame( array( 'team' ), wp_list_pluck( atf_analytics_dimensions( $schema ), 'id' ) );
	}

	/**
	 * The cross-tab: numbers grouped by an answer.
	 *
	 * @covers ::atf_analytics_breakdown
	 */
	public function test_breakdown_groups_numbers_by_a_choice() {
		$schema = $this->survey_schema();

		$rows = array(
			$this->row( 'a', 10 ),
			$this->row( 'a', 8 ),
			$this->row( 'b', 0 ),
			$this->row( 'b', 2 ),
		);

		$breakdown = atf_analytics_breakdown( $rows, $schema, 'team' );

		$this->assertSame( 'team', $breakdown['id'] );
		$this->assertCount( 3, $breakdown['groups'], 'Every choice, including the one nobody picked.' );

		$groups = array();

		foreach ( $breakdown['groups'] as $group ) {
			$groups[ $group['value'] ] = $group;
		}

		$this->assertSame( 2, $groups['a']['count'] );
		$this->assertEqualsWithDelta( 9.0, $groups['a']['metrics'][0]['mean'], 0.001 );
		$this->assertEqualsWithDelta( 1.0, $groups['b']['metrics'][0]['mean'], 0.001 );

		// The whole point of the panel: the two groups are not the same, and the
		// NPS makes it stark in a way the means do not.
		//
		// Group A is a 10 and an 8, which is +50 rather than +100 — one promoter
		// and one passive out of two. Asserted at 50 deliberately: a breakdown that
		// forgot the passive band would say 100 here and look entirely plausible.
		$this->assertSame( 50, $groups['a']['metrics'][0]['nps'] );
		$this->assertSame( -100, $groups['b']['metrics'][0]['nps'] );
	}

	/**
	 * A group nobody picked is kept, at zero.
	 *
	 * "Nobody on the leadership team answered" is a finding. Dropping the row
	 * turns it into an absence nobody notices.
	 *
	 * @covers ::atf_analytics_breakdown
	 */
	public function test_breakdown_keeps_an_empty_group() {
		$breakdown = atf_analytics_breakdown( array( $this->row( 'a', 9 ) ), $this->survey_schema(), 'team' );

		$empty = null;

		foreach ( $breakdown['groups'] as $group ) {
			if ( 'c' === $group['value'] ) {
				$empty = $group;
			}
		}

		$this->assertNotNull( $empty, 'The unpicked choice must still be a row.' );
		$this->assertSame( 0, $empty['count'] );
		$this->assertSame( array(), $empty['metrics'] );
	}

	/**
	 * Grouping by a field that is not there is empty rather than fatal.
	 *
	 * Reachable from the URL, so it has to be an answer rather than a crash.
	 *
	 * @covers ::atf_analytics_breakdown
	 */
	public function test_breakdown_of_an_unknown_field() {
		$breakdown = atf_analytics_breakdown( array( $this->row( 'a', 9 ) ), $this->survey_schema(), 'nope' );

		$this->assertSame( array(), $breakdown['groups'] );
	}

	/**
	 * A whole report, over real stored entries.
	 *
	 * The pure functions above are tested in isolation; this is the one that says
	 * they are wired to the right data.
	 *
	 * @covers ::atf_form_analytics
	 */
	public function test_report_over_real_entries() {
		$form_id = atf_test_form( $this->survey_schema() );

		foreach ( array( array( 'a', 10 ), array( 'a', 9 ), array( 'b', 1 ) ) as $answer ) {
			atf_store_entry(
				$form_id,
				atf_get_form_schema( $form_id ),
				array(
					'team'  => $answer[0],
					'score' => (string) $answer[1],
				)
			);
		}

		$report = atf_form_analytics( $form_id );

		$this->assertSame( 3, $report['sampled'] );
		$this->assertNotEmpty( $report['timeline'] );
		$this->assertSame( 'team', $report['breakdown']['id'], 'The first suitable field is grouped by.' );

		$score = null;

		foreach ( $report['fields'] as $field ) {
			if ( 'score' === $field['id'] ) {
				$score = $field;
			}
		}

		$this->assertNotNull( $score );
		$this->assertSame( 33, $score['nps']['score'], 'Two promoters and one detractor out of three.' );
		$this->assertEqualsWithDelta( 6.67, $score['numbers']['mean'], 0.01 );
	}

	/**
	 * A form with no submissions reports zeroes rather than failing.
	 *
	 * @covers ::atf_form_analytics
	 */
	public function test_report_of_an_empty_form() {
		$report = atf_form_analytics( atf_test_form( $this->survey_schema() ) );

		$this->assertSame( 0, $report['sampled'] );
		$this->assertSame( 0, $report['submissions'] );
		$this->assertCount( 90, $report['timeline'], 'A timeline of empty days is still a timeline.' );
	}

	/**
	 * A survey with one grouping field and one 0–10 score.
	 *
	 * @return array
	 */
	private function survey_schema() {
		return atf_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'      => 'team',
						'type'    => 'select',
						'label'   => 'Team',
						'choices' => array(
							array(
								'label' => 'Alpha',
								'value' => 'a',
							),
							array(
								'label' => 'Beta',
								'value' => 'b',
							),
							array(
								'label' => 'Gamma',
								'value' => 'c',
							),
						),
					),
					array(
						'id'    => 'score',
						'type'  => 'scale',
						'label' => 'Score',
						'min'   => 0,
						'max'   => 10,
					),
				),
			)
		);
	}

	/**
	 * One sampled row.
	 *
	 * @param string $team  The team's stored value.
	 * @param int    $score The score.
	 * @return array
	 */
	private function row( $team, $score ) {
		return array(
			'values' => array(
				'team'  => $team,
				'score' => $score,
			),
			'time'   => time(),
		);
	}
}

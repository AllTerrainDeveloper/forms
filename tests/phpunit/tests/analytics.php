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
		$dates  = wp_list_pluck( $timeline, 'date' );
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

	/**
	 * A repeater's sub-fields report as questions of their own.
	 *
	 * @covers ::atf_repeater_report_rows
	 */
	public function test_repeater_subfields_report_across_rows() {
		$field = atf_normalize_field(
			array(
				'id'     => 'att',
				'type'   => 'repeater',
				'label'  => 'Attendees',
				'fields' => array(
					array(
						'id'    => 'age',
						'type'  => 'number',
						'label' => 'Age',
					),
					array(
						'id'      => 'meal',
						'type'    => 'select',
						'label'   => 'Meal',
						'choices' => array(
							array(
								'label' => 'Steak',
								'value' => 'steak',
							),
							array(
								'label' => 'Veg',
								'value' => 'veg',
							),
						),
					),
				),
			)
		);

		$sample = array(
			'sampled' => 2,
			'rows'    => array(
				array(
					'values' => array(
						'att' => array(
							array(
								'age'  => '30',
								'meal' => 'steak',
							),
							array(
								'age'  => '12',
								'meal' => 'veg',
							),
						),
					),
					'time'   => time(),
				),
				array(
					'values' => array(
						'att' => array(
							array(
								'age'  => '45',
								'meal' => 'steak',
							),
						),
					),
					'time'   => time(),
				),
			),
		);

		$rows = atf_repeater_report_rows( $field, $sample );

		$this->assertCount( 2, $rows );

		$age = $rows[0];
		$this->assertSame( 'att.age', $age['id'] );
		$this->assertSame( 'Attendees · Age', $age['label'] );
		$this->assertSame( 3, $age['answered'], 'Three rows answered the age.' );
		$this->assertSame( 3, $age['of'] );
		$this->assertSame( 100, $age['rate'] );
		$this->assertSame( 3, $age['numbers']['count'] );
		$this->assertSame( 29.0, $age['numbers']['mean'] );

		$meal = $rows[1];
		$this->assertSame( 'att.meal', $meal['id'] );
		$this->assertSame( 2, $meal['choices'][0]['count'], 'Steak was picked in two rows.' );
		$this->assertSame( 1, $meal['choices'][1]['count'] );
	}

	/**
	 * A sub-field skipped in some rows reports an honest per-row rate.
	 *
	 * @covers ::atf_repeater_report_rows
	 */
	public function test_repeater_subfield_rate_is_per_row() {
		$field = atf_normalize_field(
			array(
				'id'     => 'att',
				'type'   => 'repeater',
				'fields' => array(
					array(
						'id'   => 'age',
						'type' => 'number',
					),
					array(
						'id'   => 'name',
						'type' => 'text',
					),
				),
			)
		);

		$sample = array(
			'sampled' => 1,
			'rows'    => array(
				array(
					'values' => array(
						'att' => array(
							array(
								'age'  => '8',
								'name' => 'Ana',
							),
							array(
								'age'  => '',
								'name' => 'Luz',
							),
						),
					),
					'time'   => time(),
				),
			),
		);

		$rows = atf_repeater_report_rows( $field, $sample );

		$this->assertSame( 1, $rows[0]['answered'] );
		$this->assertSame( 2, $rows[0]['of'] );
		$this->assertSame( 50, $rows[0]['rate'] );
	}
	/**
	 * The user-agent classifier sorts real strings into the right boxes.
	 *
	 * The strings here are the awkward ones: every Chromium browser says
	 * "Chrome", every WebKit browser says "Safari", an iPad says "like Mac OS
	 * X", and Android puts "Mobile" on phones but not tablets.
	 *
	 * @covers ::atf_classify_user_agent
	 */
	public function test_user_agents_classify_coarsely() {
		$cases = array(
			'Chrome on Windows is not Safari'    => array(
				'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
				array( 'desktop', 'chrome', 'windows' ),
			),
			'Edge is not Chrome'                 => array(
				'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 Edg/126.0.0.0',
				array( 'desktop', 'edge', 'windows' ),
			),
			'An iPhone is a phone on iOS'        => array(
				'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
				array( 'mobile', 'safari', 'ios' ),
			),
			'An iPad is a tablet, not a Mac'     => array(
				'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
				array( 'tablet', 'safari', 'ios' ),
			),
			'Android with Mobile is a phone'     => array(
				'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36',
				array( 'mobile', 'chrome', 'android' ),
			),
			'Android without Mobile is a tablet' => array(
				'Mozilla/5.0 (Linux; Android 14; SM-X910) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
				array( 'tablet', 'chrome', 'android' ),
			),
			'Samsung Internet is not Chrome'     => array(
				'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36',
				array( 'mobile', 'samsung', 'android' ),
			),
			'Firefox on Linux'                   => array(
				'Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0',
				array( 'desktop', 'firefox', 'linux' ),
			),
			'Safari on a Mac is macOS'           => array(
				'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
				array( 'desktop', 'safari', 'macos' ),
			),
			'Nothing classifies as unknown'      => array(
				'',
				array( 'unknown', 'unknown', 'unknown' ),
			),
		);

		foreach ( $cases as $label => $case ) {
			$this->assertSame(
				array(
					'device'  => $case[1][0],
					'browser' => $case[1][1],
					'os'      => $case[1][2],
				),
				atf_classify_user_agent( $case[0] ),
				$label
			);
		}
	}

	/**
	 * Tech tallies accumulate and come back out as ranked report rows.
	 *
	 * @covers ::atf_bump_tech
	 * @covers ::atf_get_tech_stats
	 * @covers ::atf_analytics_tech
	 */
	public function test_tech_tallies_become_report_rows() {
		$form_id = atf_test_form();

		$iphone  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
		$windows = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

		atf_bump_tech( $form_id, 'views', $iphone, 10 );
		atf_bump_tech( $form_id, 'views', $windows, 10 );
		atf_bump_tech( $form_id, 'submissions', $iphone, 2 );
		atf_bump_tech( $form_id, 'submissions', $windows, 8 );

		$tech = atf_analytics_tech( $form_id );

		$this->assertNotNull( $tech );

		$device = $tech['device'];

		$this->assertSame( 'desktop', $device[0]['id'], 'Ranked by submissions: desktop first.' );
		$this->assertSame( 80, $device[0]['share'] );
		$this->assertSame( 80, $device[0]['conversion'] );
		$this->assertSame( 'mobile', $device[1]['id'] );
		$this->assertSame( 20, $device[1]['conversion'], 'Phones viewed as much and converted a quarter as well.' );

		$browsers = wp_list_pluck( $tech['browser'], 'id' );

		$this->assertSame( array( 'chrome', 'safari' ), $browsers );
	}

	/**
	 * A form that has tallied nothing reports no tech section at all.
	 *
	 * @covers ::atf_analytics_tech
	 */
	public function test_no_tallies_is_null_not_zeroes() {
		$this->assertNull( atf_analytics_tech( atf_test_form() ) );
	}

	/**
	 * Switching analytics off stops the counting.
	 *
	 * The visitor-facing behaviour of the privacy switches: `enabled` off
	 * means no views, no starts and no tech; `tech` off alone keeps the
	 * counters and drops only the technology tally.
	 *
	 * @covers ::atf_record_view
	 * @covers ::atf_record_start
	 * @covers ::atf_should_record_tech
	 */
	public function test_analytics_switches_gate_the_recording() {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

		$off = atf_test_form(
			array(
				'settings' => array(
					'analytics' => array(
						'enabled' => false,
						'tech'    => true,
					),
				),
			)
		);

		atf_record_view( $off );
		atf_record_start( $off );

		$stats = atf_get_stats( $off );

		$this->assertSame( 0, $stats['views'] );
		$this->assertSame( 0, $stats['starts'] );
		$this->assertNull( atf_analytics_tech( $off ) );

		$no_tech = atf_test_form(
			array(
				'settings' => array(
					'analytics' => array(
						'enabled' => true,
						'tech'    => false,
					),
				),
			)
		);

		atf_record_view( $no_tech );

		$this->assertSame( 1, atf_get_stats( $no_tech )['views'] );
		$this->assertNull( atf_analytics_tech( $no_tech ), 'Views counted, technology not tallied.' );

		$on = atf_test_form();

		atf_record_view( $on );

		$tech = atf_analytics_tech( $on );

		$this->assertNotNull( $tech );
		$this->assertSame( 'desktop', $tech['device'][0]['id'] );

		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	/**
	 * The tally filter is the per-request veto a consent plugin needs.
	 *
	 * @covers ::atf_should_record_tech
	 */
	public function test_the_tech_filter_can_veto() {
		$form_id = atf_test_form();

		add_filter( 'atf_record_tech', '__return_false' );

		$this->assertFalse( atf_should_record_tech( $form_id ) );

		remove_filter( 'atf_record_tech', '__return_false' );

		$this->assertTrue( atf_should_record_tech( $form_id ) );
	}
}

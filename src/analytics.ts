/**
 * The analytics window.
 *
 * A form's report: how many people saw it, how many finished it, what they
 * answered, and — the part that makes it worth opening twice — how the answers
 * differ between groups of people.
 *
 * # Why the charts are hand-drawn
 *
 * There is no charting library here and there is not going to be one. This
 * plugin ships as a WordPress.org zip, so every byte is downloaded by every site
 * that installs it, and a chart library would be larger than the rest of the
 * bundle put together — arriving with its own opinions about fonts, colours and
 * dark mode that would then have to be argued out of it, while the shell already
 * publishes a palette everything else here obeys.
 *
 * Every chart is therefore ordinary elements sized in percentages, and not one
 * of them is a canvas or an SVG path. That is not only about bytes: a bar chart
 * *is* a list of labelled quantities, and built as a list it can be read aloud,
 * selected, zoomed and searched, none of which a painted chart can do without
 * being described all over again in an ARIA attribute somebody has to remember
 * to update. Percentage widths also mean the window resizing is a reflow rather
 * than a redraw.
 *
 * # What is drawn, and what is deliberately not
 *
 * Choice questions get tallies. Numeric questions get a distribution, because a
 * mean of 3 can be everybody answering 3 or half answering 1 and half answering
 * 5, and those are opposite findings. A 0–10 question gets an NPS panel, since
 * that is what a 0–10 question is.
 *
 * Free text gets a response rate and nothing else. A word cloud from a few
 * hundred survey comments is decoration that reliably surfaces "the", and
 * summarising opinions is not something a bar chart should be trusted to do.
 */

import { api, runtime } from './api';
import { takeFormFor } from './handoff';
import { button, clear, confirmAction, el, notify, pinWindowBodyScroll, select, whenComponents } from './ui';
import type { AnalyticsReport, Breakdown, DemoStatus, FieldReport, FormSummary, NumberSummary } from './types';

/** How the NPS bands are coloured, and what they are called. */
const NPS_BANDS = [
	{ key: 'detractors', label: 'Detractors', hint: '0–6' },
	{ key: 'passives', label: 'Passives', hint: '7–8' },
	{ key: 'promoters', label: 'Promoters', hint: '9–10' },
] as const;

/**
 * The most columns a histogram is allowed to draw.
 *
 * The spread of a numeric question is visitor-controlled: one answer of ten
 * million next to an answer of one would otherwise mean ten million DOM
 * columns, and a frozen admin tab is a thing a single hostile submission must
 * not be able to buy. Fifty is comfortably more than a window can usefully
 * show anyway.
 */
export const MAX_HISTOGRAM_BUCKETS = 50;

/** One drawn column of a numeric question's distribution. */
export interface HistogramBucket {
	/** What the tick under the column says — one value, or an "a–b" range. */
	label: string;
	count: number;
	/** Whether the mean falls in this column, which is what gets it marked. */
	holdsMean: boolean;
}

/**
 * Folds a numeric distribution into at most `MAX_HISTOGRAM_BUCKETS` columns.
 *
 * When the spread fits, every integer gets its own column, as before. When it
 * does not, columns become equal ranges labelled "a–b" and the counts inside a
 * range are summed. Either way each answer is rounded into its column first:
 * the server keeps fractional answers under fractional keys ("3.5"), and they
 * still moved the min, max and mean — dropping them here would draw a
 * distribution that disagrees with the summary line above it.
 */
export function histogramBuckets( numbers: NumberSummary ): HistogramBucket[] {
	const lo = Math.floor( numbers.min );
	const hi = Math.ceil( numbers.max );

	if ( ! Number.isFinite( lo ) || ! Number.isFinite( hi ) || hi < lo ) {
		return [];
	}

	const span = hi - lo + 1;
	const size = Math.max( 1, Math.ceil( span / MAX_HISTOGRAM_BUCKETS ) );
	const total = Math.ceil( span / size );

	/** The column a value belongs to, clamped so nothing falls off either end. */
	const indexOf = ( value: number ): number =>
		Math.min( total - 1, Math.max( 0, Math.floor( ( value - lo ) / size ) ) );

	const buckets: HistogramBucket[] = [];

	for ( let index = 0; index < total; index++ ) {
		const start = lo + index * size;
		const end = Math.min( hi, start + size - 1 );

		buckets.push( {
			label: size === 1 ? String( start ) : `${ start }–${ end }`,
			count: 0,
			holdsMean: false,
		} );
	}

	for ( const [ key, count ] of Object.entries( numbers.distribution ) ) {
		const value = Math.round( Number( key ) );

		if ( ! Number.isFinite( value ) ) {
			continue;
		}

		buckets[ indexOf( value ) ].count += count;
	}

	const mean = Math.round( numbers.mean );

	if ( Number.isFinite( mean ) ) {
		buckets[ indexOf( mean ) ].holdsMean = true;
	}

	return buckets;
}

/** The report window, mounted into one root element. */
class AnalyticsWindow {
	private readonly bar: HTMLElement;
	private readonly body: HTMLElement;

	private forms: FormSummary[] = [];
	private formId = 0;
	private dimension = '';
	private report: AnalyticsReport | null = null;
	private demo: DemoStatus | null = null;

	/**
	 * Whether the demo panel is available at all.
	 *
	 * Answered by the server rather than by the config blob. The blob says what
	 * this user's preference is; the route says whether it will actually serve —
	 * and a panel whose buttons 404 is worse than no panel.
	 */
	private demoAvailable = false;

	public constructor( root: HTMLElement ) {
		this.bar = root.querySelector< HTMLElement >( '[data-atfa-bar]' ) ?? el( 'div' );
		this.body = root.querySelector< HTMLElement >( '[data-atfa-body]' ) ?? el( 'div' );
	}

	/** Loads the form list and draws the first report. */
	public async start(): Promise< void > {
		await whenComponents();

		try {
			this.forms = await api.listForms();
		} catch {
			this.fail( 'Could not load your forms.' );

			return;
		}

		if ( ! this.forms.length ) {
			this.fail( 'There are no forms yet. Build one, collect a few submissions, and this fills in.' );

			return;
		}

		// The demo panel is offered only when the route answers. `demoStatus()`
		// 404s when developer mode is off, which is the intended reply rather than
		// an error worth reporting.
		if ( runtime?.devMode && runtime?.canEdit ) {
			try {
				this.demo = await api.demoStatus();
				this.demoAvailable = true;
			} catch {
				this.demoAvailable = false;
			}
		}

		// A deep link's form wins; then whatever a warm event already set; the
		// first form is only the answer when nobody asked for one. Assigning
		// unconditionally here was the split-second flash: the deep-linked
		// report rendered, then this stomped it back to the first form.
		const requested = takeFormFor( 'analytics' );

		if ( requested && this.forms.some( ( form ) => form.id === requested ) ) {
			this.formId = requested;
		} else if ( ! this.formId ) {
			this.formId = this.forms[ 0 ].id;
		}

		await this.load();
	}

	/** Fetches the report for the current form and redraws. */
	/**
	 * Deep-link entry: report on one form.
	 *
	 * @param formId The form.
	 */
	public async showForm( formId: number ): Promise< void > {
		this.formId = formId;
		this.dimension = '';
		await this.load();
	}

	private async load(): Promise< void > {
		try {
			this.report = await api.analytics( this.formId, this.dimension );
		} catch {
			this.fail( 'Could not load that report.' );

			return;
		}

		this.dimension = this.report.breakdown?.id ?? '';

		this.renderBar();
		this.render();
	}

	/** Says why there is nothing to show. */
	private fail( message: string ): void {
		clear( this.bar );
		clear( this.body );
		this.body.append( el( 'p', { class: 'atfa__empty', text: message } ) );
	}

	/** The form picker, the grouping picker, and the headline counts. */
	private renderBar(): void {
		clear( this.bar );

		this.bar.append(
			select(
				String( this.formId ),
				this.forms.map( ( form ) => ( { value: String( form.id ), label: form.title || `#${ form.id }` } ) ),
				( value ) => {
					this.formId = Number( value );
					// Cleared rather than kept: the grouping is a field id, and the
					// same id on a different form is a different question.
					this.dimension = '';
					void this.load();
				}
			)
		);

		const dimensions = this.report?.dimensions ?? [];

		if ( dimensions.length > 1 ) {
			this.bar.append(
				el( 'span', { class: 'atfa__bar-label', text: 'Group by' } ),
				select(
					this.dimension,
					dimensions.map( ( item ) => ( { value: item.id, label: item.label } ) ),
					( value ) => {
						this.dimension = value;
						void this.load();
					}
				)
			);
		}
	}

	/** Draws the whole report. */
	private render(): void {
		const report = this.report;

		clear( this.body );

		if ( ! report ) {
			return;
		}

		this.body.append( this.kpis( report ) );

		if ( report.submissions < 1 && report.sampled < 1 ) {
			this.body.append(
				el( 'p', {
					class: 'atfa__empty',
					text: 'No submissions yet. Everything below fills in as they arrive.',
				} )
			);
		} else {
			this.body.append( this.timeline( report ) );

			for ( const field of report.fields ) {
				if ( field.nps ) {
					this.body.append( this.npsPanel( field ) );
				}
			}

			if ( report.breakdown && report.breakdown.groups.length ) {
				this.body.append( this.breakdownPanel( report.breakdown ) );
			}

			this.body.append( this.questions( report ) );
		}

		if ( this.demoAvailable ) {
			this.body.append( this.demoPanel() );
		}
	}

	/** The headline numbers. */
	private kpis( report: AnalyticsReport ): HTMLElement {
		const cards: Array< { label: string; value: string; hint?: string } > = [
			{ label: 'Submissions', value: String( report.submissions ) },
			{ label: 'Views', value: String( report.views ) },
			{ label: 'Conversion', value: `${ report.conversion }%`, hint: 'of people who saw it' },
			{ label: 'Completion', value: `${ report.completion }%`, hint: 'of people who started' },
			{ label: 'Unread', value: String( report.unread ) },
			{ label: 'Spam', value: String( report.spam ) },
		];

		return el( 'div', {
			class: 'atfa-kpis',
			children: cards.map( ( card ) =>
				el( 'div', {
					class: 'atfa-kpi',
					children: [
						el( 'span', { class: 'atfa-kpi__value', text: card.value } ),
						el( 'span', { class: 'atfa-kpi__label', text: card.label } ),
						card.hint ? el( 'span', { class: 'atfa-kpi__hint', text: card.hint } ) : null,
					],
				} )
			),
		} );
	}

	/**
	 * Submissions per day.
	 *
	 * Drawn as one element per day rather than an SVG path, so a day can carry its
	 * own tooltip and the whole thing stays readable when the window is narrow.
	 * The server sends every day in the window including the empty ones, which is
	 * what keeps a quiet fortnight looking quiet instead of closing up.
	 */
	private timeline( report: AnalyticsReport ): HTMLElement {
		const days = report.timeline;
		const peak = days.reduce( ( most, day ) => Math.max( most, day.count ), 0 );
		const total = days.reduce( ( sum, day ) => sum + day.count, 0 );

		return this.panel(
			'When they answered',
			`${ total } in the last ${ days.length } days · busiest day ${ peak }`,
			[
				el( 'div', {
					class: 'atfa-timeline',
					children: days.map( ( day ) =>
						el( 'div', {
							class: `atfa-timeline__day${ day.count ? '' : ' is-empty' }`,
							attrs: {
								// A percentage rather than a pixel height, so the chart
								// scales with the window instead of being redrawn on
								// every resize.
								style: `height:${ peak ? Math.max( 2, ( day.count / peak ) * 100 ) : 0 }%`,
								title: `${ day.date }: ${ day.count }`,
							},
						} )
					),
				} ),
				el( 'div', {
					class: 'atfa-timeline__axis',
					children: [
						el( 'span', { text: days[ 0 ]?.date ?? '' } ),
						el( 'span', { text: days[ days.length - 1 ]?.date ?? '' } ),
					],
				} ),
			]
		);
	}

	/**
	 * The NPS panel.
	 *
	 * The three bands are drawn to scale as one bar, which is the only honest way
	 * to show a score that is a *difference* between two of them: the passives in
	 * the middle contribute nothing to the number, and a chart that omits them
	 * makes the score look like a share of something.
	 */
	private npsPanel( field: FieldReport ): HTMLElement {
		const nps = field.nps;

		if ( ! nps ) {
			return el( 'div' );
		}

		const share = ( count: number ) => ( nps.responses ? ( count / nps.responses ) * 100 : 0 );

		return this.panel( field.label, `${ nps.responses } answers · Net Promoter Score`, [
			el( 'div', {
				class: 'atfa-nps',
				children: [
					el( 'div', {
						class: `atfa-nps__score is-${ nps.score >= 50 ? 'great' : nps.score >= 0 ? 'ok' : 'poor' }`,
						children: [
							el( 'span', {
								class: 'atfa-nps__number',
								// Signed, always. An NPS of 6 and an NPS of -6 are very
								// different results and differ by one character.
								text: `${ nps.score > 0 ? '+' : '' }${ nps.score }`,
							} ),
							el( 'span', { class: 'atfa-nps__caption', text: 'NPS' } ),
						],
					} ),
					el( 'div', {
						class: 'atfa-nps__detail',
						children: [
							el( 'div', {
								class: 'atfa-nps__bar',
								children: NPS_BANDS.map( ( band ) =>
									el( 'div', {
										class: `atfa-nps__band is-${ band.key }`,
										attrs: {
											style: `width:${ share( nps[ band.key ] ) }%`,
											title: `${ band.label } (${ band.hint }): ${ nps[ band.key ] }`,
										},
									} )
								),
							} ),
							el( 'div', {
								class: 'atfa-nps__legend',
								children: NPS_BANDS.map( ( band ) =>
									el( 'span', {
										class: `atfa-nps__key is-${ band.key }`,
										children: [
											el( 'strong', { text: String( nps[ band.key ] ) } ),
											el( 'span', { text: ` ${ band.label } ` } ),
											el( 'small', { text: band.hint } ),
										],
									} )
								),
							} ),
						],
					} ),
				],
			} ),
		] );
	}

	/**
	 * The cross-tab.
	 *
	 * "The average score is 7.5" is a fact nobody can act on. "Support scores 5.8
	 * and everyone else is above 7" is a fact with an obvious next step, and this
	 * is the panel that turns the first into the second.
	 *
	 * A table, genuinely — rows of numbers compared across a shared scale is what
	 * a table is for, and it reads correctly in a screen reader without any of the
	 * describing an SVG would need.
	 */
	private breakdownPanel( breakdown: Breakdown ): HTMLElement {
		const metrics = breakdown.groups[ 0 ]?.metrics ?? [];

		if ( ! metrics.length ) {
			return el( 'div' );
		}

		// Every column is scaled against its own maximum rather than a shared one,
		// because a 0–10 score and a 1–5 rating on one scale would make the rating
		// look like half the answer it is.
		const ceilings = new Map< string, number >();

		for ( const group of breakdown.groups ) {
			for ( const metric of group.metrics ) {
				ceilings.set( metric.id, Math.max( ceilings.get( metric.id ) ?? 0, metric.mean ) );
			}
		}

		const head = el( 'tr', {
			children: [
				el( 'th', { text: breakdown.label, attrs: { scope: 'col' } } ),
				el( 'th', { text: 'Answers', attrs: { scope: 'col' } } ),
				...metrics.map( ( metric ) => el( 'th', { text: metric.label, attrs: { scope: 'col' } } ) ),
			],
		} );

		const rows = breakdown.groups.map( ( group ) =>
			el( 'tr', {
				children: [
					el( 'th', { class: 'atfa-table__label', text: group.label, attrs: { scope: 'row' } } ),
					el( 'td', { class: 'atfa-table__count', text: String( group.count ) } ),
					...group.metrics.map( ( metric ) =>
						el( 'td', {
							children: [
								el( 'div', {
									class: 'atfa-meter',
									children: [
										el( 'div', {
											class: 'atfa-meter__fill',
											attrs: {
												style: `width:${
													( metric.mean / ( ceilings.get( metric.id ) || 1 ) ) * 100
												}%`,
											},
										} ),
									],
								} ),
								el( 'span', {
									class: 'atfa-meter__value',
									text:
										null !== metric.nps
											? `${ metric.mean.toFixed( 1 ) } · NPS ${ metric.nps > 0 ? '+' : '' }${ metric.nps }`
											: metric.mean.toFixed( 2 ),
								} ),
							],
						} )
					),
				],
			} )
		);

		return this.panel( `Broken down by ${ breakdown.label.toLowerCase() }`, '', [
			// Six question columns do not fit a narrow window, and a table never
			// shrinks below its content — it overflows. Wide content scrolls
			// inside its own container; it must never bleed past the panel.
			this.scrollable(
				el( 'table', {
					class: 'atfa-table',
					children: [ el( 'thead', { children: [ head ] } ), el( 'tbody', { children: rows } ) ],
				} )
			),
		] );
	}

	/** One panel per question. */
	private questions( report: AnalyticsReport ): HTMLElement {
		return el( 'div', {
			class: 'atfa-questions',
			children: report.fields.map( ( field ) => this.question( field, report.sampled ) ),
		} );
	}

	/** One question. */
	private question( field: FieldReport, sampled: number ): HTMLElement {
		const parts: Array< HTMLElement | null > = [];

		if ( field.choices.length ) {
			// Against the number of *people*, not the number of answers. On a
			// multi-select those differ, the percentages rightly sum past 100, and
			// dividing by the answers instead is a mistake that makes every option
			// look more popular than it is while still adding up to a tidy 100.
			const ceiling = field.choices.reduce( ( most, choice ) => Math.max( most, choice.count ), 0 );

			parts.push(
				el( 'ul', {
					class: 'atfa-bars',
					children: field.choices.map( ( choice ) =>
						el( 'li', {
							class: 'atfa-bars__row',
							children: [
								el( 'span', { class: 'atfa-bars__label', text: choice.label } ),
								el( 'div', {
									class: 'atfa-meter',
									children: [
										el( 'div', {
											class: 'atfa-meter__fill',
											attrs: { style: `width:${ ceiling ? ( choice.count / ceiling ) * 100 : 0 }%` },
										} ),
									],
								} ),
								el( 'span', {
									class: 'atfa-bars__value',
									text: `${ choice.count }  ${
										field.answered ? Math.round( ( choice.count / field.answered ) * 100 ) : 0
									}%`,
								} ),
							],
						} )
					),
				} )
			);
		}

		if ( field.numbers ) {
			parts.push( this.histogram( field ) );
		}

		if ( ! parts.length ) {
			parts.push(
				el( 'p', {
					class: 'atfa-question__note',
					// A repeater sub-field is answered per row, not per
					// person, and says so through `of` and `unit`.
					text: `${ field.answered } of ${ field.of ?? sampled } ${ field.unit ?? 'people' } answered this.`,
				} )
			);
		}

		return this.panel( field.label, this.questionSummary( field, sampled ), parts );
	}

	/** The line under a question's title. */
	private questionSummary( field: FieldReport, sampled: number ): string {
		const bits = [ `${ field.rate }% answered` ];

		if ( field.numbers ) {
			bits.push( `mean ${ field.numbers.mean }`, `median ${ field.numbers.median }` );
		}

		bits.push( `${ field.answered }/${ field.of ?? sampled }` );

		return bits.join( ' · ' );
	}

	/**
	 * A numeric question's distribution.
	 *
	 * The bar the mean falls in is marked, because the interesting cases are the
	 * ones where it falls in a bar almost nobody chose — which is exactly when
	 * quoting the mean on its own would have misled.
	 */
	private histogram( field: FieldReport ): HTMLElement {
		const numbers = field.numbers;

		if ( ! numbers ) {
			return el( 'div' );
		}

		const buckets = histogramBuckets( numbers );
		const peak = buckets.reduce( ( most, bucket ) => Math.max( most, bucket.count ), 0 );

		// A wide-ranging answer — forty hour buckets — has more columns than a
		// narrow window has pixels, and each column's count label sets a width
		// it cannot shrink below. The histogram scrolls sideways rather than
		// painting past the panel.
		return this.scrollable( el( 'div', {
			class: 'atfa-hist',
			children: buckets.map( ( bucket ) =>
				el( 'div', {
					class: `atfa-hist__col${ bucket.holdsMean ? ' is-mean' : '' }`,
					attrs: { title: `${ bucket.label }: ${ bucket.count }` },
					children: [
						el( 'span', { class: 'atfa-hist__count', text: bucket.count ? String( bucket.count ) : '' } ),
						el( 'div', {
							class: 'atfa-hist__bar',
							attrs: { style: `height:${ peak ? Math.max( 2, ( bucket.count / peak ) * 100 ) : 0 }%` },
						} ),
						el( 'span', { class: 'atfa-hist__tick', text: bucket.label } ),
					],
				} )
			),
		} ) );
	}

	/**
	 * Wraps something wider than the window in a sideways-scrolling strip.
	 *
	 * The one honest answer to content with a real minimum width: a table or a
	 * histogram that cannot shrink must scroll within its own panel, because
	 * the alternative — seen in the wild — is bars painting straight through
	 * the panel border and off the edge of the window.
	 */
	private scrollable( child: HTMLElement ): HTMLElement {
		return el( 'div', { class: 'atfa-scroll', children: [ child ] } );
	}

	/**
	 * The developer panel.
	 *
	 * Deliberately at the bottom, deliberately labelled, and deliberately not
	 * present at all unless developer mode is on. It writes several hundred
	 * entries into whatever database this happens to be, which is a fine thing to
	 * do on a laptop and a terrible one to do by accident.
	 */
	private demoPanel(): HTMLElement {
		const status = this.demo;
		const progress = el( 'p', { class: 'atfa-demo__status', text: this.demoSummary( status ) } );

		const seed = button( 'Fill with demo data', async () => {
			await this.seedAll( progress );
		} );

		const remove = button( 'Remove demo data', async () => {
			if ( ! ( await confirmAction( 'Delete the demo survey and every entry it generated?', 'Remove demo data' ) ) ) {
				return;
			}

			progress.textContent = 'Removing…';

			try {
				const result = await api.demoRemove();

				notify( 'Demo data removed', `${ result.entries } entries and ${ result.forms } form.` );
			} catch {
				notify( 'Could not remove the demo data', '', 'error' );
			}

			await this.refreshDemo( progress );
		} );

		return el( 'div', {
			class: 'atfa-demo',
			attrs: { 'data-atfa-demo': '' },
			children: [
				el( 'h3', { class: 'atfa-demo__title', text: 'Developer' } ),
				el( 'p', {
					class: 'atfa-demo__note',
					text:
						'Generates a team pulse survey and several hundred submissions through the real ' +
						'submission pipeline, so this report has something to report on. Everything it makes ' +
						'is tagged, and removing it deletes exactly that and nothing else.',
				} ),
				progress,
				el( 'div', { class: 'atfa-demo__actions', children: [ seed, remove ] } ),
			],
		} );
	}

	/** What the developer panel says about the current state. */
	private demoSummary( status: DemoStatus | null ): string {
		if ( ! status || ! status.formId ) {
			return 'No demo data on this site.';
		}

		return `${ status.title }: ${ status.entries } of ${ status.target } submissions.`;
	}

	/**
	 * Generates every remaining chunk.
	 *
	 * A loop of small requests rather than one big one. Each chunk is a few dozen
	 * passes through the whole submission pipeline, and the server caps what it
	 * will do per call — so this asks repeatedly, which is also what gives the
	 * count something true to report while it runs.
	 */
	private async seedAll( progress: HTMLElement ): Promise< void > {
		let guard = 0;
		let failures = 0;

		for ( ;; ) {
			try {
				const status = await api.demoSeed();

				this.demo = status;
				failures = 0;
				progress.textContent = `Generating… ${ status.entries } of ${ status.target }`;

				if ( status.remaining < 1 ) {
					notify( 'Demo data ready', `${ status.entries } submissions.` );

					break;
				}
			} catch {
				// One chunk failing does not mean the next one will. A batch is
				// resumable by construction — the server counts what exists rather
				// than what it was asked for — so retrying continues from wherever
				// it stopped instead of starting again or double-writing.
				failures += 1;

				if ( failures >= 3 ) {
					progress.textContent = 'Stopped early. Press it again to carry on from here.';
					notify( 'Could not finish the demo data', 'Some submissions were made. Try again to continue.', 'error' );

					break;
				}
			}

			// A server that stopped making progress would otherwise spin here
			// forever, hammering the site with a request it has already refused to
			// satisfy.
			guard += 1;

			if ( guard > 200 ) {
				break;
			}
		}

		await this.refreshDemo( progress );

		// Switch to the survey that was just generated, and refresh the picker so
		// it is in the list. Staying put would show the report of whichever form
		// happened to be selected — which after "fill with demo data" is the one
		// form guaranteed not to have changed.
		if ( this.demo?.formId ) {
			try {
				this.forms = await api.listForms();
			} catch {
				// A stale picker is survivable; the report below is the point.
			}

			this.formId = this.demo.formId;
			this.dimension = '';
		}

		// The report is about to be entirely different, so it is refetched rather
		// than patched.
		await this.load();
	}

	/** Re-reads the demo status and redraws the line that shows it. */
	private async refreshDemo( progress: HTMLElement ): Promise< void > {
		try {
			this.demo = await api.demoStatus();
		} catch {
			this.demo = null;
		}

		progress.textContent = this.demoSummary( this.demo );
	}

	/** A titled block. */
	private panel( title: string, note: string, children: Array< HTMLElement | null > ): HTMLElement {
		return el( 'section', {
			class: 'atfa-panel',
			children: [
				el( 'header', {
					class: 'atfa-panel__head',
					children: [
						el( 'h3', { class: 'atfa-panel__title', text: title } ),
						note ? el( 'p', { class: 'atfa-panel__note', text: note } ) : null,
					],
				} ),
				...children,
			],
		} );
	}

	/** Scrolls the developer panel into view. */
	public revealDemo(): void {
		this.body.querySelector( '[data-atfa-demo]' )?.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	}
}

let mounted: AnalyticsWindow | null = null;

// WP Explorer deep-link: open the report on one form.
document.addEventListener( 'atf-open-analytics-form', ( event ) => {
	const formId = Number( ( event as CustomEvent ).detail?.formId ?? 0 );

	if ( ! formId ) {
		return;
	}

	void mounted?.showForm( formId );
} );

/** Mounts the window into whatever root is on the page. */
export function mountAnalytics(): void {
	const root = document.querySelector< HTMLElement >( '[data-atfa-root]' );

	if ( ! root || root.dataset.atfaMounted ) {
		return;
	}

	root.dataset.atfaMounted = '1';
	pinWindowBodyScroll( root );
	mounted = new AnalyticsWindow( root );

	void mounted.start();
}

// The dock's "Demo data" row opens this window and then asks for the developer
// panel. The window may not exist yet when that fires, so the request is retried
// once the content has loaded rather than dropped.
let wantsDemo = false;

document.addEventListener( 'atf-open-demo-panel', () => {
	wantsDemo = true;

	window.setTimeout( () => {
		mounted?.revealDemo();
		wantsDemo = false;
	}, 600 );
} );

document.addEventListener( 'os-window-content-loaded', () => {
	mountAnalytics();

	if ( wantsDemo ) {
		window.setTimeout( () => mounted?.revealDemo(), 400 );
	}
} );

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mountAnalytics );
} else {
	mountAnalytics();
}

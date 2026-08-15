/**
 * The Quick layer.
 *
 * 69 tokens is a complete description of a theme and a terrible way to make
 * one. Nobody thinks "I want `radius-field: 14px`, `radius-button: 14px`,
 * `radius-card: 24px` and `radius-check: 4px`" — they think *rounder*. The token
 * list is the right storage format and the wrong interface, and shipping only
 * the token list is how a theme editor ends up being used by the person who
 * wrote it and nobody else.
 *
 * So this file defines a handful of controls that each drive a whole family of
 * tokens, in the vocabulary people actually use: roundness, density, accent,
 * mood, field style, corner softness of shadows. The full token list stays,
 * behind Advanced, for the cases these cannot express — and because a control
 * that cannot be escaped is worse than no control.
 *
 * Every dial writes ordinary token overrides. There is no second storage format
 * and nothing here the Advanced list cannot also reach: the Quick layer is a
 * faster way to write the same map, not a parallel one. That is what keeps a
 * theme made here and a theme made by hand the same kind of object.
 */

import type { ThemeToken } from './types';

/** A resolved token map — what a dial reads to work out where it sits. */
export type Tokens = Record< string, string >;

/** One control in the Quick layer. */
export interface QuickDial {
	id: string;
	label: string;
	hint: string;
	/** `scale` renders as a slider over `steps`; `choice` as a segmented control. */
	kind: 'scale' | 'choice' | 'colour';
	/** For `scale` and `choice`: the positions this dial can take. */
	steps?: Array< { value: string; label: string } >;
	/** The tokens this dial writes, for a given step. */
	apply: ( step: string, current: Tokens ) => Tokens;
	/** Which step the current token map corresponds to, for showing state. */
	read: ( current: Tokens ) => string;
	/** Every token this dial owns — used to clear it back to the theme. */
	owns: string[];
}

/** Reads a pixel value out of a token, defaulting when it is not a length. */
function px( value: string | undefined, fallback: number ): number {
	const parsed = parseFloat( String( value ?? '' ) );

	return Number.isFinite( parsed ) ? parsed : fallback;
}

/** Picks the step whose number is closest to a measured value. */
function nearest( value: number, steps: Array< { value: string; at: number } > ): string {
	let best = steps[ 0 ];

	for ( const step of steps ) {
		if ( Math.abs( step.at - value ) < Math.abs( best.at - value ) ) {
			best = step;
		}
	}

	return best.value;
}

const ROUNDNESS = [
	{ value: 'square', label: 'Square', at: 0 },
	{ value: 'soft', label: 'Soft', at: 4 },
	{ value: 'rounded', label: 'Rounded', at: 10 },
	{ value: 'pill', label: 'Pill', at: 999 },
];

const DENSITY = [
	{ value: 'compact', label: 'Compact', at: 7 },
	{ value: 'cosy', label: 'Cosy', at: 9 },
	{ value: 'roomy', label: 'Roomy', at: 13 },
];

const SHADOW = [
	{ value: 'none', label: 'None' },
	{ value: 'subtle', label: 'Subtle' },
	{ value: 'lifted', label: 'Lifted' },
	{ value: 'hard', label: 'Hard' },
];

const FIELD_STYLE = [
	{ value: 'outline', label: 'Outlined' },
	{ value: 'filled', label: 'Filled' },
	{ value: 'underline', label: 'Underline' },
	{ value: 'none', label: 'Bare' },
];

const LABELS = [
	{ value: 'top', label: 'Above' },
	{ value: 'floating', label: 'Floating' },
	{ value: 'left', label: 'In the margin' },
	{ value: 'hidden', label: 'Hidden' },
];

/**
 * The dials, in the order they are shown.
 *
 * Ordered by how often somebody changes them, not by how the tokens are
 * grouped internally — accent first because it is the one nearly everybody
 * touches, label position last because it is a structural decision made once.
 */
export function quickDials(): QuickDial[] {
	return [
		{
			id: 'accent',
			label: 'Accent',
			hint: 'The colour of buttons, focus rings and anything selected.',
			kind: 'colour',
			owns: [ 'accent', 'accent-soft', 'border-focus', 'focus-ring-color', 'button-bg', 'button-bg-hover' ],
			read: ( current ) => current.accent ?? '#2271b1',
			apply: ( value ) => ( {
				accent: value,
				// The soft wash and the focus ring are the accent at other
				// strengths. Setting them together is the difference between
				// "changed the accent" and "changed one of six places the accent
				// appears, and now they disagree".
				'accent-soft': `color-mix( in srgb, ${ value } 12%, transparent )`,
				'border-focus': value,
				'focus-ring-color': value,
				'button-bg': value,
				'button-bg-hover': `color-mix( in srgb, ${ value } 88%, #000 )`,
			} ),
		},

		{
			id: 'roundness',
			label: 'Roundness',
			hint: 'Corners, everywhere at once.',
			kind: 'scale',
			steps: ROUNDNESS.map( ( { value, label } ) => ( { value, label } ) ),
			owns: [ 'radius-field', 'radius-button', 'radius-card', 'radius-check' ],
			read: ( current ) => nearest( px( current[ 'radius-field' ], 4 ), ROUNDNESS ),
			apply: ( step ) => {
				const base = ROUNDNESS.find( ( r ) => r.value === step )?.at ?? 4;

				// The card is rounder than the field and the tick box is
				// squarer, because that is what reads as considered rather than
				// as one number applied four times. A pill field with pill
				// checkboxes looks like a mistake.
				return {
					'radius-field': `${ base }px`,
					'radius-button': `${ base }px`,
					'radius-card': `${ Math.min( base * 2, 28 ) }px`,
					'radius-check': `${ Math.min( base, 6 ) }px`,
				};
			},
		},

		{
			id: 'density',
			label: 'Density',
			hint: 'How much air the form has.',
			kind: 'scale',
			steps: DENSITY.map( ( { value, label } ) => ( { value, label } ) ),
			owns: [ 'pad-field-x', 'pad-field-y', 'gap-fields', 'gap-label', 'button-pad-x', 'button-pad-y' ],
			read: ( current ) => nearest( px( current[ 'pad-field-y' ], 9 ), DENSITY ),
			apply: ( step ) => {
				const y = DENSITY.find( ( d ) => d.value === step )?.at ?? 9;

				// Horizontal padding grows faster than vertical, and the gap
				// between fields faster still — a form that gets taller without
				// getting looser just looks swollen.
				return {
					'pad-field-y': `${ y }px`,
					'pad-field-x': `${ Math.round( y * 1.4 ) }px`,
					'gap-fields': `${ Math.round( y * 2.2 ) }px`,
					'gap-label': `${ Math.max( 4, Math.round( y * 0.7 ) ) }px`,
					'button-pad-y': `${ y + 2 }px`,
					'button-pad-x': `${ Math.round( y * 2.2 ) }px`,
				};
			},
		},

		{
			id: 'shadow',
			label: 'Depth',
			hint: 'How far the form sits off the page.',
			kind: 'scale',
			steps: SHADOW,
			owns: [ 'shadow-field', 'shadow-field-focus', 'shadow-button', 'shadow-button-hover', 'shadow-card' ],
			read: ( current ) => {
				const card = current[ 'shadow-card' ] ?? 'none';

				if ( card === 'none' || card.trim() === '' ) {
					return current[ 'shadow-field' ] && current[ 'shadow-field' ] !== 'none' ? 'hard' : 'none';
				}

				return card.includes( '0 1px' ) || card.includes( '2px' ) ? 'subtle' : 'lifted';
			},
			apply: ( step ) => {
				switch ( step ) {
					case 'subtle':
						return {
							'shadow-field': 'none',
							'shadow-field-focus': 'none',
							'shadow-button': '0 1px 2px rgba( 0, 0, 0, 0.12 )',
							'shadow-button-hover': '0 2px 4px rgba( 0, 0, 0, 0.16 )',
							'shadow-card': '0 1px 3px rgba( 0, 0, 0, 0.1 )',
						};

					case 'lifted':
						return {
							'shadow-field': 'none',
							'shadow-field-focus': '0 4px 12px rgba( 0, 0, 0, 0.12 )',
							'shadow-button': '0 4px 10px rgba( 0, 0, 0, 0.16 )',
							'shadow-button-hover': '0 8px 20px rgba( 0, 0, 0, 0.2 )',
							'shadow-card': '0 10px 30px rgba( 0, 0, 0, 0.14 )',
						};

					// A hard shadow has no blur at all — the Brutal look. Kept as
					// a step rather than left to the token list because it is a
					// recognisable style, not an arbitrary value.
					case 'hard':
						return {
							'shadow-field': '3px 3px 0 currentColor',
							'shadow-field-focus': '5px 5px 0 currentColor',
							'shadow-button': '4px 4px 0 currentColor',
							'shadow-button-hover': '2px 2px 0 currentColor',
							'shadow-card': 'none',
						};

					default:
						return {
							'shadow-field': 'none',
							'shadow-field-focus': 'none',
							'shadow-button': 'none',
							'shadow-button-hover': 'none',
							'shadow-card': 'none',
						};
				}
			},
		},

		{
			id: 'field-style',
			label: 'Fields',
			hint: 'How an input is drawn.',
			kind: 'choice',
			steps: FIELD_STYLE,
			owns: [ 'field-style' ],
			read: ( current ) => current[ 'field-style' ] ?? 'outline',
			apply: ( step ) => ( { 'field-style': step } ),
		},

		{
			id: 'labels',
			label: 'Labels',
			hint: 'Where the question sits relative to the answer.',
			kind: 'choice',
			steps: LABELS,
			owns: [ 'label-position' ],
			read: ( current ) => current[ 'label-position' ] ?? 'top',
			apply: ( step ) => ( { 'label-position': step } ),
		},
	];
}

/**
 * Which Advanced tokens a dial has taken over.
 *
 * The Advanced list marks these, so it is obvious why moving a slider changed
 * six values — and so somebody who edits one by hand can see they have stepped
 * outside the dial rather than wondering why it stopped matching.
 */
export function dialOwning( token: string ): QuickDial | null {
	return quickDials().find( ( dial ) => dial.owns.includes( token ) ) ?? null;
}

/** Groups the Advanced tokens the way the Studio shows them. */
export function advancedGroups( tokens: ThemeToken[] ): Map< string, ThemeToken[] > {
	const grouped = new Map< string, ThemeToken[] >();

	for ( const token of tokens ) {
		const list = grouped.get( token.group ) ?? [];

		list.push( token );
		grouped.set( token.group, list );
	}

	return grouped;
}

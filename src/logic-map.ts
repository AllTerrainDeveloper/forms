/**
 * Conditional logic, drawn.
 *
 * A `LOGIC` badge on a card says that a field has a condition and nothing about
 * what it is. To find out you had to select the field, scroll the inspector to
 * Conditional logic, and read three dropdowns — and even then you learned about
 * that one field, not about the shape of the form. A form where three questions
 * depend on the first one is a form with a *structure*, and the builder was
 * showing a flat list.
 *
 * So two things, and the pair is the point:
 *
 * 1. **Every card says its condition in words.** "Shown when Can you make it? is
 *    Yes, I will be there." No selecting, no scrolling, no operator jargon —
 *    `greater_equal` becomes "is at least".
 *
 * 2. **Curves join the controller to what it controls.** Drawn in an SVG layer
 *    over the canvas, bowing out to the right so they never cross a card, each
 *    labelled with the value that triggers it. Hovering or selecting a card dims
 *    every curve that does not touch it, which turns "what does this field
 *    affect?" from a search into a glance.
 *
 * The curves are geometry over live DOM rects rather than anything stored, so
 * they follow a drag-reorder, a window resize and a scroll without the schema
 * knowing they exist. Nothing here writes: it is a view of the logic, and if it
 * disappeared the form would behave identically.
 */

import type { Field, LogicOperator, LogicRule } from './types';

/** One drawn connection: a field's rule pointing at the field it controls. */
export interface LogicEdge {
	/** The field whose answer decides. */
	from: string;
	/** The field that appears or disappears. */
	to: string;
	/** The whole clause, e.g. "Can you make it? is Yes" — the curve's tooltip. */
	label: string;
	/**
	 * The trigger alone, e.g. "Yes" — what the curve is labelled with.
	 *
	 * The full clause names the controlling question, and the curve already
	 * points at it; repeating it on the line is the same fact twice, and it is
	 * the half that does not fit in a gutter.
	 */
	short: string;
	/** `show` curves use the accent; `hide` uses the warning colour. */
	action: 'show' | 'hide';
	/** True when `from` names a field that is not in the form. */
	broken: boolean;
}

/**
 * How each operator reads in a sentence.
 *
 * Written as the middle of "Can you make it? ___ Yes", so they read as English
 * rather than as the enum they are. `greater_equal` is "is at least" and not
 * "≥": the audience for this line is somebody building a contact form.
 */
export const OPERATOR_LABELS: Record< LogicOperator, string > = {
	is: 'is',
	is_not: 'is not',
	contains: 'contains',
	not_contains: 'does not contain',
	starts_with: 'starts with',
	ends_with: 'ends with',
	greater: 'is more than',
	less: 'is less than',
	greater_equal: 'is at least',
	less_equal: 'is at most',
	empty: 'is empty',
	not_empty: 'has any answer',
};

/** The operators that are a complete statement on their own. */
export const VALUELESS_OPERATORS: LogicOperator[] = [ 'empty', 'not_empty' ];

/** A field's label, or something honest when it has none. */
function labelOf( fields: Field[], id: string ): string | null {
	const field = fields.find( ( candidate ) => candidate.id === id );

	if ( ! field ) {
		return null;
	}

	return field.label || 'an untitled question';
}

/**
 * The label of a choice, given its stored value.
 *
 * A rule stores `yes`; the person reading wants "Yes, I will be there". Falling
 * back to the raw value covers a free-text field, where the stored value *is*
 * what somebody types.
 */
function valueOf( fields: Field[], id: string, value: string ): string {
	const field = fields.find( ( candidate ) => candidate.id === id );
	const choice = field?.choices?.find( ( candidate ) => candidate.value === value );

	return choice?.label || value;
}

/**
 * The trigger half of a rule — what has to be true, without naming the field.
 *
 * "Yes, I will be there" rather than "Can you make it? is Yes, I will be there".
 * Used to label a curve, which already says which field by pointing at it.
 */
export function describeTrigger( rule: LogicRule, fields: Field[] ): string {
	const operator = OPERATOR_LABELS[ rule.operator ] ?? String( rule.operator );

	if ( VALUELESS_OPERATORS.includes( rule.operator ) ) {
		return operator;
	}

	const value = valueOf( fields, rule.field, rule.value );
	const prefix = 'is' === rule.operator ? '' : `${ operator } `;

	return `${ prefix }${ value !== '' ? value : '(nothing)' }`;
}

/**
 * One piece of a condition, tagged with what it is.
 *
 * The reason this is a list of parts rather than a sentence: read
 * "Shown when Can you make it? is Yes, I will be there" and the eye has to work
 * out on its own where the question ends, which word is the comparison, and
 * where the answer starts — and it gets no help at all, because the question
 * ends in a question mark and the answer contains a comma. Two of the five
 * pieces are quoted user text that can contain any punctuation the sentence uses
 * as structure.
 *
 * Tagging the pieces lets the builder draw them as what they are: the referenced
 * question as a chip you can click, the answer as a chip, the comparison as
 * quiet connecting text. The plain sentence is still produced — from these same
 * parts — for screen readers and for the curve tooltips, so the two readings
 * cannot drift.
 */
export type LogicToken =
	/** "Shown when" / "Hidden when" — what the condition does. */
	| { kind: 'verb'; text: string }
	/** The question being consulted. Carries its id so the chip can select it. */
	| { kind: 'field'; text: string; fieldId: string; missing: boolean }
	/**
	 * The comparison: "is", "is at least", "has any answer".
	 *
	 * Carries the operator itself and which rule it belongs to, so the builder
	 * can draw it as a live control — a small select that rewrites the rule in
	 * place — rather than as words about a rule that lives elsewhere.
	 */
	| { kind: 'operator'; text: string; operator: LogicOperator; ruleIndex: number }
	/**
	 * The answer being compared against.
	 *
	 * `text` is the display label (a choice's label when the source field has
	 * choices); `raw` is the stored value the rule actually compares with. The
	 * builder needs both to draw an editable control: choices are offered by
	 * label but written by value.
	 */
	| { kind: 'value'; text: string; raw: string; sourceId: string; ruleIndex: number }
	/** "and" / "or" between rules. */
	| { kind: 'join'; text: string };

/**
 * One rule, as tagged parts.
 *
 * A rule pointing at a deleted field never matches, so the field it guards is
 * stuck either always-shown or always-hidden. That comes back as a single
 * `field` token marked `missing`, which the builder paints as the error it is
 * rather than as a blank space in a sentence.
 */
export function ruleTokens( rule: LogicRule, fields: Field[], ruleIndex = 0 ): LogicToken[] {
	const subject = labelOf( fields, rule.field );
	const operator = OPERATOR_LABELS[ rule.operator ] ?? String( rule.operator );

	if ( subject === null ) {
		return [ { kind: 'field', text: 'a question that no longer exists', fieldId: rule.field, missing: true } ];
	}

	const tokens: LogicToken[] = [
		{ kind: 'field', text: subject, fieldId: rule.field, missing: false },
		{ kind: 'operator', text: operator, operator: rule.operator, ruleIndex },
	];

	if ( VALUELESS_OPERATORS.includes( rule.operator ) ) {
		return tokens;
	}

	const value = valueOf( fields, rule.field, rule.value );

	tokens.push( {
		kind: 'value',
		text: value !== '' ? value : '(nothing)',
		raw: rule.value,
		sourceId: rule.field,
		ruleIndex,
	} );

	return tokens;
}

/**
 * A field's whole condition, as tagged parts.
 *
 * Empty array when the field has no logic, so a caller can use its length as
 * the test for whether to render anything at all.
 */
export function logicTokens( field: Field, fields: Field[] ): LogicToken[] {
	const logic = field.logic;

	if ( ! logic?.enabled || ! logic.rules.length ) {
		return [];
	}

	const tokens: LogicToken[] = [
		{ kind: 'verb', text: logic.action === 'hide' ? 'Hidden when' : 'Shown when' },
	];

	logic.rules.forEach( ( rule, index ) => {
		if ( index > 0 ) {
			tokens.push( { kind: 'join', text: logic.match === 'all' ? 'and' : 'or' } );
		}

		tokens.push( ...ruleTokens( rule, fields, index ) );
	} );

	return tokens;
}

/** Tagged parts flattened back to a sentence. */
export function tokensToText( tokens: LogicToken[] ): string {
	return tokens.map( ( token ) => token.text ).join( ' ' );
}

/**
 * One rule, in words.
 *
 * Returns the clause only — "Can you make it? is Yes" — so the caller can join
 * several with "and"/"or" and put "Shown when" in front once. Built from
 * `ruleTokens()` rather than assembled separately: one description of a rule,
 * rendered two ways.
 */
export function describeRule( rule: LogicRule, fields: Field[] ): string {
	return tokensToText( ruleTokens( rule, fields ) );
}

/**
 * A field's whole condition, in one sentence.
 *
 * The accessible reading of the chips, and the text used for the curve
 * tooltips. Empty string when the field has no logic.
 */
export function describeLogic( field: Field, fields: Field[] ): string {
	return tokensToText( logicTokens( field, fields ) );
}

/**
 * Every controller → dependent connection in a form.
 *
 * One edge per rule rather than per field: a question shown only when two others
 * are answered a particular way is genuinely tied to both, and collapsing that
 * to a single line would hide half of what the reader came for.
 *
 * Self-references are dropped. A field whose condition names itself can never
 * resolve, and drawing a loop from a card to itself is noise on top of a bug.
 */
export function logicEdges( fields: Field[] ): LogicEdge[] {
	const edges: LogicEdge[] = [];

	for ( const field of fields ) {
		const logic = field.logic;

		if ( ! logic?.enabled ) {
			continue;
		}

		for ( const rule of logic.rules ) {
			if ( ! rule.field || rule.field === field.id ) {
				continue;
			}

			edges.push( {
				from: rule.field,
				to: field.id,
				label: describeRule( rule, fields ),
				short: describeTrigger( rule, fields ),
				action: logic.action,
				broken: labelOf( fields, rule.field ) === null,
			} );
		}
	}

	return edges;
}

/**
 * How many fields each field controls, keyed by field id.
 *
 * Counted per dependent *field*, not per rule. A question shown only when
 * another is answered one of two ways produces two edges and one relationship,
 * and a badge reading "controls 2 fields" when it controls one is worse than no
 * badge.
 */
export function controlCounts( fields: Field[] ): Map< string, number > {
	const counts = new Map< string, number >();
	const seen = new Set< string >();

	for ( const edge of logicEdges( fields ) ) {
		const pair = `${ edge.from }->${ edge.to }`;

		if ( edge.broken || seen.has( pair ) ) {
			continue;
		}

		seen.add( pair );
		counts.set( edge.from, ( counts.get( edge.from ) ?? 0 ) + 1 );
	}

	return counts;
}

/**
 * Kept clear at the far edge so a label never touches the scrollbar.
 *
 * The strip the curves and labels live in is reserved in CSS, not here: the
 * cards fill the canvas column, and `.has-logicmap` gives the list a matching
 * `padding-inline-end`. Without that the curves are drawn past the column and
 * clipped by the canvas's own scroll box — which is how the first version lost
 * every label to the inspector's border.
 */
const MARGIN = 10;

/**
 * Longest label drawn on a curve.
 *
 * SVG text does not wrap and cannot ellipsis itself, so the cut is made here.
 * The full clause is on the card and in the curve's tooltip, so nothing is
 * actually lost — a truncated label is a pointer, not the content.
 */
const LABEL_MAX = 22;

/** The SVG namespace, because `createElement` produces an HTML element. */
const SVG_NS = 'http://www.w3.org/2000/svg';

/**
 * The curve layer over the canvas.
 *
 * Owns one `<svg>` positioned over the field list and redraws it from live
 * element rects. Deliberately ignorant of the schema: it is handed the edges and
 * finds the cards by `data-atfb-card`, so a reorder needs no notification —
 * the next `update()` measures wherever the cards ended up.
 */
export class LogicMap {
	private readonly host: HTMLElement;
	private readonly svg: SVGSVGElement;
	private edges: LogicEdge[] = [];
	private teardowns: Array< () => void > = [];
	private frame = 0;

	public constructor( host: HTMLElement ) {
		this.host = host;
		this.svg = document.createElementNS( SVG_NS, 'svg' ) as SVGSVGElement;

		this.svg.setAttribute( 'class', 'atfb-logicmap' );
		// Decorative: everything it says is also said in words on the cards, so a
		// screen reader that skipped it entirely would lose nothing.
		this.svg.setAttribute( 'aria-hidden', 'true' );
		this.svg.setAttribute( 'focusable', 'false' );

		host.append( this.svg );

		const redraw = () => this.schedule();

		window.addEventListener( 'resize', redraw );
		this.teardowns.push( () => window.removeEventListener( 'resize', redraw ) );

		// The canvas scrolls independently of the window, and the curves are drawn
		// in its coordinate space — so a scroll moves the cards and not the lines
		// unless this is here.
		const scroller = host.closest( '.atfb__canvas' ) ?? host;

		scroller.addEventListener( 'scroll', redraw, { passive: true } );
		this.teardowns.push( () => scroller.removeEventListener( 'scroll', redraw ) );

		// A card resizes when its condition line wraps, and a drag-reorder moves
		// several at once. Observing the host covers both without either of them
		// having to remember to tell us.
		if ( typeof ResizeObserver !== 'undefined' ) {
			const observer = new ResizeObserver( redraw );

			observer.observe( host );
			this.teardowns.push( () => observer.disconnect() );
		}
	}

	/** Replaces the connections and redraws. */
	public setEdges( edges: LogicEdge[] ): void {
		this.edges = edges;
		this.schedule();
	}

	/**
	 * Dims every curve that does not touch a field.
	 *
	 * Passing an empty id restores them all. Applied as a class on the layer
	 * rather than per-path styles so the transition is one paint.
	 */
	public highlight( fieldId: string ): void {
		this.svg.classList.toggle( 'is-focused', Boolean( fieldId ) );

		this.svg.querySelectorAll< SVGElement >( '[data-from]' ).forEach( ( node ) => {
			const touches = fieldId && ( node.dataset.from === fieldId || node.dataset.to === fieldId );

			node.classList.toggle( 'is-lit', Boolean( touches ) );
		} );
	}

	/** Removes the layer and every listener. */
	public destroy(): void {
		this.teardowns.forEach( ( teardown ) => teardown() );
		this.teardowns = [];
		this.svg.remove();

		if ( this.frame ) {
			cancelAnimationFrame( this.frame );
		}
	}

	/** Coalesces redraw requests to one per frame. */
	private schedule(): void {
		if ( this.frame ) {
			return;
		}

		this.frame = requestAnimationFrame( () => {
			this.frame = 0;
			this.draw();
		} );
	}

	/** Measures the cards and rebuilds every path. */
	private draw(): void {
		this.svg.replaceChildren();

		const host = this.host.getBoundingClientRect();

		this.svg.setAttribute( 'viewBox', `0 0 ${ host.width } ${ host.height }` );
		this.svg.setAttribute( 'width', String( host.width ) );
		this.svg.setAttribute( 'height', String( host.height ) );

		// Lanes keep two curves between the same pair of cards from being drawn on
		// top of each other. Each additional edge sharing a source bows a little
		// further out, which is what makes a form with several dependencies on one
		// question legible rather than a single thick smear.
		const lanes = new Map< string, number >();

		for ( const edge of this.edges ) {
			const from = this.cardRect( edge.from );
			const to = this.cardRect( edge.to );

			if ( ! from || ! to ) {
				continue;
			}

			const lane = lanes.get( edge.from ) ?? 0;

			lanes.set( edge.from, lane + 1 );

			this.drawEdge( edge, from, to, host, lane );
		}
	}

	/** One card's box, in the host's coordinate space. */
	private cardRect( fieldId: string ): DOMRect | null {
		const card = this.host.querySelector< HTMLElement >(
			`[data-atfb-card="${ CSS.escape( fieldId ) }"]`
		);

		return card ? card.getBoundingClientRect() : null;
	}

	/** Draws one connection and its label. */
	private drawEdge( edge: LogicEdge, from: DOMRect, to: DOMRect, host: DOMRect, lane: number ): void {
		const startX = from.right - host.left;
		const startY = from.top + from.height / 2 - host.top;
		const endX = to.right - host.left;
		const endY = to.top + to.height / 2 - host.top;

		// Both ends leave the cards' right edge and the curve bulges into the
		// reserved strip, so it travels through empty canvas rather than over the
		// cards between them. Lanes fan concurrent curves apart; the whole fan is
		// then clamped to the strip, so a form with six dependencies on one
		// question stays inside the column instead of walking off the edge.
		// The curve keeps to the inner third of the strip and the labels take the
		// outer two thirds. Letting the bow use the whole strip put the widest part
		// of the curve exactly where the text goes, and a line through the middle
		// of a word is not fixed by a halo.
		const available = Math.max( 0, host.width - MARGIN - Math.max( startX, endX ) );
		const wanted = 22 + lane * 11 + Math.min( 22, Math.abs( endY - startY ) / 8 );
		const reach = Math.max( 12, Math.min( wanted, available * 0.4 ) );

		const path = document.createElementNS( SVG_NS, 'path' );

		path.setAttribute(
			'd',
			`M ${ startX } ${ startY } C ${ startX + reach } ${ startY }, ${ endX + reach } ${ endY }, ${ endX } ${ endY }`
		);
		path.setAttribute( 'class', `atfb-logicmap__path is-${ edge.action }${ edge.broken ? ' is-broken' : '' }` );
		path.dataset.from = edge.from;
		path.dataset.to = edge.to;

		const title = document.createElementNS( SVG_NS, 'title' );

		title.textContent = edge.label;
		path.append( title );

		const dot = document.createElementNS( SVG_NS, 'circle' );

		dot.setAttribute( 'cx', String( endX ) );
		dot.setAttribute( 'cy', String( endY ) );
		dot.setAttribute( 'r', '3.5' );
		dot.setAttribute( 'class', `atfb-logicmap__dot is-${ edge.action }${ edge.broken ? ' is-broken' : '' }` );
		dot.dataset.from = edge.from;
		dot.dataset.to = edge.to;

		this.svg.append( path, dot );

		// The label is right-aligned at the outer edge of the strip, level with the
		// field it points *at* — so reading down the column, every dependent field
		// has its trigger beside it and they line up with each other.
		const text = document.createElementNS( SVG_NS, 'text' );

		text.setAttribute( 'x', String( host.width - MARGIN ) );
		text.setAttribute( 'y', String( endY + 3 ) );
		text.setAttribute( 'text-anchor', 'end' );
		text.setAttribute( 'class', 'atfb-logicmap__label' );
		text.dataset.from = edge.from;
		text.dataset.to = edge.to;
		text.textContent =
			edge.short.length > LABEL_MAX ? `${ edge.short.slice( 0, LABEL_MAX - 1 ) }…` : edge.short;

		const labelTitle = document.createElementNS( SVG_NS, 'title' );

		labelTitle.textContent = edge.label;
		text.append( labelTitle );

		this.svg.append( text );
	}
}

/**
 * Answer recall: showing a visitor's earlier answer inside the form itself.
 *
 * "Nice to meet you, {field:name}. How old are you?" — the same `{field:…}` tag
 * notifications and confirmations already understand, so there is one grammar to
 * learn rather than two. In a label, a hint, a heading or an HTML block the tag
 * becomes a `<span class="atf-recall" data-atf-recall="name">` on the server
 * (`alltfo_recall_markup()`), and the form bundle fills it live as the visitor
 * answers. Until they answer, it is blank.
 *
 * This module is the one place that knows which answers can be recalled and how
 * each reads as text, so the front end and the builder's picker cannot disagree
 * about what a recalled answer looks like.
 */

import type { Field, FieldValue, MergeTag, MergeTagGroup } from '../types';

/** `{field:id}` — the only tag that recalls; everything else waits for submission. */
export const RECALL_PATTERN = /\{field:([a-zA-Z0-9_-]+)\}/g;

/**
 * Text with every recall tag replaced.
 *
 * @param template The text as written.
 * @param answer   Resolves one field id to its shown answer.
 * @return The filled text.
 */
export function fillRecall( template: string, answer: ( id: string ) => string ): string {
	return template.replace( /\{field:([a-zA-Z0-9_-]+)\}/g, ( _match, id: string ) => answer( id ) );
}

/**
 * Types whose answer is never echoed back into the form.
 *
 * A password must never appear on screen, a file or signature has no text worth
 * showing, a repeater is many answers, and the layout blocks hold no answer at
 * all.
 */
const UNRECALLABLE = [ 'password', 'file', 'signature', 'repeater', 'page_break', 'heading', 'html', 'divider', 'spacer' ];

/** Whether a field's answer can be shown elsewhere in the form. */
export function recallable( field: Field ): boolean {
	return ! UNRECALLABLE.includes( field.type );
}

/** The label a stored choice value is shown as. */
function choiceLabel( field: Field, value: unknown ): string {
	const match = ( field.choices ?? [] ).find( ( choice ) => String( choice.value ) === String( value ) );

	return match && match.label ? String( match.label ) : String( value ?? '' );
}

/** Words for a yes/no answer, translated by the host. */
export interface RecallWords {
	yes: string;
	no: string;
}

/**
 * How one answer reads when it is recalled.
 *
 * Choices read as their labels, not their stored values — the visitor picked
 * "Large (£12)", not `l`. A composite reads as its non-empty parts. An
 * unanswered field reads as nothing, because a placeholder like "your name"
 * shown to the person who is about to type their name reads as a bug.
 *
 * @param field The field being recalled.
 * @param value Its current value.
 * @param words Yes/no wording.
 * @return The text to show.
 */
export function recallText( field: Field, value: FieldValue | undefined, words: RecallWords = { yes: 'Yes', no: 'No' } ): string {
	if ( null === value || undefined === value || '' === value ) {
		return '';
	}

	if ( typeof value === 'boolean' ) {
		return value ? words.yes : words.no;
	}

	const hasChoices = Array.isArray( field.choices ) && field.choices.length > 0;

	if ( Array.isArray( value ) ) {
		return ( value as unknown[] )
			.map( ( item ) => ( hasChoices ? choiceLabel( field, item ) : String( item ?? '' ) ).trim() )
			.filter( Boolean )
			.join( ', ' );
	}

	if ( typeof value === 'object' ) {
		const parts = Object.values( value )
			.map( ( item ) => ( hasChoices ? choiceLabel( field, item ) : String( item ?? '' ) ).trim() )
			.filter( Boolean );

		if ( 'date_range' === field.type ) {
			return parts.join( ' – ' );
		}

		return parts.join( 'name' === field.type ? ' ' : ', ' );
	}

	return hasChoices ? choiceLabel( field, value ) : String( value );
}

/** A plausible answer, so the picker can say what will appear. */
function sampleFor( field: Field ): string {
	const first = ( field.choices ?? [] )[ 0 ];

	if ( first?.label ) {
		return String( first.label );
	}

	switch ( field.type ) {
		case 'email':
			return 'ada@example.com';
		case 'name':
			return 'Ada Lovelace';
		case 'number':
		case 'range':
		case 'scale':
		case 'rating':
			return '3';
		case 'total':
			return '42.00';
		case 'switch':
		case 'consent':
			return 'Yes';
		case 'tel':
			return '+34 600 123 456';
		case 'url':
			return 'https://example.com';
		case 'country':
			return 'Spain';
		case 'color':
			return '#3366ff';
		case 'date':
			return '24/05/2026';
		case 'time':
			return '09:30';
		case 'datetime':
			return '24/05/2026 09:30';
		case 'date_range':
			return '24/05/2026 – 28/05/2026';
		case 'textarea':
			return 'Whatever they wrote';
		case 'text':
			return 'Ada';
		default:
			return 'Their answer';
	}
}

/**
 * A label as a person reads it in a list, with its own recall tags named.
 *
 * "{field:name} choose the brand colour" is a label that recalls an answer,
 * and in a picker or a chip the raw tag is noise. It reads as "‹Your name›
 * choose the brand colour" instead — the question it points at, marked as a
 * stand-in.
 *
 * @param field  The field whose label is shown.
 * @param fields Every field, to name the ones the label recalls.
 * @return The readable label, or its id when it has none.
 */
export function readableLabel( field: Field, fields: Field[] ): string {
	const label = String( field.label ?? '' );

	if ( ! label ) {
		return field.id;
	}

	return fillRecall( label, ( id ) => {
		const target = fields.find( ( candidate ) => candidate.id === id );

		return `‹${ target ? String( target.label ?? '' ).replace( /\{field:[a-zA-Z0-9_-]+\}/g, '…' ) || id : id }›`;
	} );
}

/** One picker entry for a recallable field. */
function recallItem( field: Field, later: boolean, fields: Field[] ): MergeTag {
	return {
		tag: `{field:${ field.id }}`,
		label: field.label ? readableLabel( field, fields ) : 'Untitled question',
		hint: later
			? 'Comes later in the form, so this stays blank until they get there and answer it.'
			: 'Fills in as soon as they answer it. Blank until then.',
		sample: sampleFor( field ),
		type: field.type,
	};
}

/**
 * The picker catalogue for text shown inside the form.
 *
 * Only answers: the site name, the entry number and the visitor's IP are
 * resolved when the form is *submitted*, and a label is read long before that.
 * Earlier questions come first, since "Thanks, {name}" on the question after
 * Name is what this is nearly always for. The question being edited comes
 * next, because recall is live — a hint under Name reading "We will write to
 * you as {field:name}" fills in while they type. Later ones close the list,
 * with a note saying why they start blank.
 *
 * @param fields      The form's top-level fields.
 * @param current     The field whose text is being edited.
 * @param includeSelf Whether to offer the field's own answer. Off for its
 *                    placeholder, which is gone the moment there is an answer.
 * @return Picker groups.
 */
export function recallGroups( fields: Field[], current: string, includeSelf = true ): MergeTagGroup[] {
	const index = fields.findIndex( ( field ) => field.id === current );
	const usable = ( field: Field ) => field.id !== current && recallable( field );
	const earlier = index < 0 ? fields.filter( usable ) : fields.slice( 0, index ).filter( usable );
	const later = index < 0 ? [] : fields.slice( index + 1 ).filter( usable );
	const self = includeSelf && index >= 0 && recallable( fields[ index ] ) ? fields[ index ] : null;

	const groups: MergeTagGroup[] = [
		{
			id: 'earlier',
			label: 'Their earlier answers',
			items: earlier.map( ( field ) => recallItem( field, false, fields ) ),
			empty: later.length || self
				? 'Nothing comes before this question yet — the other answers are below.'
				: 'Add another question and its answer can be shown here.',
		},
	];

	if ( self ) {
		groups.push( {
			id: 'self',
			label: 'This question',
			items: [
				{
					...recallItem( self, false, fields ),
					hint: 'Their answer to this very question, filling in letter by letter as they type it.',
				},
			],
		} );
	}

	if ( later.length ) {
		groups.push( {
			id: 'later',
			label: 'Answers from later in the form',
			items: later.map( ( field ) => recallItem( field, true, fields ) ),
		} );
	}

	return groups;
}

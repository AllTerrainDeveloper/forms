/** Copy a complete condition between fields, notifications and confirmations. */
import { button, el, row, select } from './ui';
import { ruleTokens, tokensToText } from './logic-map';
import type { FormSchema, Logic } from './types';

/** A section that owns its own independent condition. */
export interface ConditionSection {
	key: string;
	label: string;
	fieldId?: string;
	logic: Logic;
}

/** Lists editable sections using names the form author recognises. */
export function conditionSections( schema: FormSchema ): ConditionSection[] {
	return [
		...schema.fields.filter( ( field ) => field.type !== 'page_break' ).map( ( field ) => ( {
			key: `field:${ field.id }`, label: `Field: ${ field.label || field.id }`, fieldId: field.id, logic: field.logic,
		} ) ),
		...schema.notifications.map( ( item ) => ( {
			key: `notification:${ item.id }`, label: `Notification: ${ item.name || item.id }`, logic: item.logic,
		} ) ),
		...schema.confirmations.map( ( item ) => ( {
			key: `confirmation:${ item.id }`, label: `Confirmation: ${ item.name || item.id }`, logic: item.logic,
		} ) ),
	];
}

/** Reject self-copy, missing references and rules that would depend on themselves. */
export function canCopyCondition( source: ConditionSection, target: ConditionSection, schema: FormSchema ): boolean {
	return source.key !== target.key && source.logic.rules.length > 0 && source.logic.rules.every(
		( rule ) => rule.field !== target.fieldId && schema.fields.some( ( field ) => field.id === rule.field && field.type !== 'page_break' )
	);
}

/** Copies values, never rule objects; editing the copy must leave the source intact. */
export function copyCondition( schema: FormSchema, from: string, to: string, beforeCopy?: () => void ): boolean {
	const sections = conditionSections( schema );
	const source = sections.find( ( item ) => item.key === from );
	const target = sections.find( ( item ) => item.key === to );
	if ( ! source || ! target || ! canCopyCondition( source, target, schema ) ) {
		return false;
	}
	beforeCopy?.();
	Object.assign( target.logic, source.logic, { rules: source.logic.rules.map( ( rule ) => ( { ...rule } ) ) } );
	return true;
}

/** Opens a chooser with the whole condition visible before applying it. */
export function openConditionCopy( options: {
	root: HTMLElement;
	schema: () => FormSchema | null;
	to: string;
	beforeCopy?: () => void;
	onCopy: () => void;
} ): void {
	const previous = document.activeElement as HTMLElement | null;
	const overlay = el( 'div', { class: 'atfb-overlay' } );
	const dialog = el( 'div', {
		class: 'atfb-modal atfb-condition-copy',
		attrs: { role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Copy condition', tabindex: '-1' },
	} );
	let from = '';
	const to = options.to;
	const close = () => {
		overlay.remove();
		previous?.focus();
	};

	const paint = () => {
		const schema = options.schema();
		if ( ! schema ) {
			close();
			return;
		}
		const sections = conditionSections( schema );
		const target = sections.find( ( item ) => item.key === to );
		const sources = sections.filter( ( item ) => item.logic.rules.length &&
			target && canCopyCondition( item, target, schema ) );
		const source = sources.find( ( item ) => item.key === from );
		const sourcePicker = select( from, [ { value: '', label: 'Choose a section…' }, ...sources.map( ( item ) => ( { value: item.key, label: item.label } ) ) ], ( value ) => {
			from = value;
			paint();
			dialog.querySelector< HTMLElement >( '[aria-label="Copy from"]' )?.focus();
		} );
		sourcePicker.setAttribute( 'aria-label', 'Copy from' );
		const apply = button( 'Copy condition', () => {
			const live = options.schema();
			if ( live && copyCondition( live, from, to, options.beforeCopy ) ) {
				close();
				options.onCopy();
			}
		}, 'primary' );
		if ( ! source || ! to ) {
			apply.setAttribute( 'disabled', '' );
		}
		const destination = sections.find( ( item ) => item.key === to );
		dialog.replaceChildren(
			el( 'h2', { text: 'Copy condition' } ),
			row( 'Copy from', sourcePicker ),
			el( 'p', { class: 'atfb-hint', text: `Apply to ${ destination?.label ?? 'this section' }.` } ),
			el( 'p', { class: 'atfb-condition-copy__preview', attrs: { 'aria-live': 'polite' }, text: source
				? `${ source.logic.enabled ? 'Enabled' : 'Disabled' } · ${ source.logic.action === 'hide' ? 'Hide' : 'Show' } · Match ${ source.logic.match }: ` +
					source.logic.rules.map( ( rule ) => tokensToText( ruleTokens( rule, schema.fields ) ) ).join( source.logic.match === 'all' ? ' and ' : ' or ' )
				: ( sources.length ? 'Choose the section whose condition you want to reuse.' : 'No conditions are available to copy here yet.' ) } ),
			el( 'p', { class: 'atfb-hint', text: destination?.logic.rules.length
				? 'This replaces the destination’s entire condition. You can edit the copy independently.'
				: 'Copies every rule, all/any matching, show/hide and enabled state. You can edit the copy independently.' } ),
			el( 'div', { class: 'atfb-modal__actions', children: [ button( 'Cancel', close ), apply ] } )
		);
	};
	overlay.append( dialog );
	overlay.addEventListener( 'click', ( event ) => { if ( event.target === overlay ) close(); } );
	overlay.addEventListener( 'keydown', ( event ) => {
		if ( event.key === 'Escape' ) {
			event.preventDefault();
			close();
		}
		if ( event.key === 'Tab' ) {
			const controls = [ ...dialog.querySelectorAll< HTMLElement >( 'button:not([disabled]), select, os-select, os-button:not([disabled])' ) ];
			const first = controls[ 0 ];
			const last = controls[ controls.length - 1 ];
			if ( event.shiftKey && ( document.activeElement === first || document.activeElement === dialog ) ) {
				event.preventDefault();
				last?.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first?.focus();
			}
		}
		event.stopPropagation();
	} );
	options.root.append( overlay );
	paint();
	dialog.focus();
}

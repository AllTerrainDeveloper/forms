/** Allowed answers for conditional-value selectors, shared by every editor. */
import type { Field } from './types';

export interface ConditionValueOption {
	value: string;
	label: string;
	disabled?: boolean;
}

/** Mirrors the rendered controls; null means the answer is open-ended. */
export function conditionValueOptions(
	field: Field | undefined,
	current: string,
	countries: Record< string, string > = {}
): ConditionValueOption[] | null {
	if ( ! field ) return null;
	let options: ConditionValueOption[];
	const number = ( key: string, fallback: number ) => {
		const value = field[ key ];
		return value === undefined || value === null || ! Number.isFinite( Number( value ) ) ? fallback : Number( value );
	};
	const sequence = ( min: number, max: number, step = 1 ) => Array.from(
		{ length: Math.floor( ( max - min ) / step + 1e-9 ) + 1 },
		( _, index ) => {
			const value = String( Number( ( min + index * step ).toPrecision( 12 ) ) );
			return { value, label: value };
		}
	);

	if ( field.type === 'scale' ) {
		const min = Math.trunc( number( 'min', 0 ) );
		let max = Math.trunc( number( 'max', 10 ) );
		if ( max <= min ) max = min + 10;
		options = sequence( min, Math.min( max, min + 20 ) );
	} else if ( field.type === 'rating' ) {
		options = sequence( 1, Math.max( 2, Math.min( 10, Math.abs( Math.trunc( number( 'max', 5 ) ) ) ) ) );
	} else if ( field.type === 'switch' || field.type === 'consent' ) {
		// Boolean answers compare as "1" and "" in both logic engines.
		options = [ { value: '1', label: 'Checked' }, { value: '', label: 'Unchecked' } ];
	} else if ( field.type === 'country' ) {
		options = Object.entries( countries ).map( ( [ value, label ] ) => ( { value, label } ) );
	} else if ( field.type === 'range' ) {
		const min = number( 'min', 0 );
		const max = number( 'max', 100 );
		const step = field.step === 'any' ? 0 : number( 'step', 1 );
		// Continuous or enormous ranges cannot be represented by a useful menu.
		if ( step <= 0 || max < min || ( max - min ) / step > 999 ) return null;
		options = sequence( min, max, step );
	} else if ( field.choices?.length || [ 'select', 'multiselect', 'radio', 'checkboxes', 'image_choice', 'quiz', 'likert' ].includes( field.type ) ) {
		options = ( field.choices ?? [] ).map( ( choice ) => ( { value: choice.value, label: choice.label || choice.value } ) );
	} else {
		return null;
	}

	if ( ! options.some( ( option ) => option.value === current ) && current !== '' ) {
		// Keep old rules honest without offering an obsolete answer to new rules.
		options.unshift( { value: current, label: `${ current } (stored value; unavailable)`, disabled: true } );
	}
	if ( ! options.some( ( option ) => option.value === '' ) ) {
		options.unshift( { value: '', label: 'Choose a value…', disabled: true } );
	}
	return options;
}

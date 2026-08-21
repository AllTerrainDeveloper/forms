/**
 * The front-end bundle.
 *
 * Everything here is *enhancement*. The form it attaches to already works: it is
 * a real `<form>` with a real action, it posts, the server validates it, and it
 * comes back with errors against the right fields and the answers still in
 * place. If this file fails to load, or throws, or the visitor has scripting
 * off, the form still collects submissions.
 *
 * What it adds: conditional logic as you type, live calculated totals, step
 * navigation on multi-page forms, inline validation, repeater rows, the
 * signature pad, and an AJAX submit that does not reload the page.
 *
 * Nothing decided here is trusted by the server. The server recomputes
 * visibility, recomputes every total, and revalidates everything.
 */

import { applyCalculations } from './shared/calc';
import { isEmptyValue, visibleFields } from './shared/logic';
import { presetPasses, validationPreset } from './shared/validation';
import type { Field, FieldValue, RuntimeConfig, SubmissionResult, Values } from './types';

/** The reduced schema the renderer prints beside each form. */
interface ClientSchema {
	fields: Field[];
	settings: { ajax: boolean; progressBar: string };
}

const config: RuntimeConfig | undefined = ( window as unknown as { allTerrainForms?: RuntimeConfig } ).allTerrainForms;

/** Translated strings, with English fallbacks so a missing config never blanks the UI. */
const i18n = ( key: string, fallback: string ): string => config?.i18n?.[ key ] ?? fallback;

/**
 * One live form on the page.
 *
 * A class per form rather than one module-level controller, because a page can
 * legitimately hold several forms and every piece of state here — values,
 * current step, whether it has been started — is per form.
 */
class AllTerrainForm {
	private readonly form: HTMLFormElement;
	private readonly schema: ClientSchema;
	private readonly pages: HTMLElement[];
	private readonly errorSummary: HTMLElement | null;

	private step = 0;
	private started = false;
	private submitting = false;

	public constructor( form: HTMLFormElement, schema: ClientSchema ) {
		this.form = form;
		this.schema = schema;
		this.pages = Array.from( form.querySelectorAll< HTMLElement >( '[data-atf-page]' ) );
		this.errorSummary = form.querySelector< HTMLElement >( '.atf-errors' );

		this.bind();
		this.showStep( 0, false );
		this.update();
	}

	/** Wires every listener. */
	private bind(): void {
		// One delegated listener per event rather than one per control, so
		// fields cloned into a repeater row are live the moment they appear
		// without anything having to re-bind them.
		this.form.addEventListener( 'input', ( event ) => this.onInput( event ) );
		this.form.addEventListener( 'change', ( event ) => this.onInput( event ) );
		this.form.addEventListener( 'submit', ( event ) => void this.onSubmit( event ) );

		this.form.addEventListener( 'click', ( event ) => {
			const target = event.target as HTMLElement;

			if ( target.closest( '[data-atf-next]' ) ) {
				event.preventDefault();
				this.next();
			}

			if ( target.closest( '[data-atf-prev]' ) ) {
				event.preventDefault();
				this.previous();
			}

			if ( target.closest( '[data-atf-repeater-add]' ) ) {
				event.preventDefault();
				this.addRepeaterRow( target.closest( '[data-atf-repeater]' ) as HTMLElement | null );
			}

			if ( target.closest( '[data-atf-repeater-remove]' ) ) {
				event.preventDefault();
				this.removeRepeaterRow( target.closest( '[data-atf-repeater-row]' ) as HTMLElement | null );
			}

			if ( target.closest( '[data-atf-resume]' ) ) {
				event.preventDefault();
				void this.saveForLater();
			}
		} );

		// Validation on blur, not on every keystroke. Telling somebody their
		// email address is invalid while they are still typing the `@` is
		// technically true and reliably infuriating.
		this.form.addEventListener(
			'blur',
			( event ) => {
				const field = ( event.target as HTMLElement )?.closest?.< HTMLElement >( '[data-atf-field]' );

				if ( field ) {
					this.validateField( field );
				}
			},
			true
		);

		this.form.querySelectorAll< HTMLElement >( '[data-atf-signature]' ).forEach( ( pad ) => {
			this.initSignature( pad );
		} );

		this.form.querySelectorAll< HTMLInputElement >( '.atf-range__input' ).forEach( ( range ) => {
			const output = range.parentElement?.querySelector( '.atf-range__output' );

			range.addEventListener( 'input', () => {
				if ( output ) {
					output.textContent = range.value;
				}
			} );
		} );

		this.initOtherToggles();
	}

	/** Reads every value out of the DOM. */
	public values(): Values {
		const values: Values = {};

		for ( const field of this.schema.fields ) {
			values[ field.id ] = this.readField( field );
		}

		return values;
	}

	/** Reads one field's value. */
	private readField( field: Field ): FieldValue {
		const wrapper = this.fieldElement( field.id );

		if ( ! wrapper ) {
			return null;
		}

		const inputs = Array.from(
			wrapper.querySelectorAll< HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement >(
				'input, select, textarea'
			)
		).filter( ( input ) => ! input.disabled && ! input.closest( 'template' ) );

		if ( ! inputs.length ) {
			return null;
		}

		// A composite (a name, an address, a Likert row set) posts several named
		// parts and reads back as an object keyed by the part.
		if ( [ 'name', 'address', 'date_range', 'likert' ].includes( field.type ) ) {
			const object: Record< string, unknown > = {};

			for ( const input of inputs ) {
				const key = input.name.match( /\[([^\]]+)\]$/ )?.[ 1 ];

				if ( ! key ) {
					continue;
				}

				if ( input instanceof HTMLInputElement && ( input.type === 'radio' || input.type === 'checkbox' ) ) {
					if ( input.checked ) {
						object[ key ] = input.value;
					}

					continue;
				}

				object[ key ] = input.value;
			}

			return object;
		}

		const checkboxes = inputs.filter(
			( input ): input is HTMLInputElement => input instanceof HTMLInputElement && input.type === 'checkbox'
		);

		if ( checkboxes.length ) {
			// A single unnamed-array checkbox is a toggle or a consent box: a
			// boolean, not a one-item list.
			if ( checkboxes.length === 1 && ! checkboxes[ 0 ].name.endsWith( '[]' ) ) {
				return checkboxes[ 0 ].checked;
			}

			return checkboxes.filter( ( input ) => input.checked ).map( ( input ) => input.value );
		}

		const radios = inputs.filter(
			( input ): input is HTMLInputElement => input instanceof HTMLInputElement && input.type === 'radio'
		);

		if ( radios.length ) {
			return radios.find( ( input ) => input.checked )?.value ?? '';
		}

		const first = inputs[ 0 ];

		if ( first instanceof HTMLSelectElement && first.multiple ) {
			return Array.from( first.selectedOptions ).map( ( option ) => option.value );
		}

		if ( first instanceof HTMLInputElement && first.type === 'file' ) {
			// Files are not readable as values; what matters to logic and
			// validation is only whether one was chosen.
			return first.files && first.files.length ? [ String( first.files.length ) ] : [];
		}

		return first.value;
	}

	/** The wrapper element for a field. */
	private fieldElement( fieldId: string ): HTMLElement | null {
		return this.form.querySelector< HTMLElement >( `[data-atf-field="${ CSS.escape( fieldId ) }"]` );
	}

	/** Reacts to any change: recompute logic, recompute totals, count the start. */
	private onInput( event: Event ): void {
		const target = event.target as HTMLElement | null;

		if ( target?.closest( 'template' ) ) {
			return;
		}

		if ( ! this.started ) {
			this.started = true;
			this.reportStart();
		}

		this.update();

		// Clearing an error the moment it is fixed is the one piece of live
		// feedback that is always welcome — it is confirmation, not criticism.
		const field = target?.closest< HTMLElement >( '[data-atf-field]' );

		if ( field?.classList.contains( 'has-error' ) ) {
			this.validateField( field );
		}
	}

	/** Applies conditional logic and calculations to the current DOM. */
	private update(): void {
		const values = this.values();
		const visible = visibleFields( this.schema.fields, values );

		for ( const field of this.schema.fields ) {
			const element = this.fieldElement( field.id );

			if ( ! element ) {
				continue;
			}

			const show = visible[ field.id ] !== false;

			if ( element.hidden === show ) {
				element.hidden = ! show;
			}

			// A hidden field's controls are disabled as well as hidden, so the
			// browser does not submit them and does not block submission on a
			// `required` control the visitor cannot see or reach.
			element
				.querySelectorAll< HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement >( 'input, select, textarea' )
				.forEach( ( input ) => {
					if ( input.closest( 'template' ) ) {
						return;
					}

					// The "Other" box has its own owner — the choice it belongs
					// to enables it only while that choice is picked. Showing
					// the field must not re-enable a box whose choice is
					// unchecked, so this defers rather than fighting it.
					if ( input.hasAttribute( 'data-atf-other-input' ) ) {
						if ( ! show ) {
							input.disabled = true;
						}

						return;
					}

					input.disabled = ! show;
				} );
		}

		const calculated = applyCalculations( this.schema.fields, values );

		for ( const field of this.schema.fields ) {
			if ( ! field.formula ) {
				continue;
			}

			const input = this.fieldElement( field.id )?.querySelector< HTMLInputElement >( '[data-atf-total]' );

			if ( ! input ) {
				continue;
			}

			const value = calculated[ field.id ];
			const decimals = typeof field.decimals === 'number' ? field.decimals : 2;

			input.value = typeof value === 'number' ? value.toFixed( decimals ) : '';
		}
	}

	/* ---------------------------------------------------------------- Steps */

	/** Shows one page of a multi-page form. */
	private showStep( index: number, focus = true ): void {
		if ( this.pages.length < 2 ) {
			return;
		}

		this.step = Math.max( 0, Math.min( this.pages.length - 1, index ) );

		this.pages.forEach( ( page, position ) => {
			page.hidden = position !== this.step;
			// The server-side marker is removed once this file is driving the
			// steps, so a page is never both hidden by the attribute and shown
			// by the controller.
			delete page.dataset.atfPageHidden;
		} );

		this.updateProgress();

		if ( focus ) {
			// Focus moves to the page itself rather than its first field, so a
			// screen reader announces the step before its contents.
			const page = this.pages[ this.step ];

			page.setAttribute( 'tabindex', '-1' );
			page.focus( { preventScroll: true } );
			page.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}
	}

	/** Repaints the step indicator. */
	private updateProgress(): void {
		const bar = this.form.querySelector< HTMLElement >( '.atf-progress--bar' );

		if ( bar ) {
			const fill = bar.querySelector< HTMLElement >( '.atf-progress__fill' );
			const percent = ( ( this.step + 1 ) / this.pages.length ) * 100;

			if ( fill ) {
				fill.style.width = `${ percent }%`;
			}

			bar.setAttribute( 'aria-valuenow', String( this.step + 1 ) );
		}

		this.form.querySelectorAll< HTMLElement >( '.atf-progress__step' ).forEach( ( step, index ) => {
			step.classList.toggle( 'is-current', index === this.step );
			step.classList.toggle( 'is-done', index < this.step );

			if ( index === this.step ) {
				step.setAttribute( 'aria-current', 'step' );
			} else {
				step.removeAttribute( 'aria-current' );
			}
		} );
	}

	/** Moves forward, if this page validates. */
	private next(): void {
		if ( ! this.validatePage( this.step ) ) {
			return;
		}

		this.showStep( this.step + 1 );
	}

	/** Moves back. Never validates — going back to fix something must always work. */
	private previous(): void {
		this.showStep( this.step - 1 );
	}

	/* ----------------------------------------------------------- Validation */

	/**
	 * Validates every visible field on one page.
	 *
	 * Only the current page, because a multi-page form that refused to advance
	 * over a problem three steps ahead would be unusable.
	 */
	private validatePage( index: number ): boolean {
		const page = this.pages[ index ];

		if ( ! page ) {
			return true;
		}

		const fields = Array.from( page.querySelectorAll< HTMLElement >( '[data-atf-field]' ) );
		let firstBad: HTMLElement | null = null;

		for ( const field of fields ) {
			if ( ! this.validateField( field ) && ! firstBad ) {
				firstBad = field;
			}
		}

		if ( firstBad ) {
			this.focusField( firstBad );

			return false;
		}

		return true;
	}

	/** Validates one field and paints the result. */
	private validateField( element: HTMLElement ): boolean {
		if ( element.hidden ) {
			this.setFieldError( element, '' );

			return true;
		}

		const fieldId = element.dataset.atfField ?? '';
		const field = this.schema.fields.find( ( candidate ) => candidate.id === fieldId );

		if ( ! field ) {
			return true;
		}

		const value = this.readField( field );
		const error = this.checkField( field, value );

		this.setFieldError( element, error );

		return error === '';
	}

	/**
	 * The client's copy of the validation rules.
	 *
	 * A deliberate subset of the server's: everything here is a rule the browser
	 * can check without a round trip. Uniqueness and the spam checks are not,
	 * and are left to the server rather than guessed at.
	 */
	private checkField( field: Field, value: FieldValue ): string {
		const messages = ( field.messages ?? {} ) as Record< string, string >;
		const empty = isEmptyValue( value );

		if ( field.required && empty ) {
			return messages.required || i18n( 'required', 'This is required.' );
		}

		if ( empty ) {
			return '';
		}

		if ( typeof value === 'string' ) {
			if ( field.type === 'email' && ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value ) ) {
				return messages.invalid || i18n( 'invalidEmail', 'That does not look like an email address.' );
			}

			if ( field.type === 'url' && ! /^https?:\/\/[^\s]+$/i.test( value ) ) {
				return messages.invalid || i18n( 'invalidUrl', 'That does not look like a web address.' );
			}

			const min = Number( field.minlength );
			const max = Number( field.maxlength );

			if ( field.minlength && value.length < min ) {
				return messages.min || i18n( 'tooShort', 'That is too short.' );
			}

			if ( field.maxlength && value.length > max ) {
				return messages.max || i18n( 'tooLong', 'That is too long.' );
			}

			// A named answer shape — "an email address", "a ZIP code" — checked
			// against the same preset table the server enforces. An unknown
			// slug returns null and is left to the server, which knows its own
			// presets.
			const presetSlug =
				'string' === typeof field.validation && '' !== field.validation && 'custom' !== field.validation
					? field.validation
					: '';

			if ( presetSlug && false === presetPasses( presetSlug, value ) ) {
				return (
					messages.invalid ||
					validationPreset( presetSlug )?.message ||
					i18n( 'badFormat', 'That is not in the expected format.' )
				);
			}

			if ( field.pattern ) {
				try {
					if ( ! new RegExp( String( field.pattern ) ).test( value ) ) {
						return messages.invalid || i18n( 'badFormat', 'That is not in the expected format.' );
					}
				} catch {
					// A pattern the form's author typed wrongly is their
					// mistake, not the visitor's. The server takes the same
					// view and lets the field pass.
				}
			}
		}

		if ( value !== '' && ! Number.isNaN( Number( value ) ) && typeof value !== 'boolean' && ! Array.isArray( value ) ) {
			const numeric = Number( value );

			if ( field.min !== undefined && field.min !== '' && numeric < Number( field.min ) ) {
				return messages.min || i18n( 'tooSmall', 'That number is too small.' );
			}

			if ( field.max !== undefined && field.max !== '' && numeric > Number( field.max ) ) {
				return messages.max || i18n( 'tooBig', 'That number is too large.' );
			}
		}

		if ( Array.isArray( value ) ) {
			const chosen = value.filter( ( item ) => item !== '' ).length;

			if ( field.minChoices && chosen < Number( field.minChoices ) ) {
				return messages.min || i18n( 'required', 'This is required.' );
			}

			if ( field.maxChoices && chosen > Number( field.maxChoices ) ) {
				return messages.max || i18n( 'tooBig', 'That is too many.' );
			}
		}

		return '';
	}

	/** Paints or clears one field's error. */
	private setFieldError( element: HTMLElement, message: string ): void {
		const error = element.querySelector< HTMLElement >( '.atf-error' );

		element.classList.toggle( 'has-error', message !== '' );

		if ( error ) {
			error.textContent = message;
			error.hidden = message === '';
		}

		element
			.querySelectorAll< HTMLElement >( 'input, select, textarea' )
			.forEach( ( input ) => {
				if ( message === '' ) {
					input.removeAttribute( 'aria-invalid' );
				} else {
					input.setAttribute( 'aria-invalid', 'true' );
				}
			} );
	}

	/** Moves focus to a field's first control. */
	private focusField( element: HTMLElement ): void {
		const input = element.querySelector< HTMLElement >( 'input, select, textarea' );

		( input ?? element ).focus?.( { preventScroll: true } );
		element.scrollIntoView( { behavior: 'smooth', block: 'center' } );
	}

	/* ----------------------------------------------------------- Submission */

	/** Handles the submit. */
	private async onSubmit( event: SubmitEvent ): Promise< void > {
		// Enter in a text field submits the form even when the submit button is
		// on a later, hidden page — the spec calls it implicit submission, and
		// hiding the button does not disable it. Mid-form, that keypress means
		// "next step", not "send everything now", so it takes the same path as
		// the Next button instead.
		if ( this.pages.length > 1 && this.step < this.pages.length - 1 ) {
			event.preventDefault();
			this.next();

			return;
		}

		// Every page is validated, not just the last one — a field that failed
		// on page one is still a field that must be fixed, and the visitor has
		// to be taken back to it rather than told about it in the abstract.
		for ( let index = 0; index < this.pages.length; index++ ) {
			if ( ! this.validatePage( index ) ) {
				event.preventDefault();
				this.showStep( index );
				this.announceErrors();

				return;
			}
		}

		if ( ! this.schema.settings.ajax ) {
			// Left to the browser. The form posts, the server answers, the page
			// reloads with the result — which is exactly the no-JavaScript path,
			// deliberately shared rather than reimplemented.
			return;
		}

		event.preventDefault();

		if ( this.submitting ) {
			return;
		}

		await this.submit();
	}

	/** Posts the form over the REST API. */
	private async submit(): Promise< void > {
		const button = this.form.querySelector< HTMLButtonElement >( '[data-atf-submit]' );
		const status = this.form.querySelector< HTMLElement >( '.atf-status' );

		this.submitting = true;
		button?.classList.add( 'is-busy' );

		if ( button ) {
			button.disabled = true;
		}

		if ( status ) {
			status.textContent = i18n( 'sending', 'Sending…' );
		}

		try {
			const body = new FormData( this.form );
			const response = await fetch( `${ config?.restUrl ?? '' }/submit`, {
				method: 'POST',
				body,
				credentials: 'same-origin',
				headers: config?.nonce ? { 'X-WP-Nonce': config.nonce } : {},
			} );

			const result = ( await response.json() ) as SubmissionResult;

			if ( ! result.success ) {
				this.showServerErrors( result );

				return;
			}

			this.showConfirmation( result );
		} catch {
			// A network failure is the one case where the visitor should be
			// invited to try again rather than told what they did wrong.
			if ( status ) {
				status.textContent = i18n( 'failed', 'That did not send. Please try again.' );
			}
		} finally {
			this.submitting = false;
			button?.classList.remove( 'is-busy' );

			if ( button ) {
				button.disabled = false;
			}
		}
	}

	/** Paints the errors the server sent back. */
	private showServerErrors( result: SubmissionResult ): void {
		const status = this.form.querySelector< HTMLElement >( '.atf-status' );

		if ( status ) {
			status.textContent = '';
		}

		let firstBad: HTMLElement | null = null;
		let firstPage = 0;

		for ( const [ fieldId, message ] of Object.entries( result.errors ?? {} ) ) {
			const element = this.fieldElement( fieldId );

			if ( ! element ) {
				continue;
			}

			this.setFieldError( element, message );

			if ( ! firstBad ) {
				firstBad = element;
				firstPage = this.pages.findIndex( ( page ) => page.contains( element ) );
			}
		}

		this.announceErrors( result.message );

		if ( firstBad ) {
			if ( firstPage >= 0 ) {
				this.showStep( firstPage, false );
			}

			this.focusField( firstBad );
		}
	}

	/** Fills and focuses the error summary. */
	private announceErrors( message = '' ): void {
		if ( ! this.errorSummary ) {
			return;
		}

		const bad = Array.from( this.form.querySelectorAll< HTMLElement >( '.atf-field.has-error' ) );

		if ( ! bad.length && ! message ) {
			this.errorSummary.hidden = true;

			return;
		}

		const items = bad
			.map( ( element ) => {
				const label = this.labelTextOf( element );
				const text = element.querySelector( '.atf-error' )?.textContent?.trim() ?? '';
				const id = element.querySelector( 'input, select, textarea' )?.id ?? '';

				return `<li><a href="#${ id }">${ escapeHtml( label ? `${ label }: ${ text }` : text ) }</a></li>`;
			} )
			.join( '' );

		this.errorSummary.innerHTML = `<p class="atf-errors__title">${ escapeHtml(
			message || i18n( 'errorsFound', 'There are problems to fix.' )
		) }</p><ul>${ items }</ul>`;

		this.errorSummary.hidden = false;
		this.errorSummary.focus();
	}

	/**
	 * The human name of a field, for the error summary.
	 *
	 * Two things this has to get right, and both are visible the moment a form
	 * fails validation.
	 *
	 * The **asterisk is stripped**. It is `aria-hidden` in the markup precisely
	 * so it is never announced, and reading `textContent` off the label puts it
	 * straight back — the summary would say "Your name star: this is required",
	 * which is exactly the noise the `aria-hidden` was there to prevent.
	 *
	 * A **consent or toggle field keeps its own label** in `.atf-toggle__label`
	 * rather than `.atf-label`, so a lookup that only knows the latter leaves
	 * those rows with no name at all — an entry in the summary that says nothing
	 * but "This is required", pointing at nothing the reader can identify.
	 */
	private labelTextOf( element: HTMLElement ): string {
		const source = element.querySelector( '.atf-label, legend, .atf-toggle__label' );

		if ( ! source ) {
			return '';
		}

		// Cloned so removing the asterisk cannot disturb the label the visitor
		// is looking at.
		const clone = source.cloneNode( true ) as HTMLElement;

		clone.querySelectorAll( '.atf-required, [aria-hidden="true"]' ).forEach( ( node ) => node.remove() );

		// The summary reads "<label>: <error>", and a consent label is a whole
		// sentence that already ends in a full stop — so without this the row
		// reads "…reply to it.: This is required."
		return ( clone.textContent ?? '' )
			.replace( /\s+/g, ' ' )
			.trim()
			.replace( /[.:;,]+$/, '' );
	}

	/** Replaces the form with its confirmation, or follows a redirect. */
	private showConfirmation( result: SubmissionResult ): void {
		const confirmation = result.confirmation ?? {};

		if ( confirmation.url ) {
			window.location.assign( confirmation.url );

			return;
		}

		const wrapper = this.form.parentElement;

		if ( ! wrapper ) {
			return;
		}

		const panel = document.createElement( 'div' );
		panel.className = 'atf-confirmation';
		panel.setAttribute( 'role', 'status' );
		panel.setAttribute( 'tabindex', '-1' );
		panel.innerHTML = confirmation.message ?? '';

		this.form.replaceWith( panel );

		// Focus moves into the confirmation so a screen-reader user is told the
		// form succeeded rather than being left on a control that no longer
		// exists.
		panel.focus();
		panel.scrollIntoView( { behavior: 'smooth', block: 'center' } );

		document.dispatchEvent(
			new CustomEvent( 'atf-submitted', {
				detail: { formId: Number( this.form.dataset.atfForm ), entryId: result.entry_id },
				bubbles: true,
			} )
		);
	}

	/**
	 * Saves what has been filled in so far and shows the way back.
	 *
	 * Deliberately does not validate. A half-finished form is by definition
	 * missing required answers, and refusing to save it because of that would
	 * make the feature useless — which is exactly the mistake that makes
	 * "save for later" feel broken in the plugins that have it.
	 */
	private async saveForLater(): Promise< void > {
		const button = this.form.querySelector< HTMLButtonElement >( '[data-atf-resume]' );
		const tokenField = this.form.querySelector< HTMLInputElement >( '[data-atf-resume-token]' );

		if ( button ) {
			button.disabled = true;
		}

		try {
			const body = new FormData( this.form );
			const response = await fetch( `${ config?.restUrl ?? '' }/resume`, {
				method: 'POST',
				body,
				credentials: 'same-origin',
				headers: config?.nonce ? { 'X-WP-Nonce': config.nonce } : {},
			} );

			const result = ( await response.json() ) as {
				success: boolean;
				message?: string;
				token?: string;
				url?: string;
				days?: number;
			};

			if ( ! result.success || ! result.url ) {
				this.showResumeMessage( result.message ?? i18n( 'failed', 'That did not save. Please try again.' ), '' );

				return;
			}

			// Carrying the token forward means saving twice updates one partial
			// rather than leaving a trail of them, and means finishing the form
			// can delete the right one.
			if ( tokenField && result.token ) {
				tokenField.value = result.token;
			}

			this.showResumeMessage(
				result.days
					? `Saved. Come back to this within ${ result.days } days using the link below.`
					: 'Saved. Come back using the link below.',
				result.url
			);
		} catch {
			this.showResumeMessage( i18n( 'failed', 'That did not save. Please try again.' ), '' );
		} finally {
			if ( button ) {
				button.disabled = false;
			}
		}
	}

	/**
	 * Shows the resume link.
	 *
	 * On the page rather than only in an e-mail, because the visitor is looking
	 * at the screen right now and may not have given an address yet. The input is
	 * `readonly` and selects itself, so copying it is one gesture.
	 */
	private showResumeMessage( message: string, url: string ): void {
		this.form.querySelector( '.atf-resume-panel' )?.remove();

		const panel = document.createElement( 'div' );

		panel.className = 'atf-resume-panel';
		panel.setAttribute( 'role', 'status' );
		panel.setAttribute( 'tabindex', '-1' );

		const text = document.createElement( 'p' );

		text.textContent = message;
		panel.append( text );

		if ( url ) {
			const field = document.createElement( 'input' );

			field.type = 'text';
			field.className = 'atf-input';
			field.readOnly = true;
			field.value = url;
			field.setAttribute( 'aria-label', 'Your link back to this form' );
			field.addEventListener( 'focus', () => field.select() );

			panel.append( field );
		}

		this.form.querySelector( '.atf-actions' )?.after( panel );
		panel.focus();
	}

	/** Tells the server somebody started filling this in. */
	private reportStart(): void {
		const formId = Number( this.form.dataset.atfForm );

		if ( ! formId || ! config?.restUrl ) {
			return;
		}

		// `keepalive` so the request survives the visitor navigating away
		// mid-form, which is exactly the case this statistic exists to count.
		void fetch( `${ config.restUrl }/track`, {
			method: 'POST',
			keepalive: true,
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { form_id: formId, event: 'start' } ),
		} ).catch( () => {
			// An analytics ping is never worth surfacing to the visitor.
		} );
	}

	/* ------------------------------------------------------------ Repeaters */

	/** Clones the template row into a repeater. */
	private addRepeaterRow( repeater: HTMLElement | null ): void {
		if ( ! repeater ) {
			return;
		}

		const rows = repeater.querySelector< HTMLElement >( '.atf-repeater__rows' );
		const template = repeater.querySelector< HTMLTemplateElement >( '[data-atf-repeater-template]' );
		const max = Number( repeater.dataset.atfMax ?? 10 );

		if ( ! rows || ! template ) {
			return;
		}

		if ( rows.querySelectorAll( '[data-atf-repeater-row]' ).length >= max ) {
			return;
		}

		// One past the highest index in use — not the row count. The two differ
		// as soon as a middle row is removed: three rows minus row one leaves
		// rows 0 and 2, and a "new row 2" would post into the same array slot
		// as the survivor, silently losing one of them on submit.
		const index = nextRepeaterIndex( rows );

		const clone = template.content.cloneNode( true ) as DocumentFragment;

		// The template carries `__INDEX__` where the row number goes; without
		// this substitution every row would post into the same array slot and
		// only the last would survive.
		clone.querySelectorAll< HTMLElement >( '[name], [id], [for]' ).forEach( ( element ) => {
			for ( const attribute of [ 'name', 'id', 'for' ] ) {
				const value = element.getAttribute( attribute );

				if ( value?.includes( '__INDEX__' ) ) {
					element.setAttribute( attribute, value.replace( /__INDEX__/g, String( index ) ) );
				}
			}
		} );

		rows.appendChild( clone );

		const added = rows.lastElementChild as HTMLElement | null;

		added?.querySelector< HTMLElement >( 'input, select, textarea' )?.focus();

		this.update();
	}

	/** Removes a repeater row, unless it is the last one the field allows. */
	private removeRepeaterRow( row: HTMLElement | null ): void {
		const repeater = row?.closest< HTMLElement >( '[data-atf-repeater]' );

		if ( ! row || ! repeater ) {
			return;
		}

		const rows = repeater.querySelectorAll( '[data-atf-repeater-row]' );
		const min = Number( repeater.dataset.atfMin ?? 1 );

		if ( rows.length <= min ) {
			// Cleared rather than removed, so the field keeps its minimum and
			// the visitor still gets the "this is now empty" result they asked
			// for.
			row.querySelectorAll< HTMLInputElement >( 'input, select, textarea' ).forEach( ( input ) => {
				input.value = '';
			} );

			return;
		}

		const focusAfter = ( row.nextElementSibling ?? row.previousElementSibling ) as HTMLElement | null;

		row.remove();
		focusAfter?.querySelector< HTMLElement >( 'input, select, textarea' )?.focus();

		this.update();
	}

	/* ------------------------------------------------------------ Signature */

	/**
	 * Turns the canvas into a signature pad.
	 *
	 * Pointer events rather than mouse plus touch, so a finger, a stylus and a
	 * mouse are one code path. `touch-action: none` in the CSS is what stops the
	 * page scrolling under a finger that is trying to sign.
	 */
	private initSignature( pad: HTMLElement ): void {
		const canvas = pad.querySelector< HTMLCanvasElement >( 'canvas' );
		const input = pad.querySelector< HTMLInputElement >( 'input[type="hidden"]' );
		const clear = pad.querySelector< HTMLButtonElement >( '[data-atf-signature-clear]' );
		const context = canvas?.getContext( '2d' );

		if ( ! canvas || ! input || ! context ) {
			return;
		}

		context.lineWidth = 2;
		context.lineCap = 'round';
		context.lineJoin = 'round';
		context.strokeStyle = getComputedStyle( pad ).color || '#000';

		let drawing = false;

		const positionOf = ( event: PointerEvent ) => {
			const rect = canvas.getBoundingClientRect();

			// The canvas is laid out responsively but has a fixed backing store,
			// so pointer coordinates have to be scaled or the line lands away
			// from the finger on every screen but one.
			return {
				x: ( ( event.clientX - rect.left ) / rect.width ) * canvas.width,
				y: ( ( event.clientY - rect.top ) / rect.height ) * canvas.height,
			};
		};

		canvas.addEventListener( 'pointerdown', ( event ) => {
			drawing = true;
			canvas.setPointerCapture( event.pointerId );

			const { x, y } = positionOf( event );

			context.beginPath();
			context.moveTo( x, y );
		} );

		canvas.addEventListener( 'pointermove', ( event ) => {
			if ( ! drawing ) {
				return;
			}

			const { x, y } = positionOf( event );

			context.lineTo( x, y );
			context.stroke();
		} );

		const finish = () => {
			if ( ! drawing ) {
				return;
			}

			drawing = false;
			input.value = canvas.toDataURL( 'image/png' );
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		};

		canvas.addEventListener( 'pointerup', finish );
		canvas.addEventListener( 'pointercancel', finish );
		canvas.addEventListener( 'pointerleave', finish );

		clear?.addEventListener( 'click', () => {
			context.clearRect( 0, 0, canvas.width, canvas.height );
			input.value = '';
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
	}

	/** Enables the "Other" text box only while its choice is picked. */
	private initOtherToggles(): void {
		const sync = () => {
			this.form.querySelectorAll< HTMLElement >( '.atf-choice--other' ).forEach( ( wrapper ) => {
				const toggle = wrapper.querySelector< HTMLInputElement >( '[data-atf-other-toggle]' );
				const input = wrapper.querySelector< HTMLInputElement >( '[data-atf-other-input]' );

				if ( ! toggle || ! input ) {
					return;
				}

				input.disabled = ! toggle.checked;
				input.hidden = ! toggle.checked;
			} );
		};

		this.form.addEventListener( 'change', sync );
		sync();
	}
}

/** Escapes text destined for `innerHTML`. */
function escapeHtml( text: string ): string {
	const div = document.createElement( 'div' );

	div.textContent = text;

	return div.innerHTML;
}

/**
 * The index the next repeater row should post under.
 *
 * Row names look like `atf[repeater][3][sub]`; the next row takes the highest
 * index found plus one. Scanned from the DOM rather than counted or kept on the
 * instance, because the DOM is the one place that cannot drift from what will
 * actually be submitted.
 */
export function nextRepeaterIndex( rows: ParentNode ): number {
	let highest = -1;

	rows.querySelectorAll( '[data-atf-repeater-row] [name]' ).forEach( ( element ) => {
		const match = /^atf\[[^\]]*\]\[(\d+)\]/.exec( element.getAttribute( 'name' ) ?? '' );

		if ( match ) {
			highest = Math.max( highest, Number( match[ 1 ] ) );
		}
	} );

	return highest + 1;
}

/** Reads the schema the renderer printed beside a form. */
function readSchema( form: HTMLFormElement ): ClientSchema | null {
	const instance = form.dataset.atfInstance ?? '';
	const script = document.getElementById( `${ instance }-schema` );

	if ( ! script?.textContent ) {
		return null;
	}

	try {
		return JSON.parse( script.textContent ) as ClientSchema;
	} catch {
		return null;
	}
}

/** Boots every form on the page. */
function boot(): void {
	document.querySelectorAll< HTMLFormElement >( 'form[data-atf-form]' ).forEach( ( form ) => {
		if ( form.dataset.atfBooted ) {
			return;
		}

		const schema = readSchema( form );

		if ( ! schema ) {
			// Without a schema there is no logic to run and no totals to
			// compute, and the form is still a working form. Leaving it alone
			// is strictly better than half-enhancing it.
			return;
		}

		form.dataset.atfBooted = '1';

		try {
			new AllTerrainForm( form, schema );
		} catch ( error ) {
			// An exception here must not leave the form in a half-enhanced
			// state that is worse than no enhancement at all — the un-enhanced
			// form still posts and still validates on the server.
			// eslint-disable-next-line no-console
			console.error( '[AllTerrain Forms] Could not enhance a form.', error );
		}
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}

// Forms can arrive after first paint — a block editor preview, an AJAX-loaded
// page, a modal. Re-booting on this event lets those enhance too, and the
// per-form guard makes it idempotent.
document.addEventListener( 'atf-refresh', boot );

export { AllTerrainForm, boot };

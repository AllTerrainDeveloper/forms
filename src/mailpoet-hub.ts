/**
 * The MailPoet window.
 *
 * A form is where a relationship starts; the newsletter is how it continues.
 * This window is the bridge between the two: pick a form, pick the MailPoet
 * lists it should feed, say which answers carry the address and the name, and
 * bind the whole thing to an explicit opt-in.
 *
 * What it writes is deliberately unexciting: an ordinary `mailpoet` action in
 * the form's own schema, conditional through the same logic block every other
 * action uses. Nothing about the integration is special-cased downstream —
 * the submit pipeline runs it like any action, failures land on the entry
 * like any failure, and a future Actions UI in the builder will show it like
 * any action.
 *
 * Consent is the one opinion this UI holds strongly: the binding control
 * defaults to "only when they tick a box", and choosing "everyone" is an
 * explicit act. MailPoet's own signup confirmation (double opt-in) then runs
 * on top of whatever is chosen here.
 */

import { api } from './api';
import { button, clear, el, notify, pinWindowBodyScroll, row, select } from './ui';
import type { Field, Form, FormAction, FormSummary, MailPoetInfo } from './types';

/** The one action id this window manages per form. */
const ACTION_ID = 'mailpoet';

/** MailPoet's brand orange, for the hero only — everything else wears the shell. */
const SENTINEL_EVERYONE = '';

/** The field types that can carry an opt-in tick. */
const CONSENT_TYPES = [ 'checkbox', 'checkboxes', 'toggle', 'consent', 'gdpr' ];

/** The field types that can carry an address, most likely first. */
const EMAIL_TYPES = [ 'email' ];

interface HubSettings {
	lists: number[];
	email_field: string;
	first_name_field: string;
	last_name_field: string;
}

/** The `mailpoet` action on a schema, if the hub (or anyone) has added one. */
function findAction( form: Form ): FormAction | undefined {
	return form.schema.actions.find( ( action ) => 'mailpoet' === action.type );
}

/** The action's settings, defaulted to the hub's shape. */
function settingsOf( action: FormAction | undefined ): HubSettings {
	const raw = ( action?.settings ?? {} ) as Partial< HubSettings >;

	return {
		lists: Array.isArray( raw.lists ) ? raw.lists.map( Number ).filter( Boolean ) : [],
		email_field: typeof raw.email_field === 'string' ? raw.email_field : '',
		first_name_field: typeof raw.first_name_field === 'string' ? raw.first_name_field : '',
		last_name_field: typeof raw.last_name_field === 'string' ? raw.last_name_field : '',
	};
}

/** The consent field id the action is bound to, or '' for everyone. */
function consentOf( action: FormAction | undefined ): string {
	if ( ! action?.logic?.enabled ) {
		return SENTINEL_EVERYONE;
	}

	return action.logic.rules[ 0 ]?.field ?? SENTINEL_EVERYONE;
}

/** Guesses the sensible default for a field role. */
function guessField( fields: Field[], types: string[], namePattern?: RegExp ): string {
	const byType = fields.find( ( field ) => types.includes( field.type ) );

	if ( byType ) {
		return byType.id;
	}

	if ( namePattern ) {
		return fields.find( ( field ) => namePattern.test( field.label ) )?.id ?? '';
	}

	return '';
}

/** One form's connection editor. */
function formCard( summary: FormSummary, info: MailPoetInfo, host: HTMLElement ): HTMLElement {
	const card = el( 'section', { class: 'atfm-form' } );

	// The symbol marks each card — full colour once the form subscribes,
	// greyed while it does not, so the list reads at a glance.
	const mark = ( on: boolean ) =>
		el( 'span', {
			class: `atfm-form__mark${ on ? '' : ' atfm-form__mark--off' }`,
			children: [ el( 'img', { attrs: { src: info.symbol, alt: '', width: '20', height: '20' } } ) ],
		} );

	const paintClosed = ( subscribed: string[] ) => {
		clear( card );
		card.classList.remove( 'is-open' );

		card.append(
			el( 'div', {
				class: 'atfm-form__head',
				children: [
					mark( subscribed.length > 0 ),
					el( 'div', {
						class: 'atfm-form__title',
						children: [
							el( 'strong', { text: summary.title || `Form ${ summary.id }` } ),
							subscribed.length
								? el( 'span', {
										class: 'atfm-form__status is-on',
										text: `Subscribing → ${ subscribed.join( ', ' ) }`,
								  } )
								: el( 'span', { class: 'atfm-form__status', text: 'Not connected' } ),
						],
					} ),
					button( subscribed.length ? 'Edit' : 'Connect', () => void openEditor(), 'secondary' ),
				],
			} )
		);
	};

	const openEditor = async () => {
		card.classList.add( 'is-open' );
		clear( card );
		card.append( el( 'p', { class: 'atfm-hint', text: 'Loading the form…' } ) );

		let form: Form;

		try {
			form = await api.getForm( summary.id );
		} catch ( error ) {
			clear( card );
			card.append( el( 'p', { class: 'atfm-hint', text: 'Could not load this form.' } ) );

			return;
		}

		const fields = form.schema.fields.filter( ( field ) => 'page_break' !== field.type );
		const action = findAction( form );
		const settings = settingsOf( action );
		const chosen = new Set( settings.lists.filter( ( id ) => info.lists.some( ( list ) => list.id === id ) ) );
		let consent = consentOf( action );
		let email = settings.email_field || guessField( fields, EMAIL_TYPES, /mail/i );
		let firstName = settings.first_name_field;
		let lastName = settings.last_name_field;

		// A form connected before a field was deleted keeps the stale id in its
		// settings; offering it in the picker would be offering a ghost.
		if ( ! fields.some( ( field ) => field.id === email ) ) {
			email = guessField( fields, EMAIL_TYPES, /mail/i );
		}

		const consentable = fields.filter( ( field ) => CONSENT_TYPES.includes( field.type ) );

		// Default new connections to the first tickable field, not to
		// "everyone": consent should be what you get without thinking, and
		// subscribing every submitter should be the deliberate choice.
		if ( ! action && consentable.length ) {
			consent = consentable[ 0 ].id;
		}

		const fieldOptions = ( included: Field[], none: string ) => [
			{ value: '', label: none },
			...included.map( ( field ) => ( { value: field.id, label: field.label || field.id } ) ),
		];

		const listsBox = el( 'div', { class: 'atfm-lists' } );

		const paintLists = () => {
			clear( listsBox );

			for ( const list of info.lists ) {
				const tick = el( 'input', {
					attrs: { type: 'checkbox' },
				} ) as HTMLInputElement;

				tick.checked = chosen.has( list.id );
				tick.addEventListener( 'change', () => {
					if ( tick.checked ) {
						chosen.add( list.id );
					} else {
						chosen.delete( list.id );
					}
				} );

				listsBox.append(
					el( 'label', {
						class: 'atfm-list',
						children: [ tick, el( 'span', { text: list.name } ) ],
					} )
				);
			}
		};

		paintLists();

		const save = async ( remove = false ) => {
			const actions = form.schema.actions.filter( ( candidate ) => 'mailpoet' !== candidate.type );

			if ( ! remove ) {
				if ( ! chosen.size ) {
					notify( 'Pick a list', 'A subscription needs at least one MailPoet list.', 'error' );

					return;
				}

				if ( ! email ) {
					notify( 'Pick the email field', 'MailPoet needs to know which answer is the address.', 'error' );

					return;
				}

				actions.push( {
					id: ACTION_ID,
					type: 'mailpoet',
					enabled: true,
					logic:
						consent === SENTINEL_EVERYONE
							? { enabled: false, action: 'show', match: 'all', rules: [] }
							: {
									enabled: true,
									action: 'show',
									match: 'all',
									rules: [ { field: consent, operator: 'not_empty', value: '' } ],
							  },
					settings: {
						lists: [ ...chosen ],
						email_field: email,
						first_name_field: firstName,
						last_name_field: lastName,
					},
				} );
			}

			form.schema.actions = actions;

			try {
				await api.updateForm( form.id, { schema: form.schema } );
			} catch ( error ) {
				notify( 'Could not save', error instanceof Error ? error.message : '', 'error' );

				return;
			}

			notify(
				remove ? 'Disconnected' : 'Connected to MailPoet',
				remove
					? `${ summary.title } no longer subscribes anyone.`
					: `${ summary.title } now subscribes opted-in visitors.`
			);
			paintClosed( remove ? [] : info.lists.filter( ( list ) => chosen.has( list.id ) ).map( ( list ) => list.name ) );
		};

		clear( card );
		// A tiny caption over each group of controls, so the editor reads as
		// three questions — where, who, when — rather than five bare rows.
		const caption = ( text: string ) => el( 'span', { class: 'atfm-form__section', text } );

		card.append(
			el( 'div', {
				class: 'atfm-form__head',
				children: [
					mark( Boolean( action ) ),
					el( 'div', {
						class: 'atfm-form__title',
						children: [ el( 'strong', { text: summary.title || `Form ${ summary.id }` } ) ],
					} ),
					button( 'Close', () => paintClosed( action ? info.lists.filter( ( l ) => settingsOf( action ).lists.includes( l.id ) ).map( ( l ) => l.name ) : [] ), 'ghost' ),
				],
			} ),
			el( 'div', {
				class: 'atfm-form__body',
				children: [
					caption( 'Where they land' ),
					row( 'Lists', listsBox, 'MailPoet sends its own confirmation email before anyone is truly on a list.' ),
					caption( 'Who they are' ),
					row(
						'Email address',
						select( email, fieldOptions( fields, '— pick a field —' ), ( value ) => {
							email = value;
						} ),
						'The answer that carries their address.'
					),
					row(
						'First name',
						select( firstName, fieldOptions( fields, '— none —' ), ( value ) => {
							firstName = value;
						} )
					),
					row(
						'Last name',
						select( lastName, fieldOptions( fields, '— none —' ), ( value ) => {
							lastName = value;
						} )
					),
					caption( 'When to subscribe' ),
					row(
						'Subscribe',
						select(
							consent,
							[
								...consentable.map( ( field ) => ( {
									value: field.id,
									label: `Only when “${ field.label || field.id }” is ticked`,
								} ) ),
								{ value: SENTINEL_EVERYONE, label: 'Everyone who submits' },
							],
							( value ) => {
								consent = value;
							}
						),
						consentable.length
							? 'Bind it to a box they tick. Subscribing everyone is rarely what visitors expect.'
							: 'Add a checkbox or toggle field to the form to offer a proper opt-in.'
					),
					el( 'div', {
						class: 'atfm-form__actions',
						children: action
							? [ button( 'Save connection', () => void save(), 'primary' ), button( 'Disconnect', () => void save( true ), 'danger' ) ]
							: [ button( 'Save connection', () => void save(), 'primary' ) ],
					} ),
				],
			} )
		);
	};

	// The summary paints from the list row alone; whether this form already
	// subscribes is only knowable from its schema, which is one request per
	// form — fetched lazily when the card opens, and eagerly here only for
	// the status line via the summary's cheap proxy: nothing. The row starts
	// as "Not connected" and corrects itself the moment it is opened; the
	// alternative is N schema requests to draw a list.
	paintClosed( [] );

	// A connected form should say so without being opened. One schema fetch
	// per form is acceptable in a window opened deliberately — but done
	// lazily, after paint, so the list is usable immediately.
	void api
		.getForm( summary.id )
		.then( ( form ) => {
			const action = findAction( form );

			if ( action && ! card.classList.contains( 'is-open' ) ) {
				const names = info.lists
					.filter( ( list ) => settingsOf( action ).lists.includes( list.id ) )
					.map( ( list ) => list.name );

				paintClosed( names );
			}
		} )
		.catch( () => undefined );

	host.append( card );

	return card;
}

/** The hero: MailPoet's face on our window, and the state of the bridge. */
function hero( info: MailPoetInfo ): HTMLElement {
	const media = info.logo
		? el( 'span', {
				class: 'atfm-hero__logo',
				children: [
					el( 'img', {
						attrs: { src: info.logo, alt: 'MailPoet', width: '210', height: '105' },
					} ),
				],
		  } )
		: el( 'span', { class: 'atfm-hero__logo atfm-hero__logo--glyph', text: '📬' } );

	return el( 'header', {
		class: 'atfm-hero',
		children: [
			media,
			el( 'div', {
				class: 'atfm-hero__words',
				children: [
					el( 'h1', { text: 'Turn submissions into subscribers' } ),
					el( 'p', {
						text:
							'Every submission is someone choosing to talk to you. Connect a form to MailPoet and the ones who opt in land on your lists by themselves — named, consented, and confirmed by MailPoet’s own double opt-in.',
					} ),
					info.active
						? el( 'p', {
								class: 'atfm-hero__status is-on',
								text:
									1 === info.lists.length
										? 'Connected — 1 list ready for subscribers'
										: `Connected — ${ info.lists.length } lists ready for subscribers`,
						  } )
						: el( 'p', {
								class: 'atfm-hero__status',
								text: 'MailPoet is not installed on this site yet',
						  } ),
				],
			} ),
		],
	} );
}

/** Three reasons MailPoet is worth meeting, and the doors into their world. */
function whyMailPoet(): HTMLElement[] {
	const card = ( icon: string, title: string, words: string ) =>
		el( 'div', {
			class: 'atfm-why__card',
			children: [
				el( 'span', { class: 'atfm-why__icon', text: icon } ),
				el( 'strong', { text: title } ),
				el( 'p', { text: words } ),
			],
		} );

	const link = ( href: string, text: string ) =>
		el( 'a', { text, attrs: { href, target: '_blank', rel: 'noreferrer' } } );

	return [
		el( 'div', {
			class: 'atfm-why',
			children: [
				card(
					'✉️',
					'Beautiful emails, made in WordPress',
					'Design newsletters and welcome emails in a drag-and-drop editor that lives in your own admin — no external account to juggle.'
				),
				card(
					'🤝',
					'Consent you can stand behind',
					'Double opt-in out of the box: every address your forms send over is confirmed by the visitor before a single campaign reaches it.'
				),
				card(
					'🚀',
					'Free to grow with',
					'Free up to 500 subscribers, welcome automations, WooCommerce emails and open-rate stats included — a paid addon anywhere else.'
				),
			],
		} ),
		el( 'p', {
			class: 'atfm-links',
			children: [
				link( 'https://www.mailpoet.com/features/', 'Explore MailPoet’s features ↗' ),
				link( 'https://kb.mailpoet.com/', 'Guides & docs ↗' ),
				link( 'https://www.mailpoet.com/pricing/', 'Plans & the free tier ↗' ),
			],
		} ),
	];
}

/** The three-step strip: what connecting actually involves. */
function steps(): HTMLElement {
	const step = ( n: string, words: string ) =>
		el( 'div', {
			class: 'atfm-step',
			children: [ el( 'span', { class: 'atfm-step__n', text: n } ), el( 'span', { text: words } ) ],
		} );

	return el( 'div', {
		class: 'atfm-steps',
		children: [
			step( '1', 'Pick a form below' ),
			step( '2', 'Choose the lists it feeds' ),
			step( '3', 'Bind it to an opt-in — MailPoet confirms the rest' ),
		],
	} );
}

/** Mounts the MailPoet window. */
export async function mountMailPoetHub(): Promise< void > {
	const root = document.querySelector< HTMLElement >( '[data-atfm-root]' );

	if ( ! root || root.dataset.atfmMounted ) {
		return;
	}

	root.dataset.atfmMounted = '1';
	pinWindowBodyScroll( root );

	const bar = root.querySelector< HTMLElement >( '[data-atfm-bar]' );
	const body = root.querySelector< HTMLElement >( '[data-atfm-body]' ) ?? root;

	try {
		const [ info, forms ] = await Promise.all( [ api.mailpoet(), api.listForms() ] );

		bar?.remove();
		clear( body );
		body.append( hero( info ), ...whyMailPoet() );

		if ( ! info.active ) {
			// The pitch, not a dead end: what the bridge does, and the one
			// button that makes it real. Wording sells the outcome, not the
			// plugin — the plugin is the how.
			body.append(
				el( 'div', {
					class: 'atfm-pitch',
					children: [
						el( 'p', {
							text:
								'MailPoet sends newsletters from right inside WordPress — no external account, free to start. Install it and every form here gets a subscribe switch: reservations feed your announcements, signups feed your schedule, enquiries feed your news.',
						} ),
						el( 'a', {
							class: 'atfm-pitch__cta',
							text: 'Install MailPoet — it’s free',
							attrs: { href: info.adminUrl, target: '_blank', rel: 'noreferrer' },
						} ),
					],
				} )
			);

			return;
		}

		if ( ! info.lists.length ) {
			body.append(
				el( 'div', {
					class: 'atfm-pitch',
					children: [
						el( 'p', {
							text: 'MailPoet is here, but it has no list yet. Make one, and your forms will have somewhere to send subscribers.',
						} ),
						el( 'a', {
							class: 'atfm-pitch__cta',
							text: 'Open MailPoet lists',
							attrs: { href: info.adminUrl, target: '_blank', rel: 'noreferrer' },
						} ),
					],
				} )
			);

			return;
		}

		body.append( steps() );

		const section = el( 'section', {
			class: 'atfm-forms',
			children: [
				el( 'div', {
					class: 'atfm-forms__head',
					children: [
						el( 'h2', { text: 'Your forms' } ),
						el( 'p', {
							class: 'atfm-hint',
							text: 'Connected forms wear the MailPoet mark in colour.',
						} ),
					],
				} ),
			],
		} );

		body.append( section );

		if ( ! forms.length ) {
			section.append( el( 'p', { class: 'atfm-hint', text: 'No forms yet — make one, then come back to connect it.' } ) );

			return;
		}

		for ( const summary of forms ) {
			formCard( summary, info, section );
		}
	} catch ( error ) {
		if ( bar ) {
			bar.textContent = 'Could not reach the site — reload the window to try again.';
		}
	}
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', () => void mountMailPoetHub() );
} else {
	void mountMailPoetHub();
}

// The shell mounts a native window's markup after this bundle has already run,
// so the window's own render callback fires this to mount into it.
document.addEventListener( 'os-window-content-loaded', () => void mountMailPoetHub() );

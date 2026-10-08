/**
 * Merge tags, for people who have never heard of merge tags.
 *
 * The Notifications and Confirmations panes used to hand somebody an empty text
 * box, put `{admin_email}` in it, and add a hint mentioning `{field:f2}`. That
 * asks the person to know three things nobody has told them: that braces mean
 * something, which tags exist, and that their second question is internally
 * called `f2`. It is the single least discoverable corner of the plugin, and it
 * sits in the two panes that decide whether anybody ever finds out a form was
 * submitted.
 *
 * Three changes fix it, and they work together:
 *
 * 1. **A picker.** Every box that understands tags gets an Insert button opening
 *    a grouped list — the form's own questions first, by the labels the person
 *    wrote. Typing `{` opens that same picker in place. The tag is shown beside each label, small
 *    and secondary, which is how the syntax gets learned rather than taught.
 *
 * 2. **A descriptive preview.** Under each box, each tag becomes
 *    `{the value of Question label}`. The reason merge tags feel like guesswork is that you
 *    cannot see the result until a real email reaches a real person, and by then
 *    it is too late to be wrong.
 *
 * 3. **A plain-language chooser for "To".** Nearly every notification goes to
 *    one of three places: the site admin, a fixed address, or an address the
 *    visitor typed. Those are offered as choices, so the common cases need no
 *    tags at all and the text box appears only for the rare one that does.
 *
 * The catalogue is fetched from the server, never assembled here. `merge-tags.php`
 * is what decides what a tag does, and a second list living in the browser is a
 * list that drifts — advertising a tag that resolves to nothing, or missing one a
 * plugin added through `alltfo_resolve_merge_tag`.
 */

import { api } from './api';
import { el, icon } from './ui';

import type { MergeTag, MergeTagGroup } from './types';

/**
 * The catalogue for one form, fetched once.
 *
 * Cached per form because the picker opens from six different boxes and a
 * round-trip each time would make it feel like a page load. Invalidated by
 * `forgetMergeTags()` when the form's fields change, since the answer group is
 * built from them.
 */
const cache = new Map< number, Promise< MergeTagGroup[] > >();

/** Loads the catalogue, from cache when it is there. */
export function mergeTags( formId: number ): Promise< MergeTagGroup[] > {
	let pending = cache.get( formId );

	if ( ! pending ) {
		pending = api.mergeTags( formId ).catch( () => [] as MergeTagGroup[] );

		cache.set( formId, pending );
	}

	return pending;
}

/**
 * Drops the cached catalogue for a form.
 *
 * Called when a field is added, removed, renamed or retyped: the answers group
 * is built from the schema, so a stale catalogue offers questions that no longer
 * exist and hides the one just added — which reads as the picker being broken.
 */
export function forgetMergeTags( formId: number ): void {
	cache.delete( formId );
}

/** Every tag in the catalogue, flattened — for resolving a preview. */
function flatten( groups: MergeTagGroup[] ): Map< string, MergeTag > {
	const all = new Map< string, MergeTag >();

	for ( const group of groups ) {
		for ( const item of group.items ) {
			all.set( item.tag, item );
		}
	}

	return all;
}

/**
 * The text as it will read once the tags are resolved.
 *
 * Names each value instead of inventing an answer. A tag nobody recognises is left visible rather
 * than blanked, because that is what the server does with it too — and a preview
 * that quietly swallowed a typo would hide the one mistake this is here to
 * catch.
 */
export function resolvePreview( text: string, groups: MergeTagGroup[] ): string {
	const all = flatten( groups );

	return text.replace( /\{[a-z_]+(?::[^}]*)?\}/gi, ( match ) => {
		const known = all.get( match.toLowerCase() );

		return known ? `{the value of ${ known.label }}` : match;
	} );
}

/** Whether a string contains anything that looks like a tag. */
export function hasTags( text: string ): boolean {
	return /\{[a-z_]+(?::[^}]*)?\}/i.test( text );
}

/** The one open picker, so a second Insert click does not stack two. */
let openPicker: HTMLElement | null = null;
let pickerRequest = 0;
let pickerReturnFocus: HTMLElement | null = null;
let pickerOnClose: ( () => void ) | null = null;

/** Closes whatever picker is open. */
function closePicker( restoreFocus = false ): void {
	pickerRequest++;
	openPicker?.remove();
	openPicker = null;
	if ( restoreFocus ) {
		pickerReturnFocus?.focus();
	}
	pickerReturnFocus = null;

	const after = pickerOnClose;

	pickerOnClose = null;
	after?.();
}

/**
 * Whether a picker is open on behalf of this element.
 *
 * Focus moves into the picker's search box while it is open, so the element
 * the tag is going into sees a `blur`. An inline-editable label commits — and
 * repaints the canvas — on blur, which would destroy the very node the tag is
 * about to be written into. It asks this first.
 */
export function isPickingFor( element: HTMLElement ): boolean {
	return pickerReturnFocus === element;
}

/**
 * The element an open picker is writing into, if any.
 *
 * For hosts that repaint on their own schedule — the canvas after an autosave
 * — to hold off until the pick has landed.
 */
export function pickerOwner(): HTMLElement | null {
	return pickerReturnFocus;
}

if ( typeof document !== 'undefined' ) {
	// Capture phase: the picker's own buttons stop propagation, so anything that
	// reaches here is genuinely a click somewhere else.
	document.addEventListener( 'pointerdown', ( event ) => {
		const target = event.target as HTMLElement | null;

		// The Insert button owns its own toggle. Closing here as well would
		// null `openPicker` before the button's click handler runs, so its
		// "already open — close" branch could never match and every press
		// reopened the picker instead of toggling it shut.
		if ( target?.closest( '.atfb-tagpick__open' ) ) {
			return;
		}

		if ( ! openPicker?.contains( target ) ) {
			closePicker();
		}
	} );

	// The desktop shell handles arrows during document capture. A listener on
	// the picker itself runs too late once an option button has focus. Capture
	// at the window boundary, scoped to this picker, before desktop shortcuts.
	window.addEventListener( 'keydown', ( event ) => {
		if ( 'Escape' === event.key && pickerReturnFocus ) {
			closePicker( true );
			event.preventDefault();
			event.stopImmediatePropagation();
			return;
		}
		if ( ! openPicker?.contains( event.target as Node | null ) ||
			! [ 'ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight', 'Home', 'End', 'Enter' ].includes( event.key ) ) {
			return;
		}
		event.stopImmediatePropagation();
		const search = openPicker.querySelector( '.atfb-tagpick__search' );
		const items = [ ...openPicker.querySelectorAll< HTMLButtonElement >( '.atfb-tagpick__item' ) ];
		const index = items.indexOf( document.activeElement as HTMLButtonElement );
		if ( event.key === 'ArrowDown' || event.key === 'ArrowUp' ) {
			event.preventDefault();
			const next = event.key === 'ArrowDown' ? index + 1 : ( index < 0 ? items.length - 1 : index - 1 );
			items[ ( next + items.length ) % items.length ]?.focus();
		} else if ( event.key === 'Enter' && event.target === search ) {
			event.preventDefault();
			items[ 0 ]?.click();
		} else if ( event.target !== search && event.key !== 'Enter' ) {
			// Left/right and Home/End retain native caret movement in search.
			// On result buttons, Home/End jump to the first/last result instead.
			event.preventDefault();
			if ( event.key === 'Home' ) items[ 0 ]?.focus();
			if ( event.key === 'End' ) items[ items.length - 1 ]?.focus();
		}
	}, true );
}

/**
 * Puts a tag into a field at the cursor.
 *
 * At the cursor rather than appended, because a subject line is usually
 * "New enquiry from ‹here›" and appending would make every insertion need a
 * cut-and-paste afterwards. The caret lands after the inserted tag so a second
 * insertion continues where the first left off.
 */
export function insertAtCursor( field: HTMLInputElement | HTMLTextAreaElement, text: string ): void {
	const start = field.selectionStart ?? field.value.length;
	const end = field.selectionEnd ?? field.value.length;

	field.value = field.value.slice( 0, start ) + text + field.value.slice( end );

	const caret = start + text.length;

	field.setSelectionRange( caret, caret );

	// A programmatic value change fires nothing, so the pane's own `input`
	// handler — the thing that marks the form dirty — would never run.
	field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	field.focus();
}

/** The visible area shared by every scrolling ancestor of the control. */
function pickerBounds( from: HTMLElement ): { top: number; bottom: number } {
	let top = 0;
	let bottom = window.innerHeight;
	let node = from.parentElement;
	while ( node && node !== document.body ) {
		if ( /auto|scroll|hidden|clip/.test( getComputedStyle( node ).overflowY ) ) {
			const rect = node.getBoundingClientRect();
			top = Math.max( top, rect.top );
			bottom = Math.min( bottom, rect.bottom );
		}
		node = node.parentElement;
	}
	return { top, bottom };
}

/** What the picker says above its list, unless the box asks for other words. */
const DEFAULT_INTRO = 'Pick something to drop in. It is filled in when the form is submitted.';

/** Builds the popover list. */
function buildPicker(
	groups: MergeTagGroup[],
	intro: string,
	onPick: ( tag: string ) => void
): HTMLElement {
	const search = el( 'input', {
		class: 'atfb-input atfb-tagpick__search',
		type: 'search',
		placeholder: 'Search values…',
		attrs: { 'aria-label': 'Search values' },
	} );

	const list = el( 'div', { class: 'atfb-tagpick__list' } );

	const paint = ( query: string ) => {
		list.replaceChildren();

		const needle = query.trim().toLowerCase();
		let shown = 0;

		for ( const group of groups ) {
			const matches = group.items.filter(
				( item ) =>
					! needle ||
					item.label.toLowerCase().includes( needle ) ||
					item.tag.toLowerCase().includes( needle ) ||
					( item.hint ?? '' ).toLowerCase().includes( needle )
			);

			if ( ! matches.length ) {
				// The empty-state line belongs to its group and is only worth
				// showing when nothing is being searched for — during a search it
				// would read as "no results" for the whole picker.
				if ( group.empty && ! needle && ! group.items.length ) {
					list.append(
						el( 'p', { class: 'atfb-tagpick__group', text: group.label } ),
						el( 'p', { class: 'atfb-tagpick__empty', text: group.empty } )
					);
				}

				continue;
			}

			list.append( el( 'p', { class: 'atfb-tagpick__group', text: group.label } ) );

			for ( const item of matches ) {
				shown += 1;

				list.append(
					el( 'button', {
						class: 'atfb-tagpick__item',
						type: 'button',
						on: {
							click: () => {
								// Inserted while the picker is still the open one,
								// so the box it belongs to is never told the picker
								// went away before the tag arrived.
								onPick( item.tag );
								closePicker();
							},
						},
						children: [
							el( 'span', {
								class: 'atfb-tagpick__item-main',
								children: [
									el( 'span', { class: 'atfb-tagpick__label', text: item.label } ),
									el( 'code', { class: 'atfb-tagpick__tag', text: item.tag } ),
								],
							} ),
							// What it is, then what it looks like. The catalogue
							// has always carried both; the list used to drop them
							// for a restatement of the label.
							item.hint ? el( 'span', { class: 'atfb-tagpick__meta', text: item.hint } ) : null,
							item.sample
								? el( 'span', {
										class: 'atfb-tagpick__sample',
										children: [
											el( 'span', { class: 'atfb-tagpick__sample-label', text: 'e.g.' } ),
											el( 'span', { text: item.sample } ),
										],
								  } )
								: null,
						],
					} )
				);
			}
		}

		if ( ! shown && needle ) {
			list.append( el( 'p', { class: 'atfb-tagpick__empty', text: `Nothing matches “${ query }”.` } ) );
		}
	};

	paint( '' );
	search.addEventListener( 'input', () => paint( search.value ) );

	const picker = el( 'div', {
		class: 'atfb-tagpick',
		attrs: { role: 'dialog', 'aria-label': 'Insert a value' },
		children: [
			el( 'p', { class: 'atfb-tagpick__intro', text: intro } ),
			search,
			list,
			el( 'p', {
				class: 'atfb-tagpick__tip',
				children: [
					'Tip: type ',
					el( 'kbd', { text: '{' } ),
					' in the box to open this list without reaching for the mouse.',
				],
			} ),
		],
	} );
	picker.addEventListener( 'keydown', ( event ) => event.stopPropagation() );
	return picker;
}

/** Everything `showPicker()` needs to open, place and fill one picker. */
interface PickerRequest {
	catalogue: () => Promise< MergeTagGroup[] >;
	/** What the picker is appended to. */
	container: HTMLElement;
	/** What it sits beside. */
	anchor: HTMLElement;
	/**
	 * Fixed to the viewport rather than absolute inside `container`.
	 *
	 * For text on the canvas: a card clips its overflow and is itself a drag
	 * handle, so the picker cannot live inside it.
	 */
	floating?: boolean;
	/** Where focus goes back to on Escape. */
	returnFocus: HTMLElement;
	intro: string;
	/** False once the text changed under the picker while it was loading. */
	stillValid: () => boolean;
	onPick: ( tag: string ) => void;
	onClose?: () => void;
}

/** Opens a picker, replacing any that is already open. */
function showPicker( request: PickerRequest ): void {
	closePicker();
	const ticket = pickerRequest;
	pickerReturnFocus = request.returnFocus;
	pickerOnClose = request.onClose ?? null;

	void request.catalogue().then( ( groups ) => {
		if ( ticket !== pickerRequest || ! request.container.isConnected || ! request.stillValid() ) {
			return;
		}
		const picker = buildPicker( groups, request.intro, request.onPick );
		if ( request.floating ) {
			picker.classList.add( 'atfb-tagpick--floating' );
		}
		request.container.append( picker );
		openPicker = picker;

		const bounds = request.floating ? { top: 0, bottom: window.innerHeight } : pickerBounds( request.anchor );
		picker.style.maxBlockSize = `${ Math.max( 0, Math.min( 360, bounds.bottom - bounds.top - 8 ) ) }px`;
		const anchor = request.anchor.getBoundingClientRect();
		const { height, width } = picker.getBoundingClientRect();
		// Prefer below, otherwise above. Clamp within the pane even when a
		// tall textarea leaves too little space on either side.
		const preferred = anchor.bottom + height <= bounds.bottom ? anchor.bottom : anchor.top - height;
		const top = Math.max( bounds.top + 4, Math.min( preferred, bounds.bottom - height - 4 ) );

		if ( request.floating ) {
			picker.style.top = `${ top }px`;
			picker.style.left = `${ Math.max( 4, Math.min( anchor.left, window.innerWidth - width - 4 ) ) }px`;
		} else {
			picker.style.insetBlockStart = `${ top - anchor.top }px`;
		}
		picker.querySelector< HTMLInputElement >( '.atfb-tagpick__search' )?.focus( { preventScroll: true } );
	} );
}

/** Options for a tag-aware control. */
interface TaggableOptions {
	formId?: number;
	/** A catalogue other than the submission's merge tags: formula references, recalled answers. */
	groups?: () => MergeTagGroup[] | Promise< MergeTagGroup[] >;
	/** Shown under the box as “Reads as: …”. Off for one-line URLs, where it adds noise. */
	preview?: boolean;
	/** What the picker says above its list — when the values are filled in, mainly. */
	intro?: string;
	/** The Insert button's wording. */
	button?: string;
}

/** The catalogue a tag-aware control offers. */
function catalogueFor( options: TaggableOptions ): () => Promise< MergeTagGroup[] > {
	return () => Promise.resolve( options.groups ? options.groups() : mergeTags( options.formId ?? 0 ) );
}

/**
 * Wraps an input or textarea so it can take merge tags without anyone knowing
 * the syntax.
 *
 * Returns the wrapper, not the field: callers put this where the bare control
 * used to go, and everything about the field itself — value, listeners — is
 * still whatever they built.
 */
export function taggable(
	field: HTMLInputElement | HTMLTextAreaElement,
	options: TaggableOptions
): HTMLElement {
	const catalogue = catalogueFor( options );
	const insert = el( 'button', {
		class: 'atfb-button atfb-button--ghost atfb-tagpick__open',
		type: 'button',
		title: 'Pick a value to insert — or type { in the box',
		attrs: { 'aria-haspopup': 'dialog' },
		children: [ icon( 'shortcode' ), el( 'span', { text: options.button ?? 'Insert a value' } ) ],
	} );

	const wrapper = el( 'div', {
		class: 'atfb-taggable',
		children: [ field, el( 'div', { class: 'atfb-taggable__tools', children: [ insert ] } ) ],
	} );

	const preview = options.preview === false ? null : el( 'p', { class: 'atfb-taggable__preview' } );

	if ( preview ) {
		wrapper.append( preview );
	}

	const repaint = () => {
		if ( ! preview ) {
			return;
		}

		if ( ! hasTags( field.value ) ) {
			// Nothing to explain. An always-present preview echoing plain text back
			// at the person is just a second copy of what they typed.
			preview.textContent = '';
			preview.hidden = true;

			return;
		}

		void catalogue().then( ( groups ) => {
			if ( ! hasTags( field.value ) ) {
				return;
			}
			preview.hidden = false;
			preview.replaceChildren(
				el( 'span', { class: 'atfb-taggable__preview-label', text: 'Reads as' } ),
				el( 'span', { text: resolvePreview( field.value, groups ) } )
			);
		} );
	};

	field.addEventListener( 'input', repaint );
	repaint();

	/** Preserve the replacement range while focus moves into the picker. */
	const open = ( start: number, end: number ) => {
		const original = field.value;

		showPicker( {
			catalogue,
			container: wrapper,
			anchor: wrapper,
			returnFocus: field,
			intro: options.intro ?? DEFAULT_INTRO,
			stillValid: () => wrapper.isConnected && field.value === original,
			onPick: ( tag ) => {
				field.setSelectionRange( start, end );
				insertAtCursor( field, tag );
			},
		} );
	};

	insert.addEventListener( 'click', ( event ) => {
		event.stopPropagation();
		if ( pickerReturnFocus === field ) {
			closePicker( true );
			return;
		}
		open( field.selectionStart ?? field.value.length, field.selectionEnd ?? field.value.length );
	} );

	field.addEventListener( 'input', ( event ) => {
		const typed = event as InputEvent;
		const caret = field.selectionStart ?? 0;
		if ( ! typed.isComposing && typed.data === '{' && field.value[ caret - 1 ] === '{' ) {
			// Replace the triggering brace (and an existing closing brace), so
			// picking a reference never produces {{field:f1} or {field:f1}}.
			open( caret - 1, caret + ( field.value[ caret ] === '}' ? 1 : 0 ) );
		}
	} );

	return wrapper;
}

/** Where the caret sits inside an editable element, as a character offset. */
function caretOffset( node: HTMLElement ): number {
	const length = ( node.textContent ?? '' ).length;
	const selection = node.ownerDocument.getSelection();

	if ( ! selection?.rangeCount ) {
		return length;
	}

	const range = selection.getRangeAt( 0 );

	if ( ! node.contains( range.endContainer ) ) {
		return length;
	}

	const before = node.ownerDocument.createRange();

	before.selectNodeContents( node );
	before.setEnd( range.endContainer, range.endOffset );

	return before.toString().length;
}

/** Puts the caret at a character offset inside a single-text-node editable. */
function placeCaret( node: HTMLElement, offset: number ): void {
	const text = node.firstChild;
	const selection = node.ownerDocument.getSelection();

	if ( ! text || ! selection ) {
		return;
	}

	const range = node.ownerDocument.createRange();

	range.setStart( text, Math.min( offset, ( text.textContent ?? '' ).length ) );
	range.collapse( true );
	selection.removeAllRanges();
	selection.addRange( range );
}

/**
 * The same `{` shortcut, for text edited where it sits on the canvas.
 *
 * The canvas edits a label as the label — a `contenteditable` in the theme's
 * own type — so there is no room for an Insert button and nothing to wrap.
 * Typing `{` opens the picker floating beside the text instead, and the tag
 * replaces the brace exactly as it does in a text box.
 *
 * The editable should check {@link isPickingFor} before committing on blur:
 * focus is in the picker's search box while it is open. When the picker
 * closes without a pick and focus has gone elsewhere, a `blur` is dispatched
 * on the editable so the commit it skipped still happens.
 *
 * @param node    A single-line `contenteditable`.
 * @param options What it offers.
 * @return The same node.
 */
export function taggableText< T extends HTMLElement >( node: T, options: TaggableOptions ): T {
	const catalogue = catalogueFor( options );

	node.addEventListener( 'input', ( event ) => {
		const typed = event as InputEvent;

		if ( typed.isComposing || typed.data !== '{' ) {
			return;
		}

		const text = node.textContent ?? '';
		const caret = caretOffset( node );

		if ( text[ caret - 1 ] !== '{' ) {
			return;
		}

		const start = caret - 1;
		const end = caret + ( text[ caret ] === '}' ? 1 : 0 );

		showPicker( {
			catalogue,
			container: node.closest< HTMLElement >( '.atfb' ) ?? node.ownerDocument.body,
			anchor: node,
			floating: true,
			returnFocus: node,
			intro: options.intro ?? DEFAULT_INTRO,
			stillValid: () => node.isConnected && node.textContent === text,
			onPick: ( tag ) => {
				const current = node.textContent ?? '';

				node.textContent = current.slice( 0, start ) + tag + current.slice( end );
				node.focus();
				placeCaret( node, start + tag.length );
				node.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			},
			onClose: () => {
				if ( node.ownerDocument.activeElement !== node ) {
					node.dispatchEvent( new FocusEvent( 'blur' ) );
				}
			},
		} );
	} );

	return node;
}

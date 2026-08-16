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
 *    wrote. Nobody has to type a brace. The tag is shown beside each label, small
 *    and secondary, which is how the syntax gets learned rather than taught.
 *
 * 2. **A resolved preview.** Under each box, the same text with the tags filled
 *    in from sample values. The reason merge tags feel like guesswork is that you
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
 * plugin added through `atf_resolve_merge_tag`.
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
 * Uses each tag's sample value. A tag nobody recognises is left visible rather
 * than blanked, because that is what the server does with it too — and a preview
 * that quietly swallowed a typo would hide the one mistake this is here to
 * catch.
 */
export function resolvePreview( text: string, groups: MergeTagGroup[] ): string {
	const all = flatten( groups );

	return text.replace( /\{[a-z_]+(?::[^}]*)?\}/gi, ( match ) => {
		const known = all.get( match.toLowerCase() );

		return known ? known.sample : match;
	} );
}

/** Whether a string contains anything that looks like a tag. */
export function hasTags( text: string ): boolean {
	return /\{[a-z_]+(?::[^}]*)?\}/i.test( text );
}

/** The one open picker, so a second Insert click does not stack two. */
let openPicker: HTMLElement | null = null;

/** Closes whatever picker is open. */
function closePicker(): void {
	openPicker?.remove();
	openPicker = null;
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

		if ( openPicker && ! openPicker.contains( target ) ) {
			closePicker();
		}
	} );

	document.addEventListener( 'keydown', ( event ) => {
		if ( 'Escape' === event.key && openPicker ) {
			closePicker();
			event.stopPropagation();
		}
	} );
}

/**
 * Puts a tag into a field at the cursor.
 *
 * At the cursor rather than appended, because a subject line is usually
 * "New enquiry from ‹here›" and appending would make every insertion need a
 * cut-and-paste afterwards. The caret lands after the inserted tag so a second
 * insertion continues where the first left off.
 */
function insertAtCursor( field: HTMLInputElement | HTMLTextAreaElement, text: string ): void {
	const start = field.selectionStart ?? field.value.length;
	const end = field.selectionEnd ?? field.value.length;

	field.value = field.value.slice( 0, start ) + text + field.value.slice( end );

	const caret = start + text.length;

	field.setSelectionRange( caret, caret );
	field.focus();

	// A programmatic value change fires nothing, so the pane's own `input`
	// handler — the thing that marks the form dirty — would never run.
	field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
}

/**
 * The lowest point a popover can reach before something clips it.
 *
 * Walks up to the first ancestor that scrolls — in the builder that is
 * `.atfb__canvas`, whose `overflow-y: auto` is what actually cuts the popover
 * off. Falls back to the viewport on a plain admin page, where the document
 * itself is the scroller.
 */
function clipBottom( from: HTMLElement ): number {
	let node: HTMLElement | null = from.parentElement;

	while ( node && node !== document.body ) {
		const overflow = getComputedStyle( node ).overflowY;

		if ( 'auto' === overflow || 'scroll' === overflow || 'hidden' === overflow ) {
			return node.getBoundingClientRect().bottom;
		}

		node = node.parentElement;
	}

	return window.innerHeight;
}

/** Builds the popover list. */
function buildPicker(
	groups: MergeTagGroup[],
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
					item.tag.toLowerCase().includes( needle )
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
							item.hint || item.sample
								? el( 'span', {
										class: 'atfb-tagpick__meta',
										// The sample is the part people actually read, so it
										// leads; the hint explains the cases where it is empty.
										text: item.sample ? `e.g. ${ item.sample }` : item.hint,
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

	return el( 'div', {
		class: 'atfb-tagpick',
		attrs: { role: 'dialog', 'aria-label': 'Insert a value' },
		children: [
			el( 'p', {
				class: 'atfb-tagpick__intro',
				text: 'Pick something to drop in. It is filled in when the form is submitted.',
			} ),
			search,
			list,
		],
	} );
}

/** Options for a tag-aware control. */
interface TaggableOptions {
	formId: number;
	/** Shown under the box as “Reads as: …”. Off for one-line URLs, where it adds noise. */
	preview?: boolean;
	/** Extra text under the control, before the preview. */
	hint?: string;
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
	const insert = el( 'button', {
		class: 'atfb-button atfb-button--ghost atfb-tagpick__open',
		type: 'button',
		title: 'Insert a value from the submission',
		children: [ icon( 'shortcode' ), el( 'span', { text: 'Insert a value' } ) ],
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

		void mergeTags( options.formId ).then( ( groups ) => {
			preview.hidden = false;
			preview.replaceChildren(
				el( 'span', { class: 'atfb-taggable__preview-label', text: 'Reads as' } ),
				el( 'span', { text: resolvePreview( field.value, groups ) } )
			);
		} );
	};

	field.addEventListener( 'input', repaint );
	repaint();

	insert.addEventListener( 'click', ( event ) => {
		event.stopPropagation();

		if ( openPicker && wrapper.contains( openPicker ) ) {
			closePicker();

			return;
		}

		closePicker();

		void mergeTags( options.formId ).then( ( groups ) => {
			const picker = buildPicker( groups, ( tag ) => {
				insertAtCursor( field, tag );
				repaint();
			} );

			wrapper.append( picker );
			openPicker = picker;

			// Flip above the button when there is no room below it. The Message box
			// is the tallest control in the pane and sits near the bottom of the
			// window, so the picker opened from it is exactly the one that would
			// otherwise be clipped — and a list whose last rows cannot be reached is
			// indistinguishable from a list that does not contain them.
			//
			// Measured against the scrolling pane, not the viewport. Inside an
			// OpenStation window the viewport is the whole desktop and is always
			// roomy, while the pane that actually clips the popover is a few hundred
			// pixels tall — so a viewport test says "plenty of room" in precisely
			// the case where there is none.
			if ( window.innerHeight - picker.getBoundingClientRect().top < picker.offsetHeight ) {
				picker.classList.add( 'atfb-tagpick--above' );
			} else if ( picker.getBoundingClientRect().bottom > clipBottom( wrapper ) ) {
				picker.classList.add( 'atfb-tagpick--above' );
			}

			picker.querySelector< HTMLInputElement >( '.atfb-tagpick__search' )?.focus();
		} );
	} );

	return wrapper;
}

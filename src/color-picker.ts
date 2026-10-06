/**
 * The colour picker on the front end.
 *
 * The server renders a hex text box with a chip beside it — a complete control
 * with no JavaScript at all. This module turns the chip into a button that
 * opens a small panel: a saturation/brightness area, a hue track, the form's
 * suggested colours, and the browser's eyedropper where there is one.
 *
 * Every visual decision in the panel is read from the form theme's tokens in
 * `form.css`, never from the browser's own colour widget, so it sits in a
 * neon theme, a glass theme and a plain one equally well. The only colours
 * hard-coded anywhere are the ones that *are* the colour space — the white
 * and black ramps of the area and the rainbow of the hue track.
 *
 * The text box stays the one named input. The panel writes into it and fires
 * the same `input` event typing does, so conditional logic, validation and the
 * submission all see a single value, and nothing here has to be trusted: the
 * server normalises what arrives through `alltfo_normalize_hex_color()`.
 */

/** A colour in the picker's own space: hue in degrees, the rest 0–1. */
export interface Hsv {
	h: number;
	s: number;
	v: number;
}

/** Looks up a translated string, falling back to the English. */
export type Translate = ( key: string, fallback: string ) => string;

/**
 * Normalises a typed colour to a lower-case, six-digit hex code.
 *
 * Mirrors `alltfo_normalize_hex_color()` in PHP, so what the visitor sees in
 * the box once they leave it is exactly what is stored.
 *
 * @param raw What was typed: `#3366ff`, `3366FF`, `#36f`.
 * @return The code, or an empty string when it is not one.
 */
export function normalizeHex( raw: string ): string {
	const trimmed = raw.trim().replace( /^#/, '' );

	if ( ! /^([0-9a-f]{3}){1,2}$/i.test( trimmed ) ) {
		return '';
	}

	const six =
		3 === trimmed.length
			? trimmed
					.split( '' )
					.map( ( digit ) => digit + digit )
					.join( '' )
			: trimmed;

	return `#${ six.toLowerCase() }`;
}

/**
 * Converts a hex code to hue, saturation and brightness.
 *
 * @param hex A normalised six-digit code.
 * @return The colour in HSV.
 */
export function hexToHsv( hex: string ): Hsv {
	const r = parseInt( hex.slice( 1, 3 ), 16 ) / 255;
	const g = parseInt( hex.slice( 3, 5 ), 16 ) / 255;
	const b = parseInt( hex.slice( 5, 7 ), 16 ) / 255;
	const max = Math.max( r, g, b );
	const delta = max - Math.min( r, g, b );

	let h = 0;

	if ( delta ) {
		if ( max === r ) {
			h = ( ( g - b ) / delta ) % 6;
		} else if ( max === g ) {
			h = ( b - r ) / delta + 2;
		} else {
			h = ( r - g ) / delta + 4;
		}

		h = ( h * 60 + 360 ) % 360;
	}

	return { h, s: max ? delta / max : 0, v: max };
}

/**
 * Converts hue, saturation and brightness to a hex code.
 *
 * @param hsv The colour.
 * @return A lower-case six-digit code.
 */
export function hsvToHex( { h, s, v }: Hsv ): string {
	const channel = ( n: number ): string => {
		const k = ( n + h / 60 ) % 6;
		const value = v - v * s * Math.max( 0, Math.min( k, 4 - k, 1 ) );

		return Math.round( value * 255 )
			.toString( 16 )
			.padStart( 2, '0' );
	};

	return `#${ channel( 5 ) }${ channel( 3 ) }${ channel( 1 ) }`;
}

const clamp = ( value: number, min = 0, max = 1 ): number => Math.min( max, Math.max( min, value ) );

let instances = 0;

/**
 * Turns one server-rendered colour field into a picker.
 *
 * Safe to call twice on the same wrapper, and on a wrapper inside a repeater
 * row cloned after the page loaded.
 *
 * @param wrapper The `[data-atf-color]` element.
 * @param t       Translates the panel's strings.
 */
export function enhanceColorField( wrapper: HTMLElement, t: Translate ): void {
	if ( wrapper.dataset.atfColorReady || wrapper.closest( 'template' ) ) {
		return;
	}

	const input = wrapper.querySelector< HTMLInputElement >( '.atf-color__input' );
	const chip = wrapper.querySelector< HTMLElement >( '.atf-color__chip' );

	if ( ! input || ! chip ) {
		return;
	}

	wrapper.dataset.atfColorReady = '1';
	instances++;

	const panelId = `${ input.id || 'atf-color' }-panel-${ instances }`;
	const make = < K extends keyof HTMLElementTagNameMap >( tag: K, className: string ): HTMLElementTagNameMap[ K ] => {
		const node = document.createElement( tag );

		node.className = className;

		return node;
	};

	/* ------------------------------------------------------------ Markup -- */

	const toggle = make( 'button', 'atf-color__swatch' );

	toggle.type = 'button';
	toggle.setAttribute( 'aria-label', t( 'chooseColor', 'Choose a colour' ) );
	toggle.setAttribute( 'aria-haspopup', 'dialog' );
	toggle.setAttribute( 'aria-expanded', 'false' );
	toggle.setAttribute( 'aria-controls', panelId );
	chip.replaceWith( toggle );
	toggle.append( chip );

	const panel = make( 'div', 'atf-color__panel' );

	panel.id = panelId;
	panel.hidden = true;
	panel.setAttribute( 'role', 'dialog' );
	panel.setAttribute( 'aria-label', t( 'chooseColor', 'Choose a colour' ) );

	const area = make( 'div', 'atf-color__area' );
	const areaThumb = make( 'span', 'atf-color__thumb' );

	area.tabIndex = 0;
	area.setAttribute( 'role', 'slider' );
	area.setAttribute( 'aria-roledescription', '2D slider' );
	area.setAttribute( 'aria-label', t( 'colorArea', 'Saturation and brightness' ) );
	area.setAttribute( 'aria-valuemin', '0' );
	area.setAttribute( 'aria-valuemax', '100' );
	area.append( areaThumb );

	const hue = make( 'div', 'atf-color__hue' );
	const hueThumb = make( 'span', 'atf-color__thumb' );

	hue.tabIndex = 0;
	hue.setAttribute( 'role', 'slider' );
	hue.setAttribute( 'aria-label', t( 'colorHue', 'Hue' ) );
	hue.setAttribute( 'aria-valuemin', '0' );
	hue.setAttribute( 'aria-valuemax', '359' );
	hue.append( hueThumb );

	panel.append( area, hue );

	const swatches = ( wrapper.dataset.atfSwatches ?? '' )
		.split( ',' )
		.map( normalizeHex )
		.filter( Boolean );
	const swatchButtons: HTMLButtonElement[] = [];

	if ( swatches.length ) {
		const group = make( 'div', 'atf-color__swatches' );

		group.setAttribute( 'role', 'group' );
		group.setAttribute( 'aria-label', t( 'colorSwatches', 'Suggested colours' ) );

		for ( const hex of swatches ) {
			const button = make( 'button', 'atf-color__preset' );

			button.type = 'button';
			button.value = hex;
			button.style.backgroundColor = hex;
			button.setAttribute( 'aria-label', hex );
			button.setAttribute( 'aria-pressed', 'false' );
			button.addEventListener( 'click', () => {
				hsv = hexToHsv( hex );
				commit( hex );
			} );
			swatchButtons.push( button );
			group.append( button );
		}

		panel.append( group );
	}

	const actions = make( 'div', 'atf-color__actions' );
	const EyeDropperApi = ( window as unknown as { EyeDropper?: new () => { open: () => Promise< { sRGBHex: string } > } } )
		.EyeDropper;

	if ( EyeDropperApi ) {
		const pick = make( 'button', 'atf-color__action' );

		pick.type = 'button';
		pick.textContent = t( 'colorPick', 'Pick a colour from the screen' );
		pick.addEventListener( 'click', () => {
			new EyeDropperApi()
				.open()
				.then( ( result ) => {
					const hex = normalizeHex( result.sRGBHex );

					if ( hex ) {
						hsv = hexToHsv( hex );
						commit( hex );
					}
				} )
				// Escape dismisses the eyedropper by rejecting — not an error.
				.catch( () => undefined );
		} );
		actions.append( pick );
	}

	// An optional colour must be able to go back to "no answer", which the
	// native control never allowed.
	if ( ! input.required ) {
		const none = make( 'button', 'atf-color__action' );

		none.type = 'button';
		none.textContent = t( 'colorNone', 'No colour' );
		none.addEventListener( 'click', () => {
			commit( '' );
			close( true );
		} );
		actions.append( none );
	}

	if ( actions.childElementCount ) {
		panel.append( actions );
	}

	wrapper.append( panel );

	/* ------------------------------------------------------------- State -- */

	const initial = normalizeHex( input.value );

	// Somewhere pleasant to start when the field is empty, so the first drag
	// on the area does not begin from black.
	let hsv: Hsv = initial ? hexToHsv( initial ) : { h: 210, s: 0.65, v: 0.85 };
	let valueAtOpen = input.value;

	/** Repaints the panel and the chip from `hsv` and the input. */
	const paint = (): void => {
		const current = normalizeHex( input.value );

		chip.classList.toggle( 'is-empty', ! current );
		chip.style.backgroundColor = current;

		// Physical `left`, on purpose: the white-to-colour ramp is a physical
		// left-to-right gradient, and the thumb has to sit on the colour it
		// stands for in a right-to-left form too. The tracks are `direction:
		// ltr` in the stylesheet for the same reason.
		area.style.backgroundColor = `hsl(${ Math.round( hsv.h ) }, 100%, 50%)`;
		areaThumb.style.left = `${ hsv.s * 100 }%`;
		areaThumb.style.top = `${ ( 1 - hsv.v ) * 100 }%`;
		areaThumb.style.backgroundColor = hsvToHex( hsv );
		hueThumb.style.left = `${ ( hsv.h / 360 ) * 100 }%`;
		hueThumb.style.backgroundColor = `hsl(${ Math.round( hsv.h ) }, 100%, 50%)`;

		area.setAttribute( 'aria-valuenow', String( Math.round( hsv.s * 100 ) ) );
		area.setAttribute(
			'aria-valuetext',
			`${ Math.round( hsv.s * 100 ) }%, ${ Math.round( hsv.v * 100 ) }% · ${ current || hsvToHex( hsv ) }`
		);
		hue.setAttribute( 'aria-valuenow', String( Math.round( hsv.h ) ) );
		hue.setAttribute( 'aria-valuetext', `${ Math.round( hsv.h ) }°` );

		for ( const button of swatchButtons ) {
			button.setAttribute( 'aria-pressed', String( button.value === current ) );
		}
	};

	/** Writes a colour into the input as if it had been typed. */
	const commit = ( hex: string ): void => {
		if ( input.value !== hex ) {
			input.value = hex;
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		}

		paint();
	};

	const fromHsv = (): void => commit( hsvToHex( hsv ) );

	/* --------------------------------------------------------- Open/close -- */

	const open = (): void => {
		const current = normalizeHex( input.value );

		if ( current ) {
			// Keep the hue when the colour is a grey: a grey has none of its
			// own, and snapping the track to red each time is disorienting.
			const next = hexToHsv( current );

			hsv = next.s ? next : { ...next, h: hsv.h };
		}

		valueAtOpen = input.value;
		panel.hidden = false;
		toggle.setAttribute( 'aria-expanded', 'true' );
		wrapper.classList.add( 'is-open' );
		paint();
		area.focus();
	};

	const close = ( returnFocus = false ): void => {
		if ( panel.hidden ) {
			return;
		}

		panel.hidden = true;
		toggle.setAttribute( 'aria-expanded', 'false' );
		wrapper.classList.remove( 'is-open' );

		if ( input.value !== valueAtOpen ) {
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}

		if ( returnFocus ) {
			toggle.focus();
		}
	};

	toggle.addEventListener( 'click', () => ( panel.hidden ? open() : close( true ) ) );

	panel.addEventListener( 'keydown', ( event ) => {
		if ( 'Escape' === event.key ) {
			event.preventDefault();
			event.stopPropagation();
			close( true );
		}
	} );

	// Leaving the field — by Tab or by clicking anywhere else — puts the panel
	// away. Checked on the next frame: focus moving *within* the wrapper
	// passes through `body` for a moment in some browsers.
	wrapper.addEventListener( 'focusout', () => {
		requestAnimationFrame( () => {
			if ( ! wrapper.contains( document.activeElement ) ) {
				close();
			}
		} );
	} );

	document.addEventListener( 'pointerdown', ( event ) => {
		if ( ! panel.hidden && ! wrapper.contains( event.target as Node ) ) {
			close();
		}
	} );

	/* ----------------------------------------------------------- Dragging -- */

	const track = ( surface: HTMLElement, apply: ( x: number, y: number ) => void ): void => {
		const move = ( event: PointerEvent ): void => {
			const box = surface.getBoundingClientRect();

			apply(
				clamp( ( event.clientX - box.left ) / ( box.width || 1 ) ),
				clamp( ( event.clientY - box.top ) / ( box.height || 1 ) )
			);
			fromHsv();
		};

		surface.addEventListener( 'pointerdown', ( event ) => {
			event.preventDefault();
			surface.focus();
			surface.setPointerCapture?.( event.pointerId );
			move( event );
		} );

		surface.addEventListener( 'pointermove', ( event ) => {
			if ( surface.hasPointerCapture?.( event.pointerId ) ) {
				move( event );
			}
		} );
	};

	track( area, ( x, y ) => {
		hsv = { ...hsv, s: x, v: 1 - y };
	} );

	track( hue, ( x ) => {
		hsv = { ...hsv, h: Math.min( 359.9, x * 360 ) };
	} );

	/* ----------------------------------------------------------- Keyboard -- */

	area.addEventListener( 'keydown', ( event ) => {
		const step = event.shiftKey ? 0.1 : 0.01;
		const moves: Record< string, [ number, number ] > = {
			ArrowLeft: [ -step, 0 ],
			ArrowRight: [ step, 0 ],
			ArrowUp: [ 0, step ],
			ArrowDown: [ 0, -step ],
		};
		const delta = moves[ event.key ];

		if ( ! delta ) {
			return;
		}

		event.preventDefault();
		hsv = { ...hsv, s: clamp( hsv.s + delta[ 0 ] ), v: clamp( hsv.v + delta[ 1 ] ) };
		fromHsv();
	} );

	hue.addEventListener( 'keydown', ( event ) => {
		const step = event.shiftKey ? 10 : 1;
		const next: Record< string, number > = {
			ArrowLeft: hsv.h - step,
			ArrowDown: hsv.h - step,
			ArrowRight: hsv.h + step,
			ArrowUp: hsv.h + step,
			PageDown: hsv.h - 10,
			PageUp: hsv.h + 10,
			Home: 0,
			End: 359,
		};

		if ( ! ( event.key in next ) ) {
			return;
		}

		event.preventDefault();
		hsv = { ...hsv, h: clamp( next[ event.key ], 0, 359 ) };
		fromHsv();
	} );

	/* ------------------------------------------------------------- Typing -- */

	// The chip follows the box as somebody types, the moment it holds a
	// colour; the box is only tidied — `36F` to `#3366ff` — once they leave it,
	// because rewriting text under a caret is how keystrokes get lost.
	input.addEventListener( 'input', () => {
		const current = normalizeHex( input.value );

		if ( current ) {
			const next = hexToHsv( current );

			hsv = next.s ? next : { ...next, h: hsv.h };
		}

		paint();
	} );

	input.addEventListener( 'change', () => {
		const current = normalizeHex( input.value );

		if ( current && current !== input.value ) {
			input.value = current;
		}

		paint();
	} );

	// A form reset puts the box back without firing `input`.
	input.form?.addEventListener( 'reset', () => setTimeout( paint ) );

	paint();
}

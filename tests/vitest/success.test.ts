/**
 * The success screen renderer.
 *
 * The renderer is the same module the front end and the builder preview both
 * run, and its markup is a contract shared with PHP's static fallback — the
 * class names asserted here are the ones `atf_success_screen_html()` emits and
 * `form.css` styles. What these tests pin: `plain` is byte-for-byte the old
 * confirmation, an unknown style can never escape as a class name, and the
 * per-style structure (icon fallbacks, the drawn check, the typewriter's
 * accessible name) is what the stylesheet expects to find.
 */

// @vitest-environment jsdom

import { describe, expect, it, vi } from 'vitest';
import {
	SUCCESS_STYLE_ICONS,
	defaultSuccessScreen,
	normalizeSuccessScreen,
	playSuccessEffects,
	renderSuccessScreen,
} from '../../src/success';
import type { SuccessScreen } from '../../src/types';

/** A full config from a partial one, for terser cases. */
function config( partial: Partial< SuccessScreen > ): SuccessScreen {
	return { ...defaultSuccessScreen(), ...partial };
}

describe( 'normalizeSuccessScreen', () => {
	it( 'fills a missing config out to the defaults', () => {
		const success = normalizeSuccessScreen( undefined );

		expect( success.style ).toBe( 'simple' );
		expect( success.intensity ).toBe( 'medium' );
		expect( success.showButton ).toBe( false );
	} );

	it( 'refuses a style it does not know', () => {
		const success = normalizeSuccessScreen( { style: 'raveparty' as SuccessScreen[ 'style' ] } );

		expect( success.style ).toBe( 'simple' );
	} );
} );

describe( 'renderSuccessScreen', () => {
	it( 'renders plain as exactly the old confirmation', () => {
		const screen = renderSuccessScreen( '<p>Done.</p>', config( { style: 'plain' } ) );

		expect( screen.className ).toBe( 'atf-confirmation' );
		expect( screen.getAttribute( 'role' ) ).toBe( 'status' );
		expect( screen.getAttribute( 'tabindex' ) ).toBe( '-1' );
		expect( screen.innerHTML ).toBe( '<p>Done.</p>' );
		expect( screen.querySelector( '.atf-success__inner' ) ).toBeNull();
	} );

	it( 'wears the style as a class and keeps the message HTML', () => {
		const screen = renderSuccessScreen( '<p>Saved.</p>', config( { style: 'card', title: 'Thanks!' } ) );

		expect( screen.classList.contains( 'atf-success' ) ).toBe( true );
		expect( screen.classList.contains( 'atf-success--card' ) ).toBe( true );
		expect( screen.querySelector( '.atf-success__title' )?.textContent ).toBe( 'Thanks!' );
		expect( screen.querySelector( '.atf-success__message' )?.innerHTML ).toBe( '<p>Saved.</p>' );
	} );

	it( 'falls back to the style icon and lets the author override it', () => {
		const fallback = renderSuccessScreen( 'Hi', config( { style: 'confetti' } ) );
		const chosen = renderSuccessScreen( 'Hi', config( { style: 'confetti', icon: '🎈' } ) );

		expect( fallback.querySelector( '.atf-success__icon' )?.textContent ).toBe( SUCCESS_STYLE_ICONS.confetti );
		expect( chosen.querySelector( '.atf-success__icon' )?.textContent ).toBe( '🎈' );
	} );

	it( 'draws the check as an SVG, not an emoji', () => {
		const screen = renderSuccessScreen( 'Hi', config( { style: 'check' } ) );

		expect( screen.querySelector( 'svg.atf-success__check' ) ).not.toBeNull();
		expect( screen.querySelector( '.atf-success__check-mark' ) ).not.toBeNull();
		expect( screen.querySelector( '.atf-success__icon' ) ).toBeNull();
	} );

	it( 'scopes the accent to the screen as the theme token', () => {
		const screen = renderSuccessScreen( 'Hi', config( { style: 'simple', accent: '#ff0055' } ) );

		expect( screen.style.getPropertyValue( '--atf-accent' ) ).toBe( '#ff0055' );
	} );

	it( 'gives the typewriter an accessible name for what it has not typed yet', () => {
		const screen = renderSuccessScreen( '<p>All done here.</p>', config( { style: 'typewriter' } ) );

		expect( screen.getAttribute( 'aria-label' ) ).toBe( 'All done here.' );
	} );

	it( 'wires the again-button to the caller, not the page', () => {
		const onAgain = vi.fn();
		const screen = renderSuccessScreen( 'Hi', config( { style: 'simple', showButton: true, buttonLabel: 'Once more' } ), onAgain );
		const button = screen.querySelector< HTMLButtonElement >( '.atf-success__again' );

		expect( button?.textContent ).toBe( 'Once more' );

		button?.click();

		expect( onAgain ).toHaveBeenCalledTimes( 1 );
	} );
} );

describe( 'playSuccessEffects', () => {
	it( 'types the message out and restores the real markup at the end', () => {
		vi.useFakeTimers();

		const screen = renderSuccessScreen( '<p>Hello there</p>', config( { style: 'typewriter' } ) );
		document.body.append( screen );

		const body = screen.querySelector< HTMLElement >( '.atf-success__message' )!;
		const cleanup = playSuccessEffects( screen, config( { style: 'typewriter' } ) );

		expect( body.textContent ).toBe( '' );
		expect( body.classList.contains( 'is-typing' ) ).toBe( true );

		vi.runAllTimers();

		expect( body.innerHTML ).toBe( '<p>Hello there</p>' );
		expect( body.classList.contains( 'is-typing' ) ).toBe( false );

		cleanup();
		screen.remove();
		vi.useRealTimers();
	} );

	it( 'survives an environment with no canvas and cleans up after itself', () => {
		// jsdom has no 2D context, which is exactly the failure mode a hostile
		// or ancient browser presents: the effect must degrade to nothing, not
		// leave a dead canvas over the page.
		const screen = renderSuccessScreen( 'Hi', config( { style: 'confetti' } ) );
		document.body.append( screen );

		const cleanup = playSuccessEffects( screen, config( { style: 'confetti' } ) );

		expect( document.querySelector( '.atf-success-canvas' ) ).toBeNull();

		cleanup();
		screen.remove();
	} );

	it( 'does nothing for the calm styles', () => {
		const screen = renderSuccessScreen( 'Hi', config( { style: 'minimal' } ) );
		document.body.append( screen );

		const before = document.body.innerHTML;

		playSuccessEffects( screen, config( { style: 'minimal' } ) )();

		expect( document.body.innerHTML ).toBe( before );

		screen.remove();
	} );
} );

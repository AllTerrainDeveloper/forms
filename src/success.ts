/**
 * The success screen: what the moment after a submission looks like.
 *
 * One renderer shared by the front-end bundle (the real thing) and the builder
 * (the preview), so what an author sees when they press "Preview" is the code
 * path their visitors get, not an imitation of it.
 *
 * The screen itself is DOM + CSS; the celebrations are effects layered on top:
 * two canvas simulations (confetti, fireworks), one DOM particle system
 * (sparkles) and one text effect (typewriter). Every effect honours
 * `prefers-reduced-motion` by not running — the screen still appears, via the
 * stylesheet's own reduced-motion-safe entrance.
 */

import type { SuccessScreen, SuccessStyle } from './types';

/** The icon each style falls back to when the author sets none. */
export const SUCCESS_STYLE_ICONS: Record< SuccessStyle, string > = {
	plain: '',
	simple: '✓',
	minimal: '',
	card: '🎉',
	check: '',
	confetti: '🎉',
	fireworks: '🎆',
	sparkles: '✨',
	typewriter: '',
};

/** A complete success screen config with every default. */
export function defaultSuccessScreen(): SuccessScreen {
	return {
		style: 'simple',
		title: '',
		icon: '',
		accent: '',
		intensity: 'medium',
		showButton: false,
		buttonLabel: '',
	};
}

/** Fills a possibly partial config out to a complete one. */
export function normalizeSuccessScreen( raw: Partial< SuccessScreen > | undefined ): SuccessScreen {
	const success = { ...defaultSuccessScreen(), ...( raw ?? {} ) };

	if ( ! ( success.style in SUCCESS_STYLE_ICONS ) ) {
		success.style = 'simple';
	}

	return success;
}

/** Whether the visitor asked for less motion; every effect defers to it. */
function reducedMotion(): boolean {
	return typeof window.matchMedia === 'function' && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
}

/**
 * Builds the screen itself: icon, title, message, optional again-button.
 *
 * Mirrors `atf_success_screen_html()` in PHP — same classes, same structure —
 * so the stylesheet serves both and the no-JavaScript fallback looks like the
 * real thing minus the moving parts.
 */
export function renderSuccessScreen(
	message: string,
	raw: Partial< SuccessScreen > | undefined,
	onAgain?: () => void
): HTMLElement {
	const success = normalizeSuccessScreen( raw );

	const root = document.createElement( 'div' );
	root.className = `atf-confirmation atf-success atf-success--${ success.style }`;
	root.setAttribute( 'role', 'status' );
	root.setAttribute( 'tabindex', '-1' );

	if ( success.style === 'plain' ) {
		root.className = 'atf-confirmation';
		root.innerHTML = message;

		return root;
	}

	if ( success.accent ) {
		// The accent recolours everything inside the screen that reads the
		// theme's accent token, which is exactly what "accent" should mean.
		root.style.setProperty( '--atf-accent', success.accent );
	}

	const inner = document.createElement( 'div' );
	inner.className = 'atf-success__inner';
	root.append( inner );

	const icon = success.icon || SUCCESS_STYLE_ICONS[ success.style ];

	if ( success.style === 'check' ) {
		inner.insertAdjacentHTML(
			'beforeend',
			'<svg class="atf-success__check" viewBox="0 0 52 52" aria-hidden="true">' +
				'<circle class="atf-success__check-ring" cx="26" cy="26" r="24" fill="none" />' +
				'<path class="atf-success__check-mark" fill="none" d="M14 27l8 8 16-17" /></svg>'
		);
	} else if ( icon ) {
		const glyph = document.createElement( 'span' );
		glyph.className = 'atf-success__icon';
		glyph.setAttribute( 'aria-hidden', 'true' );
		glyph.textContent = icon;
		inner.append( glyph );
	}

	if ( success.title ) {
		const title = document.createElement( 'h2' );
		title.className = 'atf-success__title';
		title.textContent = success.title;
		inner.append( title );
	}

	const body = document.createElement( 'div' );
	body.className = 'atf-success__message';
	body.innerHTML = message;
	inner.append( body );

	if ( success.style === 'typewriter' ) {
		// The screen is announced from its accessible name while the visible
		// text is still typing itself out one letter at a time.
		root.setAttribute( 'aria-label', body.textContent ?? '' );
	}

	if ( success.showButton ) {
		const again = document.createElement( 'button' );
		again.type = 'button';
		again.className = 'atf-button atf-button--ghost atf-success__again';
		again.textContent = success.buttonLabel || 'Fill it in again';
		again.addEventListener( 'click', () => ( onAgain ? onAgain() : window.location.reload() ) );
		inner.append( again );
	}

	return root;
}

/**
 * Runs the style's celebration, if it has one.
 *
 * @param root    The screen `renderSuccessScreen()` built, already in the DOM.
 * @param raw     The success config.
 * @return A cleanup function that stops the effect and removes what it added.
 */
export function playSuccessEffects( root: HTMLElement, raw: Partial< SuccessScreen > | undefined ): () => void {
	const success = normalizeSuccessScreen( raw );

	if ( reducedMotion() ) {
		return () => {};
	}

	switch ( success.style ) {
		case 'confetti':
			return confetti( root, success );
		case 'fireworks':
			return fireworks( success );
		case 'sparkles':
			return sparkles( root, success );
		case 'typewriter':
			return typewriter( root );
		default:
			return () => {};
	}
}

/** Particle counts and lifetimes per intensity. */
function scale( success: SuccessScreen ): number {
	return { low: 0.5, medium: 1, high: 1.8 }[ success.intensity ];
}

/** A full-viewport canvas that stays out of the way and cleans up after itself. */
function makeCanvas(): { canvas: HTMLCanvasElement; ctx: CanvasRenderingContext2D | null; stop: () => void } {
	const canvas = document.createElement( 'canvas' );
	canvas.className = 'atf-success-canvas';
	canvas.setAttribute( 'aria-hidden', 'true' );

	const dpr = Math.min( window.devicePixelRatio || 1, 2 );
	canvas.width = Math.floor( window.innerWidth * dpr );
	canvas.height = Math.floor( window.innerHeight * dpr );

	document.body.append( canvas );

	const ctx = canvas.getContext( '2d' );
	ctx?.scale( dpr, dpr );

	return { canvas, ctx, stop: () => canvas.remove() };
}

const CONFETTI_COLORS = [ '#f43f5e', '#f59e0b', '#10b981', '#3b82f6', '#8b5cf6', '#ec4899', '#eab308' ];

interface ConfettiPiece {
	x: number;
	y: number;
	vx: number;
	vy: number;
	w: number;
	h: number;
	angle: number;
	spin: number;
	color: string;
	wobble: number;
}

/**
 * Confetti: one burst up from the screen itself, then a rain from the top.
 *
 * The burst is what makes it feel caused by the submission rather than merely
 * concurrent with it — the paper comes out of the thank-you, not the sky —
 * and the rain is what makes it feel abundant.
 */
function confetti( root: HTMLElement, success: SuccessScreen ): () => void {
	const { ctx, stop } = makeCanvas();

	// No 2D context — a locked-down or ancient browser. The dead canvas comes
	// straight back off the page and the screen simply stays still.
	if ( ! ctx ) {
		stop();

		return () => {};
	}

	const colors = success.accent ? [ success.accent, ...CONFETTI_COLORS ] : CONFETTI_COLORS;
	const pieces: ConfettiPiece[] = [];
	const rect = root.getBoundingClientRect();
	const originX = rect.left + rect.width / 2;
	const originY = Math.min( rect.top + 40, window.innerHeight - 20 );

	const make = ( x: number, y: number, burst: boolean ): ConfettiPiece => {
		const angle = burst ? Math.PI * ( 1.15 + 0.7 * Math.random() ) : 0;
		const speed = burst ? 9 + Math.random() * 8 : 0;

		return {
			x,
			y,
			vx: burst ? Math.cos( angle ) * speed * ( Math.random() < 0.5 ? 1 : -1 ) : ( Math.random() - 0.5 ) * 1.5,
			vy: burst ? Math.sin( angle ) * speed : 1 + Math.random() * 2,
			w: 6 + Math.random() * 5,
			h: 8 + Math.random() * 7,
			angle: Math.random() * Math.PI,
			spin: ( Math.random() - 0.5 ) * 0.3,
			color: colors[ Math.floor( Math.random() * colors.length ) ],
			wobble: Math.random() * Math.PI * 2,
		};
	};

	const burstCount = Math.round( 90 * scale( success ) );

	for ( let i = 0; i < burstCount; i++ ) {
		pieces.push( make( originX, originY, true ) );
	}

	// The rain arrives over the first couple of seconds rather than all at once.
	const rainCount = Math.round( 70 * scale( success ) );
	let rained = 0;
	const rain = window.setInterval( () => {
		if ( rained >= rainCount ) {
			window.clearInterval( rain );

			return;
		}

		pieces.push( make( Math.random() * window.innerWidth, -20, false ) );
		rained++;
	}, 2000 / rainCount );

	let frame = 0;
	const started = performance.now();

	const tick = ( now: number ) => {
		ctx.clearRect( 0, 0, window.innerWidth, window.innerHeight );

		let alive = false;

		for ( const piece of pieces ) {
			piece.vy += 0.16;
			piece.vx *= 0.99;
			piece.vy *= 0.985;
			piece.wobble += 0.1;
			piece.x += piece.vx + Math.sin( piece.wobble ) * 0.8;
			piece.y += piece.vy;
			piece.angle += piece.spin;

			if ( piece.y < window.innerHeight + 30 ) {
				alive = true;
			}

			ctx.save();
			ctx.translate( piece.x, piece.y );
			ctx.rotate( piece.angle );
			// A cosine on the wobble fakes the third dimension: the piece
			// narrows as it "turns over" in the air.
			ctx.scale( 1, 0.4 + 0.6 * Math.abs( Math.cos( piece.wobble ) ) );
			ctx.fillStyle = piece.color;
			ctx.fillRect( -piece.w / 2, -piece.h / 2, piece.w, piece.h );
			ctx.restore();
		}

		if ( alive && now - started < 7000 ) {
			frame = window.requestAnimationFrame( tick );
		} else {
			stop();
		}
	};

	frame = window.requestAnimationFrame( tick );

	return () => {
		window.clearInterval( rain );
		window.cancelAnimationFrame( frame );
		stop();
	};
}

interface Spark {
	x: number;
	y: number;
	vx: number;
	vy: number;
	life: number;
	decay: number;
	hue: number;
}

interface Rocket {
	x: number;
	y: number;
	vy: number;
	targetY: number;
	hue: number;
}

/** Fireworks: rockets from the bottom, radial bursts, gravity, twinkle. */
function fireworks( success: SuccessScreen ): () => void {
	const { ctx, stop } = makeCanvas();

	// No 2D context — a locked-down or ancient browser. The dead canvas comes
	// straight back off the page and the screen simply stays still.
	if ( ! ctx ) {
		stop();

		return () => {};
	}

	const rockets: Rocket[] = [];
	const sparks: Spark[] = [];
	const total = Math.round( 6 * scale( success ) ) + 2;
	let launched = 0;

	const launch = () => {
		rockets.push( {
			x: window.innerWidth * ( 0.15 + 0.7 * Math.random() ),
			y: window.innerHeight,
			vy: -( 9 + Math.random() * 4 ),
			targetY: window.innerHeight * ( 0.15 + 0.3 * Math.random() ),
			hue: Math.floor( Math.random() * 360 ),
		} );
		launched++;
	};

	launch();

	const launcher = window.setInterval( () => {
		if ( launched >= total ) {
			window.clearInterval( launcher );

			return;
		}

		launch();
	}, 3500 / total );

	const explode = ( rocket: Rocket ) => {
		const count = Math.round( 70 * scale( success ) );

		for ( let i = 0; i < count; i++ ) {
			const angle = ( Math.PI * 2 * i ) / count + Math.random() * 0.1;
			const speed = 2 + Math.random() * 4.5;

			sparks.push( {
				x: rocket.x,
				y: rocket.y,
				vx: Math.cos( angle ) * speed,
				vy: Math.sin( angle ) * speed,
				life: 1,
				decay: 0.012 + Math.random() * 0.014,
				hue: rocket.hue + Math.floor( Math.random() * 40 ) - 20,
			} );
		}
	};

	let frame = 0;
	const started = performance.now();

	const tick = ( now: number ) => {
		ctx.clearRect( 0, 0, window.innerWidth, window.innerHeight );

		for ( let i = rockets.length - 1; i >= 0; i-- ) {
			const rocket = rockets[ i ];
			rocket.y += rocket.vy;
			rocket.vy += 0.08;

			ctx.fillStyle = `hsl(${ rocket.hue } 90% 65%)`;
			ctx.fillRect( rocket.x - 1.5, rocket.y, 3, 10 );

			if ( rocket.y <= rocket.targetY || rocket.vy >= -1 ) {
				explode( rocket );
				rockets.splice( i, 1 );
			}
		}

		for ( let i = sparks.length - 1; i >= 0; i-- ) {
			const spark = sparks[ i ];
			spark.x += spark.vx;
			spark.y += spark.vy;
			spark.vy += 0.045;
			spark.vx *= 0.985;
			spark.vy *= 0.985;
			spark.life -= spark.decay;

			if ( spark.life <= 0 ) {
				sparks.splice( i, 1 );

				continue;
			}

			// The twinkle: brightness flickers as the spark dies.
			const flicker = spark.life * ( 0.7 + 0.3 * Math.random() );
			ctx.globalAlpha = Math.max( 0, flicker );
			ctx.fillStyle = `hsl(${ spark.hue } 95% ${ 55 + 25 * spark.life }%)`;
			ctx.beginPath();
			ctx.arc( spark.x, spark.y, 1.1 + 1.6 * spark.life, 0, Math.PI * 2 );
			ctx.fill();
		}

		ctx.globalAlpha = 1;

		const done = launched >= total && rockets.length === 0 && sparks.length === 0;

		if ( ! done && now - started < 9000 ) {
			frame = window.requestAnimationFrame( tick );
		} else {
			stop();
		}
	};

	frame = window.requestAnimationFrame( tick );

	return () => {
		window.clearInterval( launcher );
		window.cancelAnimationFrame( frame );
		stop();
	};
}

/** Sparkles: the chosen emoji floats up around the message. */
function sparkles( root: HTMLElement, success: SuccessScreen ): () => void {
	const glyph = success.icon || SUCCESS_STYLE_ICONS.sparkles;
	const count = Math.round( 22 * scale( success ) );
	const spawned: HTMLElement[] = [];
	let made = 0;

	const spawn = () => {
		const spark = document.createElement( 'span' );
		spark.className = 'atf-success__spark';
		spark.setAttribute( 'aria-hidden', 'true' );
		spark.textContent = glyph;
		spark.style.insetInlineStart = `${ 4 + Math.random() * 92 }%`;
		spark.style.animationDuration = `${ 2.6 + Math.random() * 1.8 }s`;
		spark.style.animationDelay = `${ Math.random() * 0.3 }s`;
		spark.style.fontSize = `${ 14 + Math.random() * 14 }px`;

		spark.addEventListener( 'animationend', () => spark.remove() );

		root.append( spark );
		spawned.push( spark );
		made++;
	};

	spawn();

	const spawner = window.setInterval( () => {
		if ( made >= count ) {
			window.clearInterval( spawner );

			return;
		}

		spawn();
	}, 2800 / count );

	return () => {
		window.clearInterval( spawner );
		spawned.forEach( ( spark ) => spark.remove() );
	};
}

/** Typewriter: the message types itself, then hands back the real markup. */
function typewriter( root: HTMLElement ): () => void {
	const body = root.querySelector< HTMLElement >( '.atf-success__message' );

	if ( ! body ) {
		return () => {};
	}

	const html = body.innerHTML;
	const text = body.textContent ?? '';

	if ( ! text ) {
		return () => {};
	}

	body.textContent = '';
	body.classList.add( 'is-typing' );

	// The whole message lands inside four seconds however long it is; a
	// per-character pace would make a paragraph feel like dictation.
	const step = Math.min( 45, 3800 / text.length );
	let at = 0;

	const typer = window.setInterval( () => {
		at++;
		body.textContent = text.slice( 0, at );

		if ( at >= text.length ) {
			window.clearInterval( typer );
			body.classList.remove( 'is-typing' );
			// The typed plain text is swapped for the author's real markup, so
			// links and formatting survive the effect.
			body.innerHTML = html;
		}
	}, step );

	return () => {
		window.clearInterval( typer );
		body.classList.remove( 'is-typing' );
		body.innerHTML = html;
	};
}

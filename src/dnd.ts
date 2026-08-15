/**
 * One drag model, two hosts.
 *
 * Inside OpenStation the builder uses `wp.os.dragManager` — the shell's own
 * pointer-event pipeline, the same one the wallpaper's file tiles ride. That is
 * the whole reason the builder is a native window: a field lifted with the
 * shell's manager can be dropped on anything else on the desktop that registered
 * a drop target, an image dragged out of WP Explorer can be dropped onto an
 * image-choice field, and other plugins can accept `allterrain-forms/entry`
 * without knowing anything about this plugin.
 *
 * Outside the shell — the plain wp-admin page — there is no manager, and this
 * file provides one with the same interface. Not to reimplement the shell, but
 * so the builder has exactly one drag code path. A builder with two drag
 * implementations is a builder where the fallback is broken and nobody notices,
 * because the people who would notice are all running the shell.
 *
 * Why pointer events rather than HTML5 drag-and-drop, in both: HTML5 drag has no
 * programmatic cancel (Escape, alt-tab and system modals all strand the state),
 * and `setPointerCapture` anywhere in the ancestry silently stops `dragstart`
 * from firing at all.
 */

import type { DragManagerApi, DragPayload, DragSession, DragStartOpts, DropTarget, ShellApi } from './types';

/** How far the pointer must travel before a press becomes a drag. */
const DRAG_THRESHOLD_PX = 4;

/** How long after a drop a synthesized click is still suspect. */
const CLICK_GUARD_MS = 500;

/**
 * A drag manager for pages with no shell.
 *
 * Deliberately smaller than the shell's: no cross-iframe bridge, no recovery
 * pass for orphaned ghosts, no diagnostics. What it does implement exactly is
 * the accept-vs-reject *claimant* rule — a target whose `accept()` returns false
 * still swallows the drop rather than letting it fall through to whatever is
 * underneath. Without that, dropping a field on a section that refuses it lands
 * it on the canvas behind, which is worse than nothing happening.
 */
class FallbackDragManager implements DragManagerApi {
	private targets: DropTarget[] = [];
	private active: DragSession | null = null;
	private lastEndMs = 0;

	public start( opts: DragStartOpts ): DragSession | null {
		if ( this.active || opts.origin.button !== 0 ) {
			return null;
		}

		const { payload, origin } = opts;
		const startX = origin.clientX;
		const startY = origin.clientY;

		let lifted = false;
		let finished = false;
		let ghost: HTMLElement | null = null;
		let hovered: DropTarget | null = null;
		let offsetX = 0;
		let offsetY = 0;

		const cleanup = () => {
			document.removeEventListener( 'pointermove', onMove );
			document.removeEventListener( 'pointerup', onUp );
			document.removeEventListener( 'pointercancel', onCancel );
			document.removeEventListener( 'keydown', onKey );
			window.removeEventListener( 'blur', onCancel );
			ghost?.remove();
			ghost = null;
			payload.source.classList.remove( 'atf-is-dragging' );
			hovered?.onLeave?.( session );
			hovered = null;
			this.active = null;
			this.lastEndMs = Date.now();
		};

		const session: DragSession = {
			payload,
			isFinished: () => finished,
			cancel: ( reason = 'caller' ) => {
				if ( finished ) {
					return;
				}

				finished = true;
				cleanup();
				opts.onCancel?.( reason );
			},
		};

		const lift = ( event: PointerEvent ) => {
			lifted = true;
			payload.source.classList.add( 'atf-is-dragging' );

			const rect = payload.source.getBoundingClientRect();

			offsetX = payload.ghost?.offsetX ?? startX - rect.left;
			offsetY = payload.ghost?.offsetY ?? startY - rect.top;

			ghost = payload.ghost?.element ?? ( payload.source.cloneNode( true ) as HTMLElement );
			ghost.classList.add( 'atf-drag-ghost' );
			ghost.style.width = `${ rect.width }px`;
			document.body.appendChild( ghost );

			position( event );
		};

		const position = ( event: PointerEvent ) => {
			if ( ghost ) {
				ghost.style.transform = `translate3d(${ event.clientX - offsetX }px, ${ event.clientY - offsetY }px, 0)`;
			}
		};

		const onMove = ( event: PointerEvent ) => {
			if ( finished ) {
				return;
			}

			if ( ! lifted ) {
				if ( Math.hypot( event.clientX - startX, event.clientY - startY ) < DRAG_THRESHOLD_PX ) {
					return;
				}

				lift( event );
			}

			position( event );

			const next = this.hitTest( event.clientX, event.clientY );

			if ( next !== hovered ) {
				hovered?.onLeave?.( session );
				hovered = next;
				hovered?.onEnter?.( session );
			}
		};

		const onUp = ( event: PointerEvent ) => {
			if ( finished ) {
				return;
			}

			// Never travelled far enough to be a drag. The source's click
			// handler lives here rather than on the element, so a press that
			// becomes a drag doesn't also fire it.
			if ( ! lifted ) {
				finished = true;
				cleanup();
				opts.onClickOnly?.();

				return;
			}

			const target = hovered;

			finished = true;
			cleanup();

			if ( target && target.accept( payload ) ) {
				opts.onCommit?.( target );
				void target.onDrop( session, { clientX: event.clientX, clientY: event.clientY } );

				return;
			}

			opts.onCancel?.( target ? 'rejected' : 'no-target' );
		};

		const onCancel = () => session.cancel( 'pointercancel' );

		const onKey = ( event: KeyboardEvent ) => {
			if ( event.key === 'Escape' ) {
				session.cancel( 'escape' );
			}
		};

		document.addEventListener( 'pointermove', onMove );
		document.addEventListener( 'pointerup', onUp );
		document.addEventListener( 'pointercancel', onCancel );
		document.addEventListener( 'keydown', onKey );
		window.addEventListener( 'blur', onCancel );

		this.active = session;

		return session;
	}

	public registerDropTarget( target: DropTarget ): () => void {
		this.targets = this.targets.filter( ( candidate ) => candidate.id !== target.id );
		this.targets.push( target );

		return () => {
			this.targets = this.targets.filter( ( candidate ) => candidate.id !== target.id );
		};
	}

	public isDragging(): boolean {
		return this.active !== null;
	}

	public recentlyEndedDrag( withinMs = CLICK_GUARD_MS ): boolean {
		return Date.now() - this.lastEndMs < withinMs;
	}

	/**
	 * The registered target the cursor is most specifically over.
	 *
	 * Depth first, so a target nested inside another wins — that is what makes
	 * dropping on a field mean something more specific than dropping on the
	 * canvas that holds it.
	 *
	 * Ties go to whichever element comes *later* in document order, which for
	 * overlapping siblings is the one painted on top and therefore the one the
	 * user believes they are aiming at. Without the tie-break, two overlapping
	 * siblings resolve by registration order instead, and a small target sitting
	 * on top of a large one never receives a drop at all — including when its
	 * job was to refuse one.
	 */
	private hitTest( x: number, y: number ): DropTarget | null {
		let best: DropTarget | null = null;
		let bestDepth = -1;

		for ( const target of this.targets ) {
			const rect = target.element.getBoundingClientRect();

			if ( x < rect.left || x > rect.right || y < rect.top || y > rect.bottom ) {
				continue;
			}

			const depth = depthOf( target.element );

			if ( depth > bestDepth ) {
				best = target;
				bestDepth = depth;
				continue;
			}

			if ( depth === bestDepth && best && follows( target.element, best.element ) ) {
				best = target;
			}
		}

		return best;
	}
}

/** How many elements sit between this one and the document root. */
function depthOf( element: HTMLElement ): number {
	let depth = 0;
	let node: HTMLElement | null = element;

	while ( node ) {
		depth++;
		node = node.parentElement;
	}

	return depth;
}

/** Whether `a` comes after `b` in document order. */
function follows( a: Node, b: Node ): boolean {
	return ( b.compareDocumentPosition( a ) & Node.DOCUMENT_POSITION_FOLLOWING ) !== 0;
}

/** The OpenStation API, if there is one on this page. */
export function getShell(): ShellApi | null {
	const wp = ( window as unknown as { wp?: { os?: ShellApi } } ).wp;

	return wp?.os ?? null;
}

/** Whether the desktop shell is mounted and active. */
export function shellIsActive(): boolean {
	const shell = getShell();

	return Boolean( shell?.isActive?.() );
}

let fallback: FallbackDragManager | null = null;

/**
 * The drag manager for this page — the shell's when there is one.
 *
 * The fallback is created once and reused, so drop targets registered by two
 * different mounts of the builder still hit-test against each other.
 */
export function getDragManager(): DragManagerApi {
	const shell = getShell();

	if ( shell?.dragManager ) {
		return shell.dragManager;
	}

	if ( ! fallback ) {
		fallback = new FallbackDragManager();
	}

	return fallback;
}

/**
 * Dims the element a drag was lifted from, whichever manager is driving.
 *
 * The fallback sets the class itself, inside `start()`. The shell's manager
 * cannot — it knows nothing about this plugin's CSS — so the class has to come
 * from its lifecycle events instead. Without this the source sits at full
 * opacity while its ghost follows the cursor, and the builder looks for all the
 * world like the drag did nothing.
 *
 * `os.drag.start` fires at lift rather than at pointerdown, which is exactly
 * right: a click that never became a drag must not flicker the source.
 *
 * Returns a teardown. Safe to call when there is no shell — the listeners just
 * never fire.
 */
export function watchShellDragVisuals( payloadTypes: string[] ): () => void {
	const sourceOf = ( event: Event ): HTMLElement | null => {
		const payload = ( event as CustomEvent< { payload?: DragPayload } > ).detail?.payload;

		return payload && payloadTypes.includes( payload.type ) ? payload.source : null;
	};

	const onStart = ( event: Event ) => sourceOf( event )?.classList.add( 'atf-is-dragging' );
	const onEnd = ( event: Event ) => sourceOf( event )?.classList.remove( 'atf-is-dragging' );

	document.addEventListener( 'os.drag.start', onStart );
	document.addEventListener( 'os.drag.end', onEnd );

	return () => {
		document.removeEventListener( 'os.drag.start', onStart );
		document.removeEventListener( 'os.drag.end', onEnd );
	};
}

/**
 * Builds a drag payload.
 *
 * `data` carries the whole object rather than just an id, so a drop target in
 * another plugin can render something meaningful the instant it enters, without
 * a REST round trip mid-drag.
 *
 * The ghost offsets are measured from the element the user actually grabbed, so
 * the ghost stays under the same point of it. Handing the shell `0, 0` instead
 * would snap the element's corner to the cursor at lift time, which reads as the
 * thing jumping out from under the pointer.
 */
export function buildPayload(
	type: string,
	source: HTMLElement,
	data: Record< string, unknown >,
	origin: PointerEvent,
	ghost?: HTMLElement
): DragPayload {
	const rect = source.getBoundingClientRect();

	// The ghost is sized here rather than in CSS. A `position: fixed` element
	// with no width resolves against the containing block — the viewport — so a
	// ghost that looks fine in the fallback manager (which sets a width itself)
	// stretches the full width of the screen under the shell's manager, which
	// does not. Pinning it to the element it was lifted from is the only size
	// that is right under both.
	if ( ghost ) {
		ghost.style.width = `${ Math.round( rect.width ) }px`;
		ghost.style.maxWidth = `${ Math.round( rect.width ) }px`;
		ghost.style.boxSizing = 'border-box';
	}

	return {
		type,
		source,
		data,
		ghost: {
			element: ghost,
			offsetX: origin.clientX - rect.left,
			offsetY: origin.clientY - rect.top,
			hint: {
				neutral: '',
				accept: '',
				// Only the reject case earns a chip. "Drop here" over a canvas
				// the field is visibly hovering says nothing the drop indicator
				// hasn't already said; "can't drop here" is information.
				reject: '',
				hidden: true,
			},
		},
	};
}

/**
 * Where a drop lands in a vertical list.
 *
 * Returns the index the dragged thing should take. Measured against each child's
 * midpoint rather than its edges, so the insertion point flips exactly when the
 * pointer passes the middle — which is the behaviour that reads as "it will go
 * here" rather than as a lag.
 */
export function insertionIndex( container: HTMLElement, selector: string, clientY: number, ignore?: HTMLElement ): number {
	const children = Array.from( container.querySelectorAll< HTMLElement >( selector ) ).filter(
		( child ) => child !== ignore && ! child.classList.contains( 'atf-drag-ghost' )
	);

	for ( let index = 0; index < children.length; index++ ) {
		const rect = children[ index ].getBoundingClientRect();

		if ( clientY < rect.top + rect.height / 2 ) {
			return index;
		}
	}

	return children.length;
}

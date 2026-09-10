/**
 * Opens a dropdown switcher on the side of it that has room.
 *
 * The stylesheet takes the panel out of flow and opens it downward, which is
 * right wherever there is space below. A switcher near the bottom of the page —
 * a footer being only the most common case — has none: the panel hangs past the
 * end of the document, the scrollable area grows, and the page visibly bounces.
 * There is no way to ask CSS how much room an element has, so the measurement
 * happens here and the answer is written back as data attributes the stylesheet
 * reads.
 *
 * The summary's click is taken over rather than watched. A `details` element
 * fires `toggle` asynchronously, so a listener on it runs after the browser has
 * already painted the panel in the wrong direction — one frame down, then up,
 * which is the jump itself. Opening it here keeps the decision and the opening
 * in a single task, so the first paint is already correct.
 *
 * Nothing here is required for the switcher to work: where this does not run,
 * every panel opens downward, exactly as it did before.
 */
( function () {
	'use strict';

	const SELECTOR = '.localepress-switcher__dropdown';

	/**
	 * Room to leave between the panel and the edge of the viewport, in pixels.
	 *
	 * A panel ending flush against the edge reads as cut off even when all of it
	 * is on screen, so a side counts as fitting only with this much to spare.
	 *
	 * @type {number}
	 */
	const EDGE = 8;

	const open = new Set();
	let frame = 0;

	/**
	 * Points one open dropdown at the side of the switcher that can hold it.
	 *
	 * Every measurement is taken against the summary and the panel's own size,
	 * never against where the panel currently sits. A rule that read the panel's
	 * position would change that position and then disagree with itself on the
	 * next scroll, flipping the panel back and forth.
	 *
	 * @param {HTMLDetailsElement} details Open dropdown.
	 * @return {void}
	 */
	const place = function ( details ) {
		const list = details.querySelector( '.localepress-switcher__list' );

		if ( ! list ) {
			return;
		}

		// Measured against the summary, not the details element: the panel hangs
		// off the summary's edge, and an open details box already contains the
		// panel, so its own edges would answer the wrong question.
		const anchor = ( details.querySelector( 'summary' ) || details ).getBoundingClientRect();
		const below = window.innerHeight - anchor.bottom;
		const above = anchor.top;

		// Downward stays the default: it flips only when the panel does not fit
		// below and above is genuinely roomier, so a switcher with space on both
		// sides keeps opening the way a reader expects. Where neither side fits,
		// the roomier one still shows more of the list than the other would.
		details.dataset.localepressDrop =
			list.offsetHeight + EDGE > below && above > below ? 'up' : 'down';

		// The same question sideways. A panel wider than the room left of the
		// viewport edge would push the document wider and scroll the page across,
		// which is the same bounce in the other axis.
		const rtl = 'rtl' === window.getComputedStyle( list ).direction;
		const width = list.offsetWidth;
		const overflows = rtl
			? anchor.right - width < EDGE
			: anchor.left + width > window.innerWidth - EDGE;

		details.dataset.localepressAlign = overflows ? 'end' : 'start';
	};

	/**
	 * Opens one dropdown with its direction already decided.
	 *
	 * @param {HTMLDetailsElement} details Closed dropdown.
	 * @return {void}
	 */
	const show = function ( details ) {
		// Reading a layout property below flushes the pending style change, so the
		// panel is measured in its open state without a frame being painted first.
		details.open = true;
		place( details );
		open.add( details );
	};

	/**
	 * Closes one dropdown and forgets where it was.
	 *
	 * @param {HTMLDetailsElement} details Open dropdown.
	 * @return {void}
	 */
	const hide = function ( details ) {
		details.open = false;
		open.delete( details );

		// Cleared rather than left at its last value, so a panel reopened
		// somewhere else starts from the direction the stylesheet describes.
		delete details.dataset.localepressDrop;
		delete details.dataset.localepressAlign;
	};

	const placeOpen = function () {
		frame = 0;
		open.forEach( place );
	};

	// Scrolling changes how much room a switcher has without changing anything
	// about the switcher, so an open panel is measured again on the next frame
	// rather than on every event.
	const schedule = function () {
		if ( ! frame && open.size ) {
			frame = window.requestAnimationFrame( placeOpen );
		}
	};

	document.addEventListener( 'click', function ( event ) {
		if ( event.defaultPrevented || 0 !== event.button ) {
			return;
		}

		const summary = event.target.closest ? event.target.closest( SELECTOR + ' > summary' ) : null;

		if ( ! summary ) {
			return;
		}

		const details = summary.parentElement;

		event.preventDefault();

		if ( details.open ) {
			hide( details );
		} else {
			show( details );
		}
	} );

	// A dropdown can also be opened by something other than its summary — a
	// script, or a browser's find-in-page. `toggle` is not a bubbling event, so
	// the listener catches it on the way down.
	document.addEventListener(
		'toggle',
		function ( event ) {
			const details = event.target;

			if ( ! details || ! details.matches || ! details.matches( SELECTOR ) ) {
				return;
			}

			if ( details.open ) {
				open.add( details );
				place( details );

				return;
			}

			open.delete( details );
			delete details.dataset.localepressDrop;
			delete details.dataset.localepressAlign;
		},
		true
	);

	window.addEventListener( 'scroll', schedule, { passive: true } );
	window.addEventListener( 'resize', schedule, { passive: true } );
}() );

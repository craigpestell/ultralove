( function () {
	var DRAG_THRESHOLD = 40;

	function initBanner( el ) {
		var track = el.querySelector( '.ub-banner-track' );
		var slides = track.querySelectorAll( '.ub-banner-slide' );
		// Looped banners have clones of the last N slides prepended and of the first N appended
		// (see PHP; N = data-clones), so the real slide count is 2N fewer than the rendered slide
		// count, and the real first slide sits at track position N.
		var offset = parseInt( el.getAttribute( 'data-clones' ), 10 ) || 0;
		var realCount = slides.length - 2 * offset;
		var current = 0;

		if ( realCount < 2 ) {
			return;
		}

		var timer = null;
		var autoplayDelay = parseInt( el.getAttribute( 'data-autoplay' ), 10 ) || 0;

		// Slides shown at once depends on screen width (CSS sets --ub-per-view), so read it live.
		function perView() {
			return parseInt( window.getComputedStyle( el ).getPropertyValue( '--ub-per-view' ), 10 ) || 1;
		}

		// With no more slides than fit on screen there is nothing to slide to.
		function canSlide() {
			return realCount > perView();
		}

		// A track position's translate, as a percent of the track (= banner) width.
		function percentFor( trackIndex ) {
			return -trackIndex * 100 / perView();
		}

		function setPosition( trackIndex, animate ) {
			track.style.transition = animate ? '' : 'none';
			track.style.transform = 'translateX(' + percentFor( trackIndex ) + '%)';
			if ( ! animate ) {
				// Force layout before re-enabling transitions, or the browser can animate from
				// the old position instead of snapping straight to this one.
				void track.offsetHeight;
				track.style.transition = '';
			}
		}

		function goTo( index ) {
			var wrapped = index < 0 || index >= realCount;
			var trackIndex;

			if ( index < 0 ) {
				current = realCount - 1;
				trackIndex = offset - 1;
			} else if ( index >= realCount ) {
				current = 0;
				trackIndex = realCount + offset;
			} else {
				current = index;
				trackIndex = index + offset;
			}

			setPosition( trackIndex, true );

			if ( wrapped ) {
				var settled = false;
				track.addEventListener( 'transitionend', function handler() {
					if ( settled ) {
						return;
					}
					settled = true;
					track.removeEventListener( 'transitionend', handler );
					setPosition( current + offset, false );
				} );
			}
		}

		function next() {
			if ( canSlide() ) {
				goTo( current + 1 );
			}
		}

		function prev() {
			if ( canSlide() ) {
				goTo( current - 1 );
			}
		}

		function startAutoplay() {
			if ( autoplayDelay > 0 && ! timer ) {
				timer = window.setInterval( next, autoplayDelay );
			}
		}

		function stopAutoplay() {
			if ( timer ) {
				window.clearInterval( timer );
				timer = null;
			}
		}

		el.addEventListener( 'mouseenter', stopAutoplay );
		el.addEventListener( 'mouseleave', startAutoplay );

		// Pointer Events cover mouse click-and-drag, touch swipe, and pen input through one
		// code path (matching the RevSlider carousel's original behavior, which supported both).
		var startX = null;
		var dragged = false;
		var elWidth = 0;
		var captured = false;

		el.addEventListener( 'pointerdown', function ( e ) {
			if ( ! canSlide() ) {
				return;
			}
			startX = e.clientX;
			dragged = false;
			elWidth = el.getBoundingClientRect().width;
			stopAutoplay();
			el.classList.add( 'is-dragging' );
			track.style.transition = 'none';
			captured = false;
		} );

		// Dragging the slide image would otherwise start the browser's native "ghost image"
		// drag-and-drop instead of - or fighting with - the custom carousel drag.
		el.addEventListener( 'dragstart', function ( e ) {
			e.preventDefault();
		} );

		el.addEventListener( 'pointermove', function ( e ) {
			if ( startX === null ) {
				return;
			}
			var delta = e.clientX - startX;
			if ( Math.abs( delta ) > 5 ) {
				dragged = true;
				// Capture only once it's really a drag: capturing on pointerdown retargets the click to
				// this element, so a plain click would never reach (or follow) the slide's link. Capture
				// keeps move/up events coming even if the pointer outruns the element mid-drag.
				if ( ! captured && el.setPointerCapture ) {
					el.setPointerCapture( e.pointerId );
					captured = true;
				}
			}
			// Attaches the slide to the pointer: the track follows the drag 1:1 in real time,
			// on top of wherever the current slide already sits.
			var basePercent = percentFor( current + offset );
			var deltaPercent = elWidth ? ( delta / elWidth ) * 100 : 0;
			track.style.transform = 'translateX(' + ( basePercent + deltaPercent ) + '%)';
		} );

		function endDrag( e ) {
			if ( startX === null ) {
				return;
			}
			var delta = e.clientX - startX;
			startX = null;
			el.classList.remove( 'is-dragging' );

			if ( delta <= -DRAG_THRESHOLD ) {
				next();
			} else if ( delta >= DRAG_THRESHOLD ) {
				prev();
			} else {
				setPosition( current + offset, true );
			}

			startAutoplay();
		}

		el.addEventListener( 'pointerup', endDrag );
		el.addEventListener( 'pointercancel', function () {
			if ( startX === null ) {
				return;
			}
			startX = null;
			dragged = false;
			el.classList.remove( 'is-dragging' );
			setPosition( current + offset, true );
			startAutoplay();
		} );

		// A click-and-drag (or swipe) shouldn't also follow the active slide's link.
		el.addEventListener( 'click', function ( e ) {
			if ( dragged ) {
				e.preventDefault();
				dragged = false;
			}
		} );

		// A resize can change how many slides are shown, so re-seat the track (unanimated).
		window.addEventListener( 'resize', function () {
			setPosition( current + offset, false );
		} );

		startAutoplay();
	}

	function init() {
		document.querySelectorAll( '.ub-banner' ).forEach( initBanner );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();

/**
 * Front-end inspector: outlines the part of the page under the pointer and
 * says what it is and where it is edited, from the data-vwf-src and
 * data-vwf-edit marks Annotator put on the page. Click keeps the label
 * open; Esc leaves. Plain script, no build step.
 */
( function () {
	'use strict';

	var config = window.vwfInspector || {};
	var targets = config.targets || {};
	var strings = config.strings || {};
	var SEPARATOR = '>';

	var outline = document.createElement( 'div' );
	var tip = document.createElement( 'div' );
	var banner = document.createElement( 'div' );
	var current = null;
	var pinned = false;

	/**
	 * Fills %s, %d and %1$s style placeholders.
	 *
	 * @param {string} text
	 * @return {string}
	 */
	function format( text ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var next = 0;

		return String( text || '' ).replace( /%(\d+\$)?[sd]/g, function ( match, position ) {
			return String( position ? args[ parseInt( position, 10 ) - 1 ] : args[ next++ ] );
		} );
	}

	/**
	 * @param {string} id
	 * @return {string}
	 */
	function labelOf( id ) {
		return targets[ id ] && targets[ id ].label ? targets[ id ].label : id;
	}

	/**
	 * @param {string} tag
	 * @param {string} className
	 * @param {string} text
	 * @return {HTMLElement}
	 */
	function el( tag, className, text ) {
		var node = document.createElement( tag );

		node.className = className;

		if ( text ) {
			node.textContent = text;
		}

		return node;
	}

	/**
	 * Fills the label for one marked element: what it is, what it sits in,
	 * whether the client can change it, and the Edit link.
	 *
	 * @param {Element} node
	 */
	function fill( node ) {
		var chain = ( node.getAttribute( 'data-vwf-src' ) || '' ).split( SEPARATOR );
		var id = chain[ chain.length - 1 ];
		var target = targets[ id ];
		var edit = node.getAttribute( 'data-vwf-edit' );

		tip.textContent = '';
		tip.appendChild( el( 'strong', 'vwf-inspect-label', labelOf( id ) ) );

		if ( chain.length > 1 ) {
			tip.appendChild( el( 'span', 'vwf-inspect-path', chain.map( labelOf ).join( ' › ' ) ) );
		}

		if ( target && target.client_can ) {
			tip.appendChild( el( 'span', 'vwf-inspect-client', format(
				strings.clientCan,
				strings[ target.client_can ] || target.client_can,
				target.client_can_why
			) ) );
		}

		if ( edit && /^https?:\/\//.test( edit ) ) {
			var link = el( 'a', 'vwf-inspect-edit', strings.edit || 'Edit' );

			link.href = edit;
			tip.appendChild( link );
		} else {
			tip.appendChild( el( 'span', 'vwf-inspect-none', strings.noEdit ) );
		}
	}

	/**
	 * Puts the outline over the element and the label beside it.
	 */
	function place() {
		if ( ! current ) {
			return;
		}

		var box = current.getBoundingClientRect();

		outline.style.top = box.top + 'px';
		outline.style.left = box.left + 'px';
		outline.style.width = box.width + 'px';
		outline.style.height = box.height + 'px';

		var tipBox = tip.getBoundingClientRect();
		var top = box.top - tipBox.height - 6;

		if ( top < 36 ) {
			top = Math.min( box.bottom + 6, window.innerHeight - tipBox.height - 4 );
		}

		tip.style.top = Math.max( 4, top ) + 'px';
		tip.style.left = Math.max( 4, Math.min( box.left, window.innerWidth - tipBox.width - 4 ) ) + 'px';
	}

	/**
	 * @param {Element|null} node
	 */
	function show( node ) {
		current = node;
		outline.hidden = ! node;
		tip.hidden = ! node;

		if ( node ) {
			fill( node );
			place();
		}
	}

	/**
	 * @param {Event} event
	 * @return {Element|null}
	 */
	function markedFrom( event ) {
		var node = event.target;

		if ( ! node || ! node.closest || tip.contains( node ) || banner.contains( node ) || node.closest( '#wpadminbar' ) ) {
			return null;
		}

		return node.closest( '[data-vwf-src]' );
	}

	function leave() {
		if ( config.exitUrl ) {
			window.location.href = config.exitUrl;
		}
	}

	outline.className = 'vwf-inspect-outline';
	tip.className = 'vwf-inspect-tip';
	tip.setAttribute( 'role', 'status' );
	banner.className = 'vwf-inspect-banner';
	banner.appendChild( el( 'span', '', strings.banner ) );

	var stop = el( 'button', 'vwf-inspect-stop', strings.stop );

	stop.type = 'button';
	stop.addEventListener( 'click', leave );
	banner.appendChild( stop );

	outline.hidden = true;
	tip.hidden = true;
	document.body.appendChild( outline );
	document.body.appendChild( tip );
	document.body.appendChild( banner );
	document.documentElement.classList.add( 'vwf-inspecting' );

	document.addEventListener( 'mouseover', function ( event ) {
		if ( pinned || tip.contains( event.target ) ) {
			return;
		}

		show( markedFrom( event ) );
	} );

	// While inspecting, a click picks a part of the page instead of following links on it.
	document.addEventListener( 'click', function ( event ) {
		if ( tip.contains( event.target ) || banner.contains( event.target ) || ( event.target.closest && event.target.closest( '#wpadminbar' ) ) ) {
			return;
		}

		var node = markedFrom( event );

		event.preventDefault();
		event.stopPropagation();
		pinned = !! node;
		show( node );
	}, true );

	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key === 'Escape' ) {
			leave();
		}
	} );

	window.addEventListener( 'scroll', place, { passive: true } );
	window.addEventListener( 'resize', place );
}() );

/**
 * Wayfinder page: search, copy the AI brief, and note writes through the
 * wayfinder/v1/notes REST route. Plain script, no build step.
 */
( function () {
	'use strict';

	var root = document.querySelector( '.vwf-manual' );

	if ( ! root ) {
		return;
	}

	var config = window.vwfManual || {};
	var strings = config.strings || {};

	/**
	 * Fills %s, %d and %1$d style placeholders.
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

	// Following a link to a card in a closed group opens the group first.
	function reveal() {
		var id;

		if ( window.location.hash.length < 2 ) {
			return;
		}

		try {
			id = decodeURIComponent( window.location.hash.slice( 1 ) );
		} catch ( error ) {
			return;
		}

		var target = document.getElementById( id );
		var group = target ? target.closest( 'details' ) : null;

		if ( group && ! group.open ) {
			group.open = true;
			target.scrollIntoView();
		}
	}

	window.addEventListener( 'hashchange', reveal );
	reveal();

	// Search: filter cards by the label, id and summary in data-vwf-search.
	var searchBox = root.querySelector( '[data-vwf-search-box]' );
	var search = document.getElementById( 'vwf-search' );
	var searchStatus = document.getElementById( 'vwf-search-status' );
	var cards = Array.prototype.slice.call( root.querySelectorAll( '[data-vwf-item]' ) );
	var groups = Array.prototype.slice.call( root.querySelectorAll( 'details.vwf-folded' ) );
	var sections = Array.prototype.slice.call( root.querySelectorAll( '[data-vwf-section]' ) );

	if ( searchBox && search ) {
		searchBox.hidden = false;

		search.addEventListener( 'input', function () {
			var query = search.value.trim().toLowerCase();
			var shown = 0;

			cards.forEach( function ( card ) {
				var match = query === '' || ( card.getAttribute( 'data-vwf-search' ) || '' ).indexOf( query ) !== -1;

				card.hidden = ! match;
				shown += match ? 1 : 0;
			} );

			// Open groups with a match while searching, and put them back after.
			groups.forEach( function ( group ) {
				if ( query === '' ) {
					if ( group.dataset.vwfWasOpen !== undefined ) {
						group.open = group.dataset.vwfWasOpen === '1';
						delete group.dataset.vwfWasOpen;
					}

					return;
				}

				if ( group.dataset.vwfWasOpen === undefined ) {
					group.dataset.vwfWasOpen = group.open ? '1' : '0';
				}

				group.open = group.querySelector( '[data-vwf-item]:not([hidden])' ) !== null;
			} );

			sections.forEach( function ( section ) {
				var empty = section.querySelector( '[data-vwf-no-match]' );

				if ( empty ) {
					empty.hidden = query === '' || section.querySelector( '[data-vwf-item]:not([hidden])' ) !== null;
				}
			} );

			searchStatus.textContent = query === '' ? '' : format( strings.matches, shown, cards.length );
		} );
	}

	// Copy AI brief.
	var copyButton = root.querySelector( '[data-vwf-copy-brief]' );
	var brief = document.getElementById( 'vwf-brief' );
	var copyStatus = document.getElementById( 'vwf-copy-status' );

	if ( copyButton && brief ) {
		copyButton.addEventListener( 'click', function () {
			// Without the clipboard API (a plain http site), show the text selected.
			function selectText() {
				var copied = false;

				brief.hidden = false;
				brief.focus();
				brief.select();

				try {
					copied = document.execCommand( 'copy' );
				} catch ( error ) {
					copied = false;
				}

				copyStatus.textContent = copied ? strings.copied : strings.copyManually;
			}

			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( brief.value ).then( function () {
					copyStatus.textContent = strings.copied;
				}, selectText );
			} else {
				selectText();
			}
		} );
	}

	// Notes.
	function request( method, path, body ) {
		return window.fetch( config.notesUrl + path, {
			method: method,
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( function ( response ) {
			return response.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				if ( ! response.ok ) {
					throw new Error( data && data.message ? data.message : String( response.status ) );
				}

				return data;
			} );
		} );
	}

	// Reload on the card (or the notes section) the change was made in.
	function reloadAt( element ) {
		var anchor = element.closest( '[data-vwf-item]' ) || element.closest( 'section[id]' );

		if ( anchor ) {
			window.location.hash = encodeURIComponent( anchor.id );
		}

		window.location.reload();
	}

	function fail( status, button, template, error ) {
		button.disabled = false;
		status.textContent = format( template, error.message );
	}

	root.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		var itemId = form.getAttribute( 'data-vwf-note-add' );
		var noteId = form.getAttribute( 'data-vwf-note-edit' );

		// The Rescan and settings forms post the normal way.
		if ( itemId === null && noteId === null ) {
			return;
		}

		event.preventDefault();

		var button = form.querySelector( '[type="submit"]' );
		var status = form.querySelector( '.vwf-status' );
		var body = form.querySelector( 'textarea' ).value;

		button.disabled = true;
		status.textContent = strings.saving;

		var saved = itemId !== null
			? request( 'POST', '', { item_id: itemId, body: body } )
			: request( 'POST', '/' + noteId, { body: body } );

		saved.then( function () {
			reloadAt( form );
		}, function ( error ) {
			fail( status, button, strings.saveFailed, error );
		} );
	} );

	root.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-vwf-action]' );
		var note = button ? button.closest( '[data-vwf-note]' ) : null;

		if ( ! note ) {
			return;
		}

		var edit = note.querySelector( '[data-vwf-action="edit"]' );
		var remove = note.querySelector( '[data-vwf-action="delete"]' );
		var form = note.querySelector( '[data-vwf-note-edit]' );
		var confirm = note.querySelector( '[data-vwf-note-delete]' );

		switch ( button.getAttribute( 'data-vwf-action' ) ) {
			case 'edit':
				confirm.hidden = true;
				form.hidden = false;
				edit.setAttribute( 'aria-expanded', 'true' );
				form.querySelector( 'textarea' ).focus();
				break;

			case 'cancel':
				form.hidden = true;
				edit.setAttribute( 'aria-expanded', 'false' );
				edit.focus();
				break;

			case 'delete':
				form.hidden = true;
				edit.setAttribute( 'aria-expanded', 'false' );
				confirm.hidden = false;
				confirm.querySelector( '[data-vwf-action="confirm-delete"]' ).focus();
				break;

			case 'keep':
				confirm.hidden = true;
				remove.focus();
				break;

			case 'confirm-delete':
				var status = confirm.querySelector( '.vwf-status' );

				button.disabled = true;
				status.textContent = strings.deleting;

				request( 'DELETE', '/' + note.getAttribute( 'data-vwf-note' ) ).then( function () {
					reloadAt( note );
				}, function ( error ) {
					fail( status, button, strings.deleteFailed, error );
				} );
				break;
		}
	} );
}() );

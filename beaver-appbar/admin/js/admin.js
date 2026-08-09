/**
 * Beaver App Bar — admin behaviour.
 *
 * Four small jobs on the items table: add a row, remove one, move one, and keep
 * each row showing the fields its type actually needs. Everything else on the
 * screen is a plain form and is left alone.
 *
 * Rows are grouped by an index in their field names, but the order that counts
 * is the order they appear in the table: PHP reads the posted fields in
 * document order, so moving a row is a matter of moving the row.
 */
( function () {
	'use strict';

	var table = document.getElementById( 'beaver-appbar-items' );
	var template = document.getElementById( 'beaver-appbar-template' );
	var add = document.getElementById( 'beaver-appbar-add' );

	if ( ! table || ! template || ! add ) {
		return;
	}

	var config = window.beaverAppBar || {};
	var i18n = config.i18n || {};
	var icons = config.icons || {};
	var max = parseInt( config.max, 10 ) || 5;
	var body = table.querySelector( 'tbody' );
	var counter = body.querySelectorAll( '.beaver-appbar-item' ).length;

	/**
	 * Shows or hides the link field for one row.
	 *
	 * @param {HTMLElement} row The row.
	 */
	function syncRow( row ) {
		var type = row.querySelector( '[data-type-select]' );

		row.classList.toggle( 'is-linkless', !! type && 'link' !== type.value );
	}

	/**
	 * Redraws the little icon swatch beside a row's picker.
	 *
	 * @param {HTMLElement} row The row.
	 */
	function syncIcon( row ) {
		var select = row.querySelector( '[data-icon-select]' );
		var swatch = row.querySelector( '[data-icon-preview]' );

		if ( ! select || ! swatch ) {
			return;
		}

		swatch.innerHTML = icons[ select.value ] || '';
	}

	/**
	 * Keeps the Add button honest about the limit.
	 */
	function syncAdd() {
		add.disabled = body.querySelectorAll( '.beaver-appbar-item' ).length >= max;
	}

	add.addEventListener( 'click', function () {
		var rows = body.querySelectorAll( '.beaver-appbar-item' );

		if ( rows.length >= max ) {
			if ( i18n.full ) {
				window.alert( i18n.full );
			}

			return;
		}

		var index = counter++;
		var clone = template.content.firstElementChild.cloneNode( true );

		// The template is built as row 0; give the copy an index of its own so
		// its fields land in their own group when the form is posted.
		clone.querySelectorAll( '[name]' ).forEach( function ( field ) {
			field.name = field.name.replace( 'items[0]', 'items[' + index + ']' );
		} );

		clone.querySelectorAll( '[id]' ).forEach( function ( field ) {
			field.id = field.id.replace( 'ba-item-0', 'ba-item-' + index );
		} );

		clone.querySelectorAll( '[for]' ).forEach( function ( label ) {
			label.htmlFor = label.htmlFor.replace( 'ba-item-0', 'ba-item-' + index );
		} );

		body.appendChild( clone );
		syncRow( clone );
		syncIcon( clone );
		syncAdd();

		var first = clone.querySelector( 'input[type="text"]' );

		if ( first ) {
			first.focus();
		}
	} );

	body.addEventListener( 'click', function ( event ) {
		var row = event.target.closest( '.beaver-appbar-item' );

		if ( ! row ) {
			return;
		}

		if ( event.target.closest( '.beaver-appbar-remove' ) ) {
			if ( i18n.remove && ! window.confirm( i18n.remove ) ) {
				return;
			}

			row.remove();
			syncAdd();

			return;
		}

		var move = event.target.closest( '[data-move]' );

		if ( ! move ) {
			return;
		}

		if ( 'up' === move.getAttribute( 'data-move' ) ) {
			if ( row.previousElementSibling ) {
				row.parentNode.insertBefore( row, row.previousElementSibling );
			}
		} else if ( row.nextElementSibling ) {
			row.parentNode.insertBefore( row.nextElementSibling, row );
		}

		move.focus();
	} );

	body.addEventListener( 'change', function ( event ) {
		var row = event.target.closest( '.beaver-appbar-item' );

		if ( ! row ) {
			return;
		}

		if ( event.target.matches( '[data-type-select]' ) ) {
			syncRow( row );
		}

		if ( event.target.matches( '[data-icon-select]' ) ) {
			syncIcon( row );
		}
	} );

	syncAdd();
}() );

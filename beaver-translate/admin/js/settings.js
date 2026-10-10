/**
 * Beaver Translate settings screen script.
 *
 * Adds and removes rows in the languages table. Kept deliberately simple:
 * no framework, just cloning a <template> and rewriting its index.
 */
( function () {
	'use strict';

	var table = document.getElementById( 'beaver-translate-languages' );
	var template = document.getElementById( 'beaver-translate-language-template' );
	var addButton = document.getElementById( 'beaver-translate-add-language' );

	if ( ! table || ! template || ! addButton ) {
		return;
	}

	var body = table.querySelector( 'tbody' );

	addButton.addEventListener( 'click', function () {
		var index = body.querySelectorAll( 'tr' ).length;
		var fragment = template.content.cloneNode( true );

		fragment.querySelectorAll( '[name]' ).forEach( function ( field ) {
			field.name = field.name.replace( '__INDEX__', String( index ) );
		} );

		body.appendChild( fragment );
	} );

	body.addEventListener( 'click', function ( event ) {
		var removeButton = event.target.closest ? event.target.closest( '[data-beaver-translate-remove-language]' ) : null;

		if ( ! removeButton ) {
			return;
		}

		event.preventDefault();

		var row = removeButton.closest( 'tr' );

		if ( row ) {
			row.remove();
		}
	} );

	/* --------------------------------------------------------------------
	 * API key: saved/masked by default, editable only after "Change".
	 * ----------------------------------------------------------------- */

	var keyChangeButton = document.querySelector( '[data-beaver-translate-key-change]' );

	if ( keyChangeButton ) {
		keyChangeButton.addEventListener( 'click', function () {
			var saved = document.querySelector( '[data-beaver-translate-key-saved]' );
			var edit = document.querySelector( '[data-beaver-translate-key-edit]' );
			var unchangedField = document.querySelector( '[data-beaver-translate-key-unchanged-field]' );

			if ( saved ) {
				saved.hidden = true;
			}

			if ( edit ) {
				edit.hidden = false;

				var input = edit.querySelector( 'input' );

				if ( input ) {
					input.focus();
				}
			}

			/*
			 * `hidden` only affects display, not form submission — the
			 * saved-key field would still be posted as api_key_unchanged=1
			 * even while out of sight, telling the server to keep the old
			 * key and silently discard whatever gets typed into the now-
			 * visible field. Disabling it is what actually removes it from
			 * the submitted form data.
			 */
			if ( unchangedField ) {
				unchangedField.disabled = true;
			}
		} );
	}
} )();

/**
 * Beaver SEO admin script.
 *
 * Two independent, small pieces: the media picker used by every image field
 * on the post editor, term editor and settings screen, and the live snippet
 * preview on the post/term editor's SEO box.
 */
( function () {
	'use strict';

	var settings = window.beaverSEO || { i18n: {} };

	/* --------------------------------------------------------------------
	 * Image picker
	 * ----------------------------------------------------------------- */

	document.addEventListener( 'click', function ( event ) {
		var selectButton = event.target.closest ? event.target.closest( '[data-beaver-seo-image-select]' ) : null;
		var removeButton = event.target.closest ? event.target.closest( '[data-beaver-seo-image-remove]' ) : null;

		if ( selectButton ) {
			event.preventDefault();
			openPicker( selectButton );
		} else if ( removeButton ) {
			event.preventDefault();
			clearImage( removeButton );
		}
	} );

	/**
	 * Finds the field group (hidden input + preview image) around a button.
	 *
	 * @param {HTMLElement} button Clicked button.
	 * @return {Object} The hidden input, the preview image and the remove button.
	 */
	function fieldGroup( button ) {
		var container = button.closest( 'p' ) || button.closest( 'td' ) || button.parentNode;

		return {
			input: container.querySelector( '[data-beaver-seo-image-id]' ),
			preview: container.querySelector( '[data-beaver-seo-image-preview]' ),
			remove: container.querySelector( '[data-beaver-seo-image-remove]' )
		};
	}

	/**
	 * Opens the WordPress media library and stores the chosen image.
	 *
	 * @param {HTMLElement} button The "Choose image" button that was clicked.
	 */
	function openPicker( button ) {
		if ( typeof wp === 'undefined' || ! wp.media ) {
			return;
		}

		var group = fieldGroup( button );

		var frame = wp.media( {
			title: settings.i18n.chooseImage || 'Choose an image',
			button: { text: settings.i18n.useImage || 'Use this image' },
			multiple: false,
			library: { type: 'image' }
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();

			if ( group.input ) {
				group.input.value = attachment.id;
			}

			if ( group.preview ) {
				group.preview.src = ( attachment.sizes && attachment.sizes.thumbnail ) ? attachment.sizes.thumbnail.url : attachment.url;
				group.preview.style.display = '';
			}

			if ( group.remove ) {
				group.remove.style.display = '';
			}
		} );

		frame.open();
	}

	/**
	 * Clears a chosen image.
	 *
	 * @param {HTMLElement} button The "Remove" button that was clicked.
	 */
	function clearImage( button ) {
		var group = fieldGroup( button );

		if ( group.input ) {
			group.input.value = '0';
		}

		if ( group.preview ) {
			group.preview.style.display = 'none';
			group.preview.src = '';
		}

		button.style.display = 'none';
	}

	/* --------------------------------------------------------------------
	 * Live snippet preview
	 * ----------------------------------------------------------------- */

	document.querySelectorAll( '.beaver-seo-fields' ).forEach( function ( fields ) {
		var titleInput = fields.querySelector( '[data-beaver-seo-title]' );
		var descriptionInput = fields.querySelector( '[data-beaver-seo-description]' );
		var previewTitle = fields.querySelector( '[data-beaver-seo-preview-title]' );
		var previewDescription = fields.querySelector( '[data-beaver-seo-preview-description]' );

		if ( titleInput && previewTitle ) {
			titleInput.addEventListener( 'input', function () {
				previewTitle.textContent = titleInput.value || settings.i18n.noTitle || '';
			} );
		}

		if ( descriptionInput && previewDescription ) {
			descriptionInput.addEventListener( 'input', function () {
				previewDescription.textContent = descriptionInput.value || settings.i18n.noDescription || '';
			} );
		}
	} );
} )();

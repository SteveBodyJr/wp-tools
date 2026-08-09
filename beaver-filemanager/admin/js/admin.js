/**
 * Beaver FileManager admin interface.
 *
 * Nothing here writes a file name into innerHTML. Every label goes through
 * textContent, because a file called `<img onerror=…>` is a perfectly legal
 * file name and this screen runs with administrator privileges.
 */

( function () {
	'use strict';

	var cfg  = window.beaverFM || {};
	var i18n = cfg.i18n || {};

	var ICONS = {
		folder: 'dashicons-portfolio',
		image: 'dashicons-format-image',
		video: 'dashicons-video-alt3',
		audio: 'dashicons-format-audio',
		pdf: 'dashicons-media-document',
		archive: 'dashicons-media-archive',
		code: 'dashicons-editor-code',
		text: 'dashicons-media-text',
		font: 'dashicons-editor-textcolor',
		file: 'dashicons-media-default'
	};

	var state = {
		path: '',
		items: [],
		selected: [],
		anchor: -1,
		clipboard: { mode: '', paths: [] },
		sort: { key: 'name', dir: 'asc' },
		mode: 'browse',
		search: null,
		tree: {},
		editor: { path: '', hash: '', cm: null, entry: null, dirty: false, backups: [] },
		dialog: null
	};

	/* ------------------------------------------------------------------
	 * Small helpers
	 * ------------------------------------------------------------------ */

	function $( selector, scope ) {
		return ( scope || document ).querySelector( selector );
	}

	function $$( selector, scope ) {
		return Array.prototype.slice.call( ( scope || document ).querySelectorAll( selector ) );
	}

	function make( tag, className, text ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		if ( undefined !== text && null !== text ) {
			node.textContent = String( text );
		}

		return node;
	}

	function icon( name, className ) {
		var span = make( 'span', 'dashicons ' + name + ( className ? ' ' + className : '' ) );
		span.setAttribute( 'aria-hidden', 'true' );

		return span;
	}

	function empty( node ) {
		while ( node && node.firstChild ) {
			node.removeChild( node.firstChild );
		}

		return node;
	}

	function fmt( template ) {
		var args  = Array.prototype.slice.call( arguments, 1 );
		var index = 0;

		return String( template || '' ).replace( /%(\d+\$)?[sd]/g, function ( match, pos ) {
			var value = pos ? args[ parseInt( pos, 10 ) - 1 ] : args[ index++ ];

			return undefined === value ? match : String( value );
		} );
	}

	function basename( path ) {
		var parts = String( path ).split( '/' );

		return parts[ parts.length - 1 ];
	}

	function dirname( path ) {
		var parts = String( path ).split( '/' );

		parts.pop();

		return parts.join( '/' );
	}

	function join( dir, name ) {
		return dir ? dir + '/' + name : name;
	}

	/* ------------------------------------------------------------------
	 * Transport
	 * ------------------------------------------------------------------ */

	function request( action, data ) {
		var body = new FormData();

		body.append( 'action', 'beaver_fm_' + action );
		body.append( 'nonce', cfg.nonce );

		Object.keys( data || {} ).forEach( function ( key ) {
			var value = data[ key ];

			if ( undefined === value || null === value ) {
				return;
			}

			if ( Array.isArray( value ) ) {
				body.append( key, JSON.stringify( value ) );
			} else if ( true === value || false === value ) {
				body.append( key, value ? '1' : '0' );
			} else {
				body.append( key, value );
			}
		} );

		return window.fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json().catch( function () {
				throw new Error( i18n.requestFailed );
			} );
		} ).then( function ( json ) {
			if ( ! json || ! json.success ) {
				var error = new Error( ( json && json.data && json.data.message ) || i18n.requestFailed );

				error.code   = json && json.data ? json.data.code : '';
				error.detail = json && json.data ? json.data.data : null;

				throw error;
			}

			return json.data;
		} );
	}

	function streamUrl( action, path ) {
		return cfg.ajaxUrl +
			'?action=beaver_fm_' + action +
			'&nonce=' + encodeURIComponent( cfg.nonce ) +
			'&path=' + encodeURIComponent( path );
	}

	/* ------------------------------------------------------------------
	 * Toasts
	 * ------------------------------------------------------------------ */

	function toast( message, kind ) {
		var host = $( '#beaver-fm-toasts' );

		if ( ! host ) {
			return;
		}

		var node = make( 'div', 'beaver-fm-toast' + ( kind ? ' is-' + kind : '' ), message );

		host.appendChild( node );

		window.setTimeout( function () {
			node.style.opacity = '0';
			window.setTimeout( function () {
				if ( node.parentNode ) {
					node.parentNode.removeChild( node );
				}
			}, 250 );
		}, 'error' === kind ? 7000 : 3800 );
	}

	function fail( error ) {
		toast( error && error.message ? error.message : i18n.requestFailed, 'error' );
	}

	/**
	 * Copies text, falling back to a scratch textarea where the clipboard API
	 * is unavailable — an admin served over plain http is not a secure context.
	 */
	function copyText( text, message ) {
		function fallback() {
			var scratch = make( 'textarea' );

			scratch.value = text;
			scratch.setAttribute( 'readonly', 'readonly' );
			scratch.style.position = 'fixed';
			scratch.style.opacity = '0';

			document.body.appendChild( scratch );
			scratch.select();

			var done = false;

			try {
				done = document.execCommand( 'copy' );
			} catch ( e ) {
				done = false;
			}

			document.body.removeChild( scratch );
			toast( done ? message : i18n.copyFailed, done ? 'success' : 'error' );
		}

		if ( window.navigator && window.navigator.clipboard && window.isSecureContext ) {
			window.navigator.clipboard.writeText( text ).then( function () {
				toast( message, 'success' );
			} ).catch( fallback );

			return;
		}

		fallback();
	}

	/* ------------------------------------------------------------------
	 * Dialogs
	 * ------------------------------------------------------------------ */

	function openDialog( options ) {
		var overlay = $( '#beaver-fm-dialog' );
		var title   = $( '#beaver-fm-dialog-title' );
		var body    = $( '#beaver-fm-dialog-body' );
		var ok      = $( '[data-fm="dialog-ok"]' );

		title.textContent = options.title || '';
		empty( body );

		if ( options.message ) {
			body.appendChild( make( 'p', null, options.message ) );
		}

		if ( options.build ) {
			options.build( body );
		}

		ok.textContent = options.okLabel || 'OK';
		ok.classList.toggle( 'beaver-fm-danger', !! options.danger );

		state.dialog = options;
		overlay.hidden = false;

		var focus = body.querySelector( 'input[type="text"], input[type="number"]' );

		if ( focus ) {
			focus.focus();
			focus.select();
		} else {
			ok.focus();
		}
	}

	function closeDialog() {
		$( '#beaver-fm-dialog' ).hidden = true;
		state.dialog = null;
	}

	function submitDialog() {
		var options = state.dialog;

		if ( ! options ) {
			return;
		}

		var body = $( '#beaver-fm-dialog-body' );

		closeDialog();

		if ( options.onOk ) {
			options.onOk( body );
		}
	}

	function askText( options ) {
		var input;

		openDialog( {
			title: options.title,
			message: options.message,
			okLabel: options.okLabel,
			build: function ( body ) {
				var label = make( 'label' );

				label.appendChild( make( 'span', null, options.label || i18n.nameLabel ) );

				input = make( 'input' );
				input.type = 'text';
				input.value = options.value || '';

				label.appendChild( input );
				body.appendChild( label );

				if ( options.hint ) {
					body.appendChild( make( 'p', 'description', options.hint ) );
				}

				input.addEventListener( 'keydown', function ( event ) {
					if ( 'Enter' === event.key ) {
						event.preventDefault();
						submitDialog();
					}
				} );
			},
			onOk: function ( body ) {
				options.onOk( input.value.trim(), body );
			}
		} );
	}

	function askConfirm( options ) {
		openDialog( {
			title: options.title,
			message: options.message,
			okLabel: options.okLabel,
			danger: options.danger,
			onOk: options.onOk
		} );
	}

	/* ------------------------------------------------------------------
	 * Selection
	 * ------------------------------------------------------------------ */

	function selectedEntries() {
		return state.items.filter( function ( item ) {
			return state.selected.indexOf( item.path ) !== -1;
		} );
	}

	function setSelection( paths ) {
		state.selected = paths.slice();
		syncSelectionUI();
	}

	function syncSelectionUI() {
		$$( '.beaver-fm-table tbody tr[data-path]' ).forEach( function ( row ) {
			var chosen = state.selected.indexOf( row.getAttribute( 'data-path' ) ) !== -1;

			row.classList.toggle( 'is-selected', chosen );

			var box = row.querySelector( 'input[type="checkbox"]' );

			if ( box ) {
				box.checked = chosen;
			}
		} );

		updateToolbar();
		renderStatus();
	}

	function updateToolbar() {
		var count   = state.selected.length;
		var entries = selectedEntries();
		var archive = 1 === count && entries[ 0 ] && 'zip' === entries[ 0 ].ext;

		$$( '[data-needs]' ).forEach( function ( button ) {
			var needs = button.getAttribute( 'data-needs' );
			var ready = true;

			if ( 'selection' === needs ) {
				ready = count > 0;
			} else if ( 'one' === needs ) {
				ready = 1 === count;
			} else if ( 'clipboard' === needs ) {
				ready = state.clipboard.paths.length > 0;
			} else if ( 'archive' === needs ) {
				ready = archive;
			}

			button.disabled = ! ready;
		} );
	}

	/* ------------------------------------------------------------------
	 * Navigation
	 * ------------------------------------------------------------------ */

	function navigate( path, keepSearch ) {
		if ( ! keepSearch ) {
			state.mode   = 'browse';
			state.search = null;
		}

		state.path     = path || '';
		state.selected = [];
		state.anchor   = -1;

		var listing = $( '#beaver-fm-listing' );

		empty( listing ).appendChild( make( 'div', 'beaver-fm-empty', i18n.loading ) );

		return request( 'list', { path: state.path } ).then( function ( data ) {
			state.items = data.items || [];

			renderCrumbs( data.crumbs || [] );
			renderListing();
			renderStatus( data );
			markTreeCurrent();
			expandTreeTo( state.path );

			try {
				var url = new URL( window.location.href );

				url.searchParams.set( 'path', state.path );
				window.history.replaceState( {}, '', url.toString() );
			} catch ( e ) {
				// A browser without URL support simply keeps the old address.
			}
		} ).catch( function ( error ) {
			empty( listing ).appendChild( make( 'div', 'beaver-fm-error', error.message ) );
			fail( error );
		} );
	}

	function refresh() {
		if ( 'search' === state.mode && state.search ) {
			return runSearch( state.search.query );
		}

		return navigate( state.path );
	}

	/* ------------------------------------------------------------------
	 * Breadcrumbs
	 * ------------------------------------------------------------------ */

	function renderCrumbs( crumbs ) {
		var host = empty( $( '#beaver-fm-crumbs' ) );

		crumbs.forEach( function ( crumb, index ) {
			if ( index > 0 ) {
				host.appendChild( make( 'span', 'beaver-fm-crumbs__sep', '/' ) );
			}

			var button = make( 'button', 'beaver-fm-crumb', crumb.name );

			button.type = 'button';

			if ( index < crumbs.length - 1 ) {
				button.addEventListener( 'click', function () {
					navigate( crumb.path );
				} );
			} else {
				button.disabled = true;
			}

			host.appendChild( button );
		} );
	}

	/* ------------------------------------------------------------------
	 * Listing
	 * ------------------------------------------------------------------ */

	function sortItems( items ) {
		var key = state.sort.key;
		var dir = 'desc' === state.sort.dir ? -1 : 1;

		return items.slice().sort( function ( a, b ) {
			if ( a.dir !== b.dir ) {
				return a.dir ? -1 : 1;
			}

			var result;

			if ( 'size' === key ) {
				result = a.size - b.size;
			} else if ( 'mtime' === key ) {
				result = a.mtime - b.mtime;
			} else if ( 'kind' === key ) {
				result = String( a.ext ).localeCompare( String( b.ext ) );
			} else {
				result = String( a.name ).localeCompare( String( b.name ), undefined, { numeric: true, sensitivity: 'base' } );
			}

			return result * dir;
		} );
	}

	function headerCell( label, key, className ) {
		var th = make( 'th', className || null );

		th.appendChild( document.createTextNode( label ) );

		if ( key ) {
			if ( state.sort.key === key ) {
				th.appendChild( make( 'span', 'beaver-fm-sort', 'asc' === state.sort.dir ? ' ▲' : ' ▼' ) );
			}

			th.addEventListener( 'click', function () {
				if ( state.sort.key === key ) {
					state.sort.dir = 'asc' === state.sort.dir ? 'desc' : 'asc';
				} else {
					state.sort.key = key;
					state.sort.dir = 'asc';
				}

				renderListing();
			} );
		} else {
			th.className = ( className ? className + ' ' : '' ) + 'is-static';
		}

		return th;
	}

	function renderListing() {
		var listing = empty( $( '#beaver-fm-listing' ) );
		var items   = sortItems( state.items );

		if ( 'search' === state.mode ) {
			var head = make( 'div', 'beaver-fm-crumbs' );

			head.appendChild( make( 'strong', null, fmt( i18n.searchResults, state.search.query ) ) );
			head.appendChild( make( 'span', 'beaver-fm-path', ' · ' + fmt( i18n.scanned, state.search.scanned ) ) );

			var back = make( 'button', 'beaver-fm-crumb', '✕' );

			back.type = 'button';
			back.addEventListener( 'click', function () {
				navigate( state.path );
			} );
			head.appendChild( back );

			listing.appendChild( head );

			if ( state.search.capped ) {
				listing.appendChild( make( 'div', 'beaver-fm-error', i18n.searchCapped ) );
			}
		}

		if ( ! items.length ) {
			var blank = make( 'div', 'beaver-fm-empty' );

			blank.appendChild( icon( 'search' === state.mode ? 'dashicons-search' : 'dashicons-portfolio' ) );
			blank.appendChild( document.createTextNode( 'search' === state.mode ? i18n.noResults : i18n.empty ) );
			listing.appendChild( blank );

			updateToolbar();

			return;
		}

		var table = make( 'table', 'beaver-fm-table' );
		var thead = make( 'thead' );
		var hrow  = make( 'tr' );

		var checkAll = make( 'input' );

		checkAll.type = 'checkbox';
		checkAll.addEventListener( 'change', function () {
			setSelection( checkAll.checked ? items.map( function ( item ) {
				return item.path;
			} ) : [] );
		} );

		var checkTh = make( 'th', 'beaver-fm-col-check is-static' );

		checkTh.appendChild( checkAll );
		hrow.appendChild( checkTh );

		hrow.appendChild( headerCell( i18n.colName, 'name' ) );
		hrow.appendChild( headerCell( i18n.colSize, 'size', 'beaver-fm-col-size' ) );
		hrow.appendChild( headerCell( i18n.colPerms, null, 'beaver-fm-col-perms' ) );
		hrow.appendChild( headerCell( i18n.colModified, 'mtime', 'beaver-fm-col-modified' ) );
		hrow.appendChild( headerCell( '', null, 'beaver-fm-col-actions' ) );

		thead.appendChild( hrow );
		table.appendChild( thead );

		var tbody = make( 'tbody' );

		items.forEach( function ( item, index ) {
			tbody.appendChild( renderRow( item, index ) );

			if ( item.matches && item.matches.length ) {
				var hits = make( 'tr' );
				var cell = make( 'td' );

				cell.colSpan = 6;
				cell.className = 'beaver-fm-hit';

				item.matches.forEach( function ( match ) {
					var line = make( 'div' );

					line.appendChild( make( 'b', null, fmt( i18n.line, match.line ) ) );
					line.appendChild( document.createTextNode( match.text ) );
					cell.appendChild( line );
				} );

				hits.appendChild( cell );
				tbody.appendChild( hits );
			}
		} );

		table.appendChild( tbody );
		listing.appendChild( table );

		syncSelectionUI();
	}

	function renderRow( item, index ) {
		var row = make( 'tr' );

		row.setAttribute( 'data-path', item.path );

		if ( 'move' === state.clipboard.mode && state.clipboard.paths.indexOf( item.path ) !== -1 ) {
			row.classList.add( 'is-cut' );
		}

		/* Checkbox */
		var checkCell = make( 'td', 'beaver-fm-col-check' );
		var box       = make( 'input' );

		box.type = 'checkbox';
		box.addEventListener( 'click', function ( event ) {
			event.stopPropagation();
			toggle( item.path, index, event.shiftKey, true );
		} );
		checkCell.appendChild( box );
		row.appendChild( checkCell );

		/* Name */
		var nameCell = make( 'td' );
		var wrap     = make( 'div', 'beaver-fm-name' );
		var kindIcon = icon( ICONS[ item.kind ] || ICONS.file, 'beaver-fm-name__icon is-' + item.kind );

		wrap.appendChild( kindIcon );

		var link = make( 'button', 'beaver-fm-name__link', item.name );

		link.type = 'button';
		link.title = item.path;
		link.addEventListener( 'click', function ( event ) {
			event.stopPropagation();
			openItem( item );
		} );
		wrap.appendChild( link );

		if ( item.link ) {
			wrap.appendChild( make( 'span', 'beaver-fm-name__badge', 'link' ) );
		}

		if ( ! item.writable ) {
			wrap.appendChild( make( 'span', 'beaver-fm-name__badge', 'read-only' ) );
		}

		nameCell.appendChild( wrap );

		if ( 'search' === state.mode && item.parent ) {
			nameCell.appendChild( make( 'div', 'beaver-fm-path', item.parent ) );
		}

		row.appendChild( nameCell );

		row.appendChild( make( 'td', 'beaver-fm-col-size', item.sizeText ) );

		var permCell = make( 'td', 'beaver-fm-col-perms' );

		permCell.appendChild( make( 'code', null, item.perms ) );
		permCell.title = item.permsText;
		row.appendChild( permCell );

		row.appendChild( make( 'td', 'beaver-fm-col-modified', item.modified ) );

		/* Row actions */
		var actions = make( 'td', 'beaver-fm-col-actions' );

		if ( ! item.dir && item.editable ) {
			actions.appendChild( rowAction( 'dashicons-edit', i18n.btnEdit, function () {
				openEditor( item.path );
			} ) );
		}

		if ( item.preview ) {
			actions.appendChild( rowAction( 'dashicons-visibility', i18n.btnPreview, function () {
				openPreview( item );
			} ) );
		}

		if ( ! item.dir ) {
			actions.appendChild( rowAction( 'dashicons-download', i18n.btnDownload, function () {
				window.location.href = streamUrl( 'download', item.path );
			} ) );
		}

		actions.appendChild( rowAction( 'dashicons-info-outline', i18n.btnDetails, function () {
			openInfo( item.path );
		} ) );

		row.appendChild( actions );

		row.addEventListener( 'click', function ( event ) {
			toggle( item.path, index, event.shiftKey, event.ctrlKey || event.metaKey );
		} );

		row.addEventListener( 'dblclick', function () {
			openItem( item );
		} );

		return row;
	}

	function rowAction( iconName, label, handler ) {
		var button = make( 'button', 'beaver-fm-rowaction' );

		button.type = 'button';
		button.title = label;
		button.setAttribute( 'aria-label', label );
		button.appendChild( icon( iconName ) );
		button.addEventListener( 'click', function ( event ) {
			event.stopPropagation();
			handler();
		} );

		return button;
	}

	function toggle( path, index, range, additive ) {
		var items = sortItems( state.items );

		if ( range && state.anchor > -1 ) {
			var from = Math.min( state.anchor, index );
			var to   = Math.max( state.anchor, index );
			var span = [];

			for ( var i = from; i <= to; i++ ) {
				if ( items[ i ] ) {
					span.push( items[ i ].path );
				}
			}

			setSelection( span );

			return;
		}

		state.anchor = index;

		if ( additive ) {
			var at = state.selected.indexOf( path );

			if ( at === -1 ) {
				state.selected.push( path );
			} else {
				state.selected.splice( at, 1 );
			}

			syncSelectionUI();

			return;
		}

		setSelection( [ path ] );
	}

	function renderStatus( data ) {
		var left  = $( '#beaver-fm-status-left' );
		var right = $( '#beaver-fm-status-right' );

		if ( left ) {
			var parts = [ fmt( i18n.items, state.items.length ) ];

			if ( state.selected.length ) {
				parts.push( fmt( i18n.selected, state.selected.length ) );
			}

			if ( state.clipboard.paths.length ) {
				parts.push( ( 'move' === state.clipboard.mode ? fmt( i18n.clipboardCut, state.clipboard.paths.length ) : fmt( i18n.clipboardCopy, state.clipboard.paths.length ) ) );
			}

			left.textContent = parts.join( ' · ' );
		}

		if ( right && data && data.disk && data.disk.freeText ) {
			right.textContent = data.disk.freeText + ' free';
		}
	}

	/* ------------------------------------------------------------------
	 * Opening things
	 * ------------------------------------------------------------------ */

	function openItem( item ) {
		if ( item.dir ) {
			navigate( item.path );

			return;
		}

		if ( item.editable ) {
			openEditor( item.path );

			return;
		}

		if ( item.preview ) {
			openPreview( item );

			return;
		}

		toast( i18n.notEditable );
		window.location.href = streamUrl( 'download', item.path );
	}

	function openPreview( item ) {
		var overlay = $( '#beaver-fm-preview' );
		var body    = empty( $( '#beaver-fm-preview-body' ) );
		var url     = streamUrl( 'preview', item.path );

		$( '#beaver-fm-preview-name' ).textContent = item.name;
		$( '#beaver-fm-preview-download' ).href = streamUrl( 'download', item.path );

		var node;

		if ( 'image' === item.preview ) {
			node = make( 'img' );
			node.alt = item.name;
			node.src = url;
		} else if ( 'video' === item.preview ) {
			node = make( 'video' );
			node.controls = true;
			node.src = url;
		} else if ( 'audio' === item.preview ) {
			node = make( 'audio' );
			node.controls = true;
			node.src = url;
		} else {
			node = make( 'iframe' );
			node.src = url;
		}

		body.appendChild( node );
		overlay.hidden = false;
	}

	function openInfo( path ) {
		var overlay = $( '#beaver-fm-info' );
		var body    = empty( $( '#beaver-fm-info-body' ) );

		body.appendChild( make( 'p', null, i18n.loading ) );
		overlay.hidden = false;

		request( 'info', { path: path } ).then( function ( data ) {
			empty( body );

			var table = make( 'table' );
			var rows  = [
				[ i18n.infoName, data.entry.name ],
				[ i18n.infoPath, data.entry.path || '/' ],
				[ i18n.infoFullPath, data.absolute ],
				[ i18n.infoType, data.mime ],
				[ i18n.infoSize, data.entry.dir ? ( data.contents ? data.contents.sizeText : '' ) : data.entry.sizeText ],
				[ i18n.infoPerms, data.entry.perms + '  ' + data.entry.permsText ],
				[ i18n.infoOwner, data.owner + ' : ' + data.group ],
				[ i18n.infoModified, data.entry.modified ],
				[ i18n.infoCreated, data.created ]
			];

			if ( data.contents ) {
				rows.push( [
					i18n.infoContains,
					fmt( i18n.infoCounts, data.contents.folders, data.contents.files ) + ( data.contents.capped ? i18n.infoCapped : '' )
				] );
			}

			if ( data.image ) {
				rows.push( [ i18n.infoDimensions, data.image.width + ' × ' + data.image.height ] );
			}

			if ( data.checksum ) {
				rows.push( [ i18n.infoChecksum, data.checksum ] );
			}

			rows.forEach( function ( pair ) {
				if ( ! pair[ 1 ] ) {
					return;
				}

				var tr = make( 'tr' );

				tr.appendChild( make( 'th', null, pair[ 0 ] ) );

				var td = make( 'td' );

				td.appendChild( make( 'code', null, pair[ 1 ] ) );
				tr.appendChild( td );
				table.appendChild( tr );
			} );

			body.appendChild( table );

			var tools = make( 'div', 'beaver-fm-info__tools' );
			var copyPath = make( 'button', 'button', i18n.btnCopyPath );

			copyPath.type = 'button';
			copyPath.addEventListener( 'click', function () {
				copyText( data.absolute, i18n.pathCopied );
			} );
			tools.appendChild( copyPath );

			if ( data.entry.url ) {
				var copyUrl = make( 'button', 'button', i18n.btnCopyUrl );

				copyUrl.type = 'button';
				copyUrl.addEventListener( 'click', function () {
					copyText( data.entry.url, i18n.urlCopied );
				} );
				tools.appendChild( copyUrl );

				var anchor = make( 'a', 'button', i18n.btnPreview );

				anchor.href = data.entry.url;
				anchor.target = '_blank';
				anchor.rel = 'noopener noreferrer';
				tools.appendChild( anchor );
			}

			body.appendChild( tools );

			if ( data.archive && data.archive.entries.length ) {
				body.appendChild( make( 'h3', null, fmt( i18n.infoArchive, data.archive.count ) ) );

				var list = make( 'div', 'beaver-fm-info__list' );

				data.archive.entries.forEach( function ( entry ) {
					var line = make( 'div' );

					line.appendChild( make( 'span', null, entry.name ) );
					line.appendChild( make( 'span', null, entry.sizeText ) );
					list.appendChild( line );
				} );

				body.appendChild( list );
			}
		} ).catch( function ( error ) {
			empty( body ).appendChild( make( 'div', 'beaver-fm-error', error.message ) );
		} );
	}

	/* ------------------------------------------------------------------
	 * Editor
	 * ------------------------------------------------------------------ */

	function editorState( text, kind ) {
		var node = $( '#beaver-fm-editor-state' );

		node.textContent = text || '';
		node.className = 'beaver-fm-editor__state' + ( kind ? ' is-' + kind : '' );
	}

	function destroyCodeMirror() {
		if ( state.editor.cm ) {
			state.editor.cm.toTextArea();
			state.editor.cm = null;
		}
	}

	function openEditor( path ) {
		request( 'read', { path: path } ).then( function ( data ) {
			var overlay = $( '#beaver-fm-editor' );
			var area    = $( '#beaver-fm-editor-area' );

			destroyCodeMirror();

			state.editor.path    = path;
			state.editor.hash    = data.hash;
			state.editor.entry   = data.entry;
			state.editor.dirty   = false;
			state.editor.backups = data.backups || [];

			$( '#beaver-fm-editor-name' ).textContent = data.entry.name;
			$( '#beaver-fm-editor-path' ).textContent = data.entry.path;
			$( '#beaver-fm-editor-history' ).hidden = true;

			area.value = data.content;
			overlay.hidden = false;

			var settings = cfg.editorSettings && cfg.editorSettings[ data.mode ];

			if ( settings && window.wp && window.wp.codeEditor ) {
				var instance = window.wp.codeEditor.initialize( area, settings );

				state.editor.cm = instance.codemirror;
				state.editor.cm.setSize( '100%', '100%' );
				state.editor.cm.on( 'change', markDirty );
				state.editor.cm.refresh();
				state.editor.cm.focus();
			} else {
				area.addEventListener( 'input', markDirty );
				area.focus();
			}

			$( '#beaver-fm-editor-meta' ).textContent = data.entry.sizeText + ' · ' + data.lines + ' lines · ' + data.entry.perms;

			editorState( data.writable ? '' : i18n.readOnlyFile, data.writable ? '' : 'error' );
			renderHistory();
		} ).catch( fail );
	}

	function markDirty() {
		if ( ! state.editor.dirty ) {
			state.editor.dirty = true;
			editorState( '●', 'dirty' );
		}
	}

	function editorContent() {
		return state.editor.cm ? state.editor.cm.getValue() : $( '#beaver-fm-editor-area' ).value;
	}

	function closeEditor( force ) {
		if ( state.editor.dirty && ! force ) {
			askConfirm( {
				title: i18n.unsaved,
				okLabel: i18n.btnClose,
				danger: true,
				onOk: function () {
					closeEditor( true );
				}
			} );

			return;
		}

		destroyCodeMirror();

		$( '#beaver-fm-editor' ).hidden = true;
		state.editor = { path: '', hash: '', cm: null, entry: null, dirty: false, backups: [] };
	}

	function saveEditor( flags ) {
		if ( ! state.editor.path ) {
			return;
		}

		var content = editorContent();

		editorState( i18n.saving );

		request( 'save', {
			path: state.editor.path,
			content: content,
			hash: state.editor.hash,
			force: !! ( flags && flags.force ),
			ignore_syntax: !! ( flags && flags.ignoreSyntax )
		} ).then( function ( data ) {
			state.editor.hash  = data.hash;
			state.editor.dirty = false;

			if ( data.unchanged ) {
				editorState( i18n.noChanges, 'ok' );
			} else {
				editorState( data.backup ? i18n.savedBackup : i18n.saved, 'ok' );
				toast( i18n.saved, 'success' );
			}

			if ( data.entry ) {
				$( '#beaver-fm-editor-meta' ).textContent = data.entry.sizeText + ' · ' + data.entry.perms;
			}

			return request( 'backups', { path: state.editor.path } );
		} ).then( function ( data ) {
			state.editor.backups = data.backups || [];
			renderHistory();
			refresh();
		} ).catch( function ( error ) {
			editorState( error.message, 'error' );

			if ( 'beaver_fm_conflict' === error.code ) {
				askConfirm( {
					title: i18n.conflictSave,
					message: error.message,
					okLabel: i18n.btnOverwrite,
					danger: true,
					onOk: function () {
						saveEditor( { force: true, ignoreSyntax: flags && flags.ignoreSyntax } );
					}
				} );

				return;
			}

			if ( 'beaver_fm_parse_error' === error.code ) {
				if ( error.detail && error.detail.line && state.editor.cm ) {
					state.editor.cm.setCursor( { line: error.detail.line - 1, ch: 0 } );
					state.editor.cm.focus();
				}

				askConfirm( {
					title: i18n.syntaxSave,
					message: error.message,
					okLabel: i18n.btnSaveAnyway,
					danger: true,
					onOk: function () {
						saveEditor( { force: true, ignoreSyntax: true } );
					}
				} );

				return;
			}

			fail( error );
		} );
	}

	function renderHistory() {
		var host = empty( $( '#beaver-fm-editor-history-list' ) );

		if ( ! state.editor.backups.length ) {
			host.appendChild( make( 'p', 'description', i18n.noBackups ) );

			return;
		}

		state.editor.backups.forEach( function ( version ) {
			var node = make( 'div', 'beaver-fm-version' );

			node.appendChild( make( 'strong', null, version.when ) );
			node.appendChild( make( 'span', null, version.ago + ( version.user ? ' · ' + version.user : '' ) + ' · ' + version.sizeText ) );

			var actions = make( 'div', 'beaver-fm-version__actions' );
			var load    = make( 'button', null, i18n.loadVersion );
			var restore = make( 'button', null, i18n.btnRestore );

			load.type = 'button';
			load.addEventListener( 'click', function () {
				request( 'backup_read', { path: state.editor.path, id: version.id } ).then( function ( data ) {
					if ( state.editor.cm ) {
						state.editor.cm.setValue( data.content );
					} else {
						$( '#beaver-fm-editor-area' ).value = data.content;
					}

					markDirty();
				} ).catch( fail );
			} );

			restore.type = 'button';
			restore.addEventListener( 'click', function () {
				askConfirm( {
					title: i18n.restoreBackup,
					message: version.when,
					okLabel: i18n.btnRestore,
					onOk: function () {
						request( 'backup_restore', { path: state.editor.path, id: version.id } ).then( function ( data ) {
							if ( state.editor.cm ) {
								state.editor.cm.setValue( data.content );
							} else {
								$( '#beaver-fm-editor-area' ).value = data.content;
							}

							state.editor.hash  = data.hash;
							state.editor.dirty = false;

							editorState( i18n.restored, 'ok' );
							toast( i18n.restored, 'success' );
							refresh();
						} ).catch( fail );
					}
				} );
			} );

			actions.appendChild( load );
			actions.appendChild( restore );
			node.appendChild( actions );
			host.appendChild( node );
		} );
	}

	/* ------------------------------------------------------------------
	 * Sidebar
	 * ------------------------------------------------------------------ */

	function renderShortcuts() {
		var host = empty( $( '#beaver-fm-shortcuts' ) );

		( cfg.shortcuts || [] ).forEach( function ( shortcut ) {
			var button = make( 'button', 'beaver-fm-shortcut' );

			button.type = 'button';
			button.appendChild( icon( 'dashicons-arrow-right-alt2' ) );
			button.appendChild( document.createTextNode( shortcut.label ) );
			button.addEventListener( 'click', function () {
				navigate( shortcut.path );
			} );

			host.appendChild( button );
		} );
	}

	function treeNode( folder ) {
		var li     = make( 'li' );
		var row    = make( 'div', 'beaver-fm-tree__row' );
		var toggle = make( 'button', 'beaver-fm-tree__toggle' );
		var open   = !! ( state.tree[ folder.path ] && state.tree[ folder.path ].open );

		row.setAttribute( 'data-path', folder.path );

		toggle.type = 'button';
		toggle.appendChild( icon( folder.children ? ( open ? 'dashicons-arrow-down-alt2' : 'dashicons-arrow-right-alt2' ) : 'dashicons-marker' ) );

		if ( ! folder.children ) {
			toggle.style.visibility = 'hidden';
		}

		toggle.addEventListener( 'click', function ( event ) {
			event.stopPropagation();
			toggleTree( folder.path );
		} );

		row.appendChild( toggle );
		row.appendChild( make( 'span', 'beaver-fm-tree__label', folder.name ) );
		row.addEventListener( 'click', function () {
			navigate( folder.path );
		} );

		li.appendChild( row );

		if ( open && state.tree[ folder.path ] && state.tree[ folder.path ].folders ) {
			var ul = make( 'ul' );

			state.tree[ folder.path ].folders.forEach( function ( child ) {
				ul.appendChild( treeNode( child ) );
			} );

			li.appendChild( ul );
		}

		return li;
	}

	function renderTree() {
		var host = empty( $( '#beaver-fm-tree' ) );
		var root = state.tree[ '' ];

		if ( ! root || ! root.folders ) {
			host.appendChild( make( 'p', 'description', i18n.loading ) );

			return;
		}

		var ul = make( 'ul' );

		root.folders.forEach( function ( folder ) {
			ul.appendChild( treeNode( folder ) );
		} );

		host.appendChild( ul );
		markTreeCurrent();
	}

	function loadTree( path ) {
		return request( 'tree', { path: path } ).then( function ( data ) {
			state.tree[ path ] = {
				open: state.tree[ path ] ? state.tree[ path ].open : false,
				folders: data.folders || []
			};

			return state.tree[ path ];
		} );
	}

	function toggleTree( path ) {
		var node = state.tree[ path ];

		if ( node && node.folders ) {
			node.open = ! node.open;
			renderTree();

			return;
		}

		loadTree( path ).then( function ( loaded ) {
			loaded.open = true;
			renderTree();
		} ).catch( fail );
	}

	function markTreeCurrent() {
		$$( '.beaver-fm-tree__row' ).forEach( function ( row ) {
			row.classList.toggle( 'is-current', row.getAttribute( 'data-path' ) === state.path );
		} );
	}

	function expandTreeTo( path ) {
		if ( ! path ) {
			return;
		}

		var segments = path.split( '/' );
		var walked   = '';
		var chain    = [ '' ];

		segments.forEach( function ( segment ) {
			walked = walked ? walked + '/' + segment : segment;
			chain.push( walked );
		} );

		// The last segment is the folder itself; its parents are what must open.
		chain.pop();

		var pending = chain.filter( function ( item ) {
			return ! state.tree[ item ] || ! state.tree[ item ].folders;
		} );

		Promise.all( pending.map( loadTree ) ).then( function () {
			chain.forEach( function ( item ) {
				if ( state.tree[ item ] ) {
					state.tree[ item ].open = true;
				}
			} );

			renderTree();
		} ).catch( function () {
			renderTree();
		} );
	}

	/* ------------------------------------------------------------------
	 * Commands
	 * ------------------------------------------------------------------ */

	function describeSelection() {
		var entries = selectedEntries();

		return 1 === entries.length ? fmt( i18n.oneItem, entries[ 0 ].name ) : fmt( i18n.manyItems, entries.length );
	}

	var commands = {
		up: function () {
			if ( state.path ) {
				navigate( dirname( state.path ) );
			}
		},

		refresh: refresh,

		'new-file': function () {
			askText( {
				title: i18n.newFileTitle,
				value: '',
				onOk: function ( name ) {
					if ( ! name ) {
						return;
					}

					request( 'create', { path: state.path, name: name, type: 'file' } ).then( function ( data ) {
						refresh().then( function () {
							if ( data.entry && data.entry.editable ) {
								openEditor( data.entry.path );
							}
						} );
					} ).catch( fail );
				}
			} );
		},

		'new-folder': function () {
			askText( {
				title: i18n.newFolderTitle,
				value: '',
				onOk: function ( name ) {
					if ( ! name ) {
						return;
					}

					request( 'create', { path: state.path, name: name, type: 'folder' } )
						.then( function () {
							delete state.tree[ state.path ];
							refresh();
							expandTreeTo( join( state.path, name ) );
						} )
						.catch( fail );
				}
			} );
		},

		upload: function () {
			$( '#beaver-fm-file-input' ).click();
		},

		download: function () {
			var entries = selectedEntries();

			if ( ! entries.length ) {
				return;
			}

			if ( 1 === entries.length && ! entries[ 0 ].dir ) {
				window.location.href = streamUrl( 'download', entries[ 0 ].path );

				return;
			}

			// More than one item, or a folder: build an archive first.
			commands.zip( function ( entry ) {
				window.location.href = streamUrl( 'download', entry.path );
			} );
		},

		copy: function () {
			state.clipboard = { mode: 'copy', paths: state.selected.slice() };
			toast( fmt( i18n.clipboardCopy, state.selected.length ) );
			updateToolbar();
			renderStatus();
		},

		cut: function () {
			state.clipboard = { mode: 'move', paths: state.selected.slice() };
			toast( fmt( i18n.clipboardCut, state.selected.length ) );
			renderListing();
			updateToolbar();
		},

		paste: function () {
			if ( ! state.clipboard.paths.length ) {
				return;
			}

			var mode = state.clipboard.mode;
			var overwrite;

			openDialog( {
				title: 'move' === mode ? i18n.moveHere : i18n.copyHere,
				message: fmt( i18n.manyItems, state.clipboard.paths.length ),
				okLabel: 'move' === mode ? i18n.btnMove : i18n.btnCopy,
				build: function ( body ) {
					var label = make( 'label', 'beaver-fm-check' );

					overwrite = make( 'input' );
					overwrite.type = 'checkbox';

					label.appendChild( overwrite );
					label.appendChild( document.createTextNode( ' ' + i18n.overwrite ) );
					body.appendChild( label );
					body.appendChild( make( 'p', 'description', i18n.overwriteHint ) );
				},
				onOk: function () {
					request( 'transfer', {
						paths: state.clipboard.paths,
						dest: state.path,
						mode: mode,
						overwrite: overwrite.checked
					} ).then( function ( data ) {
						if ( data.errors && data.errors.length ) {
							data.errors.forEach( function ( message ) {
								toast( message, 'error' );
							} );
						}

						toast( fmt( 'move' === mode ? i18n.moved : i18n.copied, data.done ), 'success' );

						if ( 'move' === mode ) {
							state.clipboard = { mode: '', paths: [] };
						}

						delete state.tree[ state.path ];
						refresh();
					} ).catch( fail );
				}
			} );
		},

		rename: function () {
			var entry = selectedEntries()[ 0 ];

			if ( ! entry ) {
				return;
			}

			askText( {
				title: i18n.renameTitle,
				value: entry.name,
				onOk: function ( name ) {
					if ( ! name || name === entry.name ) {
						return;
					}

					request( 'rename', { path: entry.path, name: name } ).then( function () {
						delete state.tree[ state.path ];
						refresh();
					} ).catch( fail );
				}
			} );
		},

		chmod: function () {
			var entries = selectedEntries();

			if ( ! entries.length ) {
				return;
			}

			var input;
			var recursive;

			openDialog( {
				title: i18n.chmodTitle,
				message: describeSelection(),
				okLabel: i18n.btnApply,
				build: function ( body ) {
					var label = make( 'label' );

					label.appendChild( make( 'span', null, i18n.modeLabel ) );

					input = make( 'input' );
					input.type = 'text';
					input.value = entries[ 0 ].perms ? entries[ 0 ].perms.slice( -3 ) : '644';

					label.appendChild( input );
					body.appendChild( label );
					body.appendChild( make( 'p', 'description', i18n.chmodHelp ) );

					var check = make( 'label', 'beaver-fm-check' );

					recursive = make( 'input' );
					recursive.type = 'checkbox';

					check.appendChild( recursive );
					check.appendChild( document.createTextNode( ' ' + i18n.applyRecursive ) );
					body.appendChild( check );
				},
				onOk: function () {
					request( 'chmod', {
						paths: state.selected,
						mode: input.value.trim(),
						recursive: recursive.checked
					} ).then( function ( data ) {
						( data.errors || [] ).forEach( function ( message ) {
							toast( message, 'error' );
						} );

						if ( data.done ) {
							toast( fmt( i18n.permsChanged, data.done ), 'success' );
						}

						refresh();
					} ).catch( fail );
				}
			} );
		},

		zip: function ( afterwards ) {
			var entries = selectedEntries();

			if ( ! entries.length ) {
				return;
			}

			if ( ! cfg.canZip ) {
				toast( i18n.noZip, 'error' );

				return;
			}

			var suggested = 1 === entries.length ? entries[ 0 ].name + '.zip' : ( basename( state.path ) || 'archive' ) + '.zip';

			askText( {
				title: i18n.zipTitle,
				label: i18n.archiveName,
				value: suggested,
				onOk: function ( name ) {
					request( 'zip', {
						paths: state.selected,
						dest: state.path,
						name: name
					} ).then( function ( data ) {
						toast( i18n.archived, 'success' );
						refresh();

						if ( 'function' === typeof afterwards && data.entry ) {
							afterwards( data.entry );
						}
					} ).catch( fail );
				}
			} );
		},

		unzip: function () {
			var entry = selectedEntries()[ 0 ];

			if ( ! entry ) {
				return;
			}

			askConfirm( {
				title: i18n.extractTitle,
				message: fmt( i18n.extractAsk, entry.name ),
				okLabel: i18n.btnExtract,
				onOk: function () {
					request( 'unzip', { path: entry.path, dest: state.path } ).then( function ( data ) {
						toast( fmt( i18n.extracted, data.files ), 'success' );
						delete state.tree[ state.path ];
						refresh();
					} ).catch( fail );
				}
			} );
		},

		'delete': function () {
			if ( ! state.selected.length ) {
				return;
			}

			var permanent;
			var description = describeSelection();

			openDialog( {
				title: cfg.useTrash ? fmt( i18n.confirmDelete, description ) : fmt( i18n.confirmErase, description ),
				okLabel: i18n.btnDelete,
				danger: true,
				build: function ( body ) {
					if ( ! cfg.useTrash ) {
						return;
					}

					var label = make( 'label', 'beaver-fm-check' );

					permanent = make( 'input' );
					permanent.type = 'checkbox';

					label.appendChild( permanent );
					label.appendChild( document.createTextNode( ' ' + i18n.skipTrash ) );
					body.appendChild( label );
				},
				onOk: function () {
					request( 'delete', {
						paths: state.selected,
						permanent: permanent ? permanent.checked : true
					} ).then( function ( data ) {
						( data.errors || [] ).forEach( function ( message ) {
							toast( message, 'error' );
						} );

						if ( data.done ) {
							toast( fmt( data.trashed ? i18n.trashed : i18n.deleted, data.done ), 'success' );
						}

						delete state.tree[ state.path ];
						refresh();
					} ).catch( fail );
				}
			} );
		},

		search: function () {
			runSearch( $( '#beaver-fm-search-input' ).value.trim() );
		},

		trash: openTrash,

		'trash-empty': function () {
			askConfirm( {
				title: i18n.confirmEmpty,
				okLabel: i18n.btnEmptyTrash,
				danger: true,
				onOk: function () {
					request( 'trash_empty', {} ).then( function () {
						openTrash();
					} ).catch( fail );
				}
			} );
		},

		'trash-close': function () {
			$( '#beaver-fm-trash' ).hidden = true;
		},

		'editor-save': function () {
			saveEditor();
		},

		'editor-close': function () {
			closeEditor();
		},

		'editor-history': function () {
			var panel = $( '#beaver-fm-editor-history' );

			panel.hidden = ! panel.hidden;

			if ( state.editor.cm ) {
				state.editor.cm.refresh();
			}
		},

		'preview-close': function () {
			$( '#beaver-fm-preview' ).hidden = true;
			empty( $( '#beaver-fm-preview-body' ) );
		},

		'info-close': function () {
			$( '#beaver-fm-info' ).hidden = true;
		},

		'dialog-cancel': closeDialog,
		'dialog-ok': submitDialog
	};

	/* ------------------------------------------------------------------
	 * Search
	 * ------------------------------------------------------------------ */

	function runSearch( query ) {
		if ( ! query ) {
			return navigate( state.path );
		}

		var listing = $( '#beaver-fm-listing' );

		empty( listing ).appendChild( make( 'div', 'beaver-fm-empty', i18n.loading ) );

		return request( 'search', {
			path: state.path,
			query: query,
			contents: $( '#beaver-fm-search-contents' ).checked
		} ).then( function ( data ) {
			state.mode     = 'search';
			state.search   = { query: query, scanned: data.scanned, capped: data.capped };
			state.items    = data.results || [];
			state.selected = [];

			renderListing();
			renderStatus();
		} ).catch( function ( error ) {
			empty( listing ).appendChild( make( 'div', 'beaver-fm-error', error.message ) );
		} );
	}

	/* ------------------------------------------------------------------
	 * Trash
	 * ------------------------------------------------------------------ */

	function openTrash() {
		var overlay = $( '#beaver-fm-trash' );
		var body    = empty( $( '#beaver-fm-trash-body' ) );

		body.appendChild( make( 'p', null, i18n.loading ) );
		overlay.hidden = false;

		request( 'trash', {} ).then( function ( data ) {
			empty( body );

			if ( ! data.items.length ) {
				body.appendChild( make( 'p', 'description', i18n.trashEmpty ) );

				return;
			}

			var table = make( 'table' );
			var head  = make( 'tr' );

			[ i18n.colName, i18n.colFrom, i18n.colDeleted, i18n.colSize, '' ].forEach( function ( label ) {
				head.appendChild( make( 'th', null, label ) );
			} );

			table.appendChild( head );

			data.items.forEach( function ( item ) {
				var row  = make( 'tr' );
				var name = make( 'td' );

				name.appendChild( icon( item.dir ? ICONS.folder : ICONS.file, 'beaver-fm-name__icon' ) );
				name.appendChild( document.createTextNode( ' ' + item.name ) );
				row.appendChild( name );

				var from = make( 'td' );

				from.appendChild( make( 'code', 'beaver-fm-path', dirname( item.path ) || '/' ) );
				row.appendChild( from );

				var when = make( 'td' );

				when.appendChild( make( 'span', null, item.ago ) );
				when.appendChild( make( 'span', 'beaver-fm-path', item.user ? ' · ' + item.user : '' ) );
				row.appendChild( when );

				row.appendChild( make( 'td', null, item.sizeText ) );

				var actions = make( 'td' );

				if ( ! cfg.canWrite ) {
					row.appendChild( actions );
					table.appendChild( row );

					return;
				}

				var restore = make( 'button', 'button button-small', i18n.btnRestore );
				var erase   = make( 'button', 'button button-small beaver-fm-danger', i18n.btnDelete );

				restore.type = 'button';
				restore.addEventListener( 'click', function () {
					request( 'trash_restore', { id: item.id } ).then( function () {
						toast( i18n.restored, 'success' );
						openTrash();
						refresh();
					} ).catch( fail );
				} );

				erase.type = 'button';
				erase.addEventListener( 'click', function () {
					askConfirm( {
						title: fmt( i18n.confirmErase, fmt( i18n.oneItem, item.name ) ),
						okLabel: i18n.btnDelete,
						danger: true,
						onOk: function () {
							request( 'trash_delete', { id: item.id } ).then( function () {
								openTrash();
							} ).catch( fail );
						}
					} );
				} );

				actions.appendChild( restore );
				actions.appendChild( document.createTextNode( ' ' ) );
				actions.appendChild( erase );
				row.appendChild( actions );

				table.appendChild( row );
			} );

			body.appendChild( table );
		} ).catch( function ( error ) {
			empty( body ).appendChild( make( 'div', 'beaver-fm-error', error.message ) );
		} );
	}

	/* ------------------------------------------------------------------
	 * Uploads
	 * ------------------------------------------------------------------ */

	function uploadFiles( files ) {
		var list = Array.prototype.slice.call( files );

		if ( ! list.length ) {
			return;
		}

		var oversized = list.filter( function ( file ) {
			return cfg.maxUpload > 0 && file.size > cfg.maxUpload;
		} );

		oversized.forEach( function ( file ) {
			toast( fmt( i18n.uploadTooBig, file.name, cfg.maxUploadText ), 'error' );
		} );

		list = list.filter( function ( file ) {
			return oversized.indexOf( file ) === -1;
		} );

		if ( ! list.length ) {
			return;
		}

		var status = $( '#beaver-fm-status-left' );
		var bar    = make( 'span', 'beaver-fm-progress' );
		var fill   = make( 'span' );

		fill.style.width = '0%';
		bar.appendChild( fill );

		status.textContent = fmt( i18n.uploading, 1, list.length );
		status.appendChild( bar );

		var body = new FormData();

		body.append( 'action', 'beaver_fm_upload' );
		body.append( 'nonce', cfg.nonce );
		body.append( 'path', state.path );

		list.forEach( function ( file ) {
			body.append( 'files[]', file, file.name );
		} );

		var xhr = new XMLHttpRequest();

		xhr.open( 'POST', cfg.ajaxUrl, true );
		xhr.withCredentials = true;

		xhr.upload.addEventListener( 'progress', function ( event ) {
			if ( event.lengthComputable ) {
				fill.style.width = Math.round( ( event.loaded / event.total ) * 100 ) + '%';
			}
		} );

		xhr.addEventListener( 'load', function () {
			var json;

			try {
				json = JSON.parse( xhr.responseText );
			} catch ( e ) {
				json = null;
			}

			if ( ! json || ! json.success ) {
				fail( new Error( ( json && json.data && json.data.message ) || i18n.requestFailed ) );
				refresh();

				return;
			}

			( json.data.errors || [] ).forEach( function ( message ) {
				toast( message, 'error' );
			} );

			if ( json.data.entries && json.data.entries.length ) {
				toast( fmt( i18n.uploaded, json.data.entries.length ), 'success' );
			}

			refresh();
		} );

		xhr.addEventListener( 'error', function () {
			fail( new Error( i18n.requestFailed ) );
			refresh();
		} );

		xhr.send( body );
	}

	/* ------------------------------------------------------------------
	 * Wiring
	 * ------------------------------------------------------------------ */

	function bindToolbar() {
		$$( '[data-fm]' ).forEach( function ( button ) {
			var name = button.getAttribute( 'data-fm' );

			if ( ! commands[ name ] ) {
				return;
			}

			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				commands[ name ]();
			} );
		} );

		$( '#beaver-fm-search-input' ).addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				commands.search();
			}
		} );

		$( '#beaver-fm-file-input' ).addEventListener( 'change', function ( event ) {
			uploadFiles( event.target.files );
			event.target.value = '';
		} );
	}

	function bindDragAndDrop() {
		var main = $( '.beaver-fm-main' );
		var zone = $( '#beaver-fm-dropzone' );
		var depth = 0;

		if ( ! main || ! cfg.canWrite ) {
			return;
		}

		[ 'dragenter', 'dragover' ].forEach( function ( name ) {
			main.addEventListener( name, function ( event ) {
				event.preventDefault();
				event.stopPropagation();

				if ( 'dragenter' === name ) {
					depth++;
				}

				zone.hidden = false;
			} );
		} );

		main.addEventListener( 'dragleave', function ( event ) {
			event.preventDefault();
			depth--;

			if ( depth <= 0 ) {
				depth = 0;
				zone.hidden = true;
			}
		} );

		main.addEventListener( 'drop', function ( event ) {
			event.preventDefault();
			event.stopPropagation();

			depth = 0;
			zone.hidden = true;

			if ( event.dataTransfer && event.dataTransfer.files ) {
				uploadFiles( event.dataTransfer.files );
			}
		} );
	}

	function bindKeys() {
		document.addEventListener( 'keydown', function ( event ) {
			var editorOpen = ! $( '#beaver-fm-editor' ).hidden;
			var dialogOpen = ! $( '#beaver-fm-dialog' ).hidden;

			if ( ( event.ctrlKey || event.metaKey ) && 's' === event.key.toLowerCase() && editorOpen ) {
				event.preventDefault();
				saveEditor();

				return;
			}

			if ( 'Escape' === event.key ) {
				if ( dialogOpen ) {
					closeDialog();
				} else if ( editorOpen ) {
					closeEditor();
				} else if ( ! $( '#beaver-fm-preview' ).hidden ) {
					commands[ 'preview-close' ]();
				} else if ( ! $( '#beaver-fm-info' ).hidden ) {
					commands[ 'info-close' ]();
				} else if ( ! $( '#beaver-fm-trash' ).hidden ) {
					commands[ 'trash-close' ]();
				}

				return;
			}

			if ( dialogOpen ) {
				if ( 'Enter' === event.key && 'TEXTAREA' !== event.target.tagName ) {
					event.preventDefault();
					submitDialog();
				}

				return;
			}

			if ( editorOpen ) {
				return;
			}

			var typing = /^(INPUT|TEXTAREA|SELECT)$/.test( event.target.tagName );

			if ( typing ) {
				return;
			}

			if ( 'F2' === event.key && 1 === state.selected.length ) {
				event.preventDefault();
				commands.rename();
			} else if ( 'Delete' === event.key && state.selected.length ) {
				event.preventDefault();
				commands[ 'delete' ]();
			} else if ( ( event.ctrlKey || event.metaKey ) && 'a' === event.key.toLowerCase() ) {
				event.preventDefault();
				setSelection( state.items.map( function ( item ) {
					return item.path;
				} ) );
			} else if ( ( event.ctrlKey || event.metaKey ) && 'c' === event.key.toLowerCase() && state.selected.length ) {
				commands.copy();
			} else if ( ( event.ctrlKey || event.metaKey ) && 'x' === event.key.toLowerCase() && state.selected.length ) {
				commands.cut();
			} else if ( ( event.ctrlKey || event.metaKey ) && 'v' === event.key.toLowerCase() && state.clipboard.paths.length ) {
				commands.paste();
			} else if ( 'Backspace' === event.key && state.path ) {
				event.preventDefault();
				commands.up();
			}
		} );
	}

	function bindOverlayDismiss() {
		$$( '.beaver-fm-overlay' ).forEach( function ( overlay ) {
			overlay.addEventListener( 'mousedown', function ( event ) {
				if ( event.target !== overlay ) {
					return;
				}

				if ( 'beaver-fm-dialog' === overlay.id ) {
					closeDialog();
				} else if ( 'beaver-fm-editor' === overlay.id ) {
					closeEditor();
				} else {
					overlay.hidden = true;
				}
			} );
		} );
	}

	function start() {
		if ( ! $( '#beaver-fm' ) || ! cfg.ajaxUrl ) {
			return;
		}

		renderShortcuts();
		bindToolbar();
		bindDragAndDrop();
		bindKeys();
		bindOverlayDismiss();

		/*
		 * The overlays live outside .beaver-fm-app, so the data-writable rule
		 * that hides the write toolbar does not reach them. On a read-only site
		 * the server would refuse these anyway — better not to offer them.
		 */
		if ( ! cfg.canWrite ) {
			$$( '[data-fm="editor-save"], [data-fm="trash-empty"]' ).forEach( function ( button ) {
				button.hidden = true;
			} );
		}

		loadTree( '' ).then( renderTree ).catch( fail );
		navigate( cfg.startPath || '' );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();

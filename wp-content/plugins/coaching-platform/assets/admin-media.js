( function () {
	'use strict';
	var cfg = window.ccMedia;
	var drop = document.getElementById( 'cc-media-drop' );
	var input = document.getElementById( 'cc-media-input' );
	var queue = document.getElementById( 'cc-media-queue' );
	var start = document.getElementById( 'cc-media-start' );
	var status = document.getElementById( 'cc-media-status' );
	if ( ! cfg || ! drop || ! input || ! queue || ! start || ! status ) {
		return;
	}
	var entries = [];
	var problems = document.createElement( 'ul' );
	problems.className = 'cc-media-problems';
	problems.setAttribute( 'role', 'status' );
	problems.setAttribute( 'aria-live', 'polite' );
	start.parentNode.insertBefore( problems, start );

	function extensionOf( name ) {
		var parts = name.toLowerCase().split( '.' );
		return parts.length > 1 ? parts.pop() : '';
	}

	function isImage( file ) {
		return ( cfg.types[ extensionOf( file.name ) ] || '' ).indexOf( 'image/' ) === 0;
	}

	// Mirrors CC_Media_Rules::decide(); the server re-checks everything including the real content type.
	function typeProblem( file ) {
		var parts = file.name.toLowerCase().split( '.' );
		var ext = extensionOf( file.name );
		if ( ! cfg.types[ ext ] ) {
			return 'Only JPEG, PNG, WebP, GIF and PDF files are allowed.';
		}
		if ( parts.slice( 1, -1 ).some( function ( part ) { return cfg.blocked.indexOf( part ) !== -1; } ) ) {
			return 'Rename the file: it has a blocked extension in its name.';
		}
		var max = ext === 'pdf' ? cfg.pdfMax : cfg.imageMax;
		if ( file.size <= 0 ) {
			return 'The file is empty.';
		}
		if ( file.size > max ) {
			return 'File too large (max ' + ( max / 1048576 ) + ' MB).';
		}
		return '';
	}

	function problemOf( entry ) {
		if ( entry.problem ) {
			return entry.file.name + ': ' + entry.problem;
		}
		return entry.image && entry.alt.value.trim() === '' ? 'Alt text required for ' + entry.file.name : '';
	}

	function refresh() {
		var messages = entries.map( problemOf ).filter( Boolean );
		problems.textContent = '';
		messages.forEach( function ( message ) {
			var item = document.createElement( 'li' );
			item.textContent = message;
			problems.appendChild( item );
		} );
		start.disabled = entries.length === 0 || messages.length > 0;
	}

	function remove( entry ) {
		var index = entries.indexOf( entry );
		entries.splice( index, 1 );
		queue.removeChild( entry.row );
		refresh();
		var neighbour = entries[ index ] || entries[ index - 1 ];
		( neighbour ? neighbour.remove : drop ).focus();
	}

	function setRemovable( enabled ) {
		entries.forEach( function ( entry ) { entry.remove.disabled = ! enabled; } );
	}

	function add( file ) {
		var entry = { file: file, problem: typeProblem( file ), image: isImage( file ), alt: null, row: document.createElement( 'li' ), note: document.createElement( 'span' ), remove: document.createElement( 'button' ) };
		var name = document.createElement( 'strong' );
		name.textContent = file.name;
		entry.row.appendChild( name );
		if ( entry.problem ) {
			var error = document.createElement( 'span' );
			error.className = 'cc-media-error';
			error.textContent = entry.problem;
			entry.row.appendChild( error );
		} else if ( entry.image ) {
			entry.alt = document.createElement( 'input' );
			entry.alt.type = 'text';
			entry.alt.maxLength = cfg.altMax;
			entry.alt.required = true;
			entry.alt.placeholder = 'Alt text (required)';
			entry.alt.setAttribute( 'aria-label', 'Alt text for ' + file.name );
			entry.alt.addEventListener( 'input', refresh );
			entry.row.appendChild( entry.alt );
		}
		entry.row.appendChild( entry.note );
		entry.remove.type = 'button';
		entry.remove.className = 'button-link cc-media-remove';
		entry.remove.textContent = 'Remove';
		entry.remove.setAttribute( 'aria-label', 'Remove ' + file.name + ' from the queue' );
		entry.remove.addEventListener( 'click', function () { remove( entry ); } );
		entry.row.appendChild( entry.remove );
		queue.appendChild( entry.row );
		entries.push( entry );
	}

	function take( files ) {
		Array.prototype.forEach.call( files, add );
		refresh();
	}

	function send( entry ) {
		var body = new FormData();
		body.append( 'action', cfg.action );
		body.append( '_wpnonce', cfg.nonce );
		body.append( 'ajax', '1' );
		body.append( 'alt', entry.alt ? entry.alt.value.trim() : '' );
		body.append( 'file', entry.file );
		return fetch( cfg.endpoint, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) { return response.json().catch( function () { return { success: false, data: {} }; } ); } )
			.then( function ( result ) {
				entry.note.textContent = result.success ? 'Uploaded' : ( ( result.data && result.data.message ) || 'Upload failed.' );
				return !! result.success;
			} )
			.catch( function () {
				entry.note.textContent = 'Upload failed.';
				return false;
			} );
	}

	start.addEventListener( 'click', function () {
		start.disabled = true;
		setRemovable( false );
		status.textContent = 'Uploading...';
		entries.reduce( function ( chain, entry ) {
			return chain.then( function ( okSoFar ) {
				return send( entry ).then( function ( ok ) { return okSoFar && ok; } );
			} );
		}, Promise.resolve( true ) ).then( function ( allOk ) {
			status.textContent = allOk ? 'Done. Reloading...' : 'Some files failed. See the list.';
			if ( allOk ) {
				window.location.reload();
			} else {
				setRemovable( true );
			}
		} );
	} );

	drop.addEventListener( 'click', function () { input.click(); } );
	drop.addEventListener( 'keydown', function ( event ) {
		if ( event.key === 'Enter' || event.key === ' ' ) {
			event.preventDefault();
			input.click();
		}
	} );
	input.addEventListener( 'change', function () {
		take( input.files );
		input.value = '';
	} );
	[ 'dragenter', 'dragover' ].forEach( function ( name ) {
		drop.addEventListener( name, function ( event ) {
			event.preventDefault();
			drop.classList.add( 'is-over' );
		} );
	} );
	[ 'dragleave', 'drop' ].forEach( function ( name ) {
		drop.addEventListener( name, function ( event ) {
			event.preventDefault();
			drop.classList.remove( 'is-over' );
		} );
	} );
	drop.addEventListener( 'drop', function ( event ) {
		take( event.dataTransfer.files );
	} );
}() );

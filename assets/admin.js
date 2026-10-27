/**
 * WPress Restore admin page: backups list, chunked upload, and the restore loop.
 *
 * Every server call is one short request; the restore advances by calling "step" until the
 * job leaves its working statuses. Texts come translated from PHP (window.wpressRestore.i18n).
 */
( function () {
	'use strict';

	var cfg = window.wpressRestore;
	if ( ! cfg ) {
		return;
	}
	var t = cfg.i18n;
	var WORKING = [ 'scanning', 'verifying', 'extracting_db', 'importing_db', 'extracting_files', 'finalizing' ];
	var BEFORE_CONFIRM = [ 'scanning', 'verifying' ];
	var AFTER_CONFIRM = [ 'extracting_db', 'importing_db', 'extracting_files', 'finalizing' ];
	var state = { job: cfg.job, backups: cfg.backups, running: false, uploading: false };

	/* Helpers */

	function format( template ) {
		var args = arguments;
		var next = 0;
		return String( template ).replace( /%(?:(\d+)\$)?([sd])/g, function ( match, position ) {
			var index = position ? parseInt( position, 10 ) : ++next;
			return String( args[ index ] );
		} );
	}

	function bytes( value ) {
		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var unit = 0;
		value = Number( value ) || 0;
		while ( value >= 1024 && unit < units.length - 1 ) {
			value /= 1024;
			unit++;
		}
		return ( unit ? value.toFixed( 1 ) : String( value ) ) + ' ' + units[ unit ];
	}

	function count( value ) {
		return Number( value || 0 ).toLocaleString();
	}

	function when( seconds ) {
		return new Date( seconds * 1000 ).toLocaleString();
	}

	function sleep( ms ) {
		return new Promise( function ( resolve ) {
			setTimeout( resolve, ms );
		} );
	}

	/** Builds an element; strings become text nodes, never HTML. */
	function el( tag, attributes, children ) {
		var node = document.createElement( tag );
		Object.keys( attributes || {} ).forEach( function ( name ) {
			var value = attributes[ name ];
			if ( 'onclick' === name || 'onchange' === name || 'oninput' === name ) {
				node.addEventListener( name.slice( 2 ), value );
			} else if ( 'className' === name ) {
				node.className = value;
			} else if ( true === value ) {
				node.setAttribute( name, '' );
			} else if ( false !== value && null !== value && undefined !== value ) {
				node.setAttribute( name, value );
			}
		} );
		( Array.isArray( children ) ? children : [ children ] ).forEach( function ( child ) {
			if ( null !== child && undefined !== child && false !== child ) {
				node.appendChild( 'object' === typeof child ? child : document.createTextNode( String( child ) ) );
			}
		} );
		return node;
	}

	function button( label, onclick, primary ) {
		return el( 'button', { type: 'button', className: primary ? 'button button-primary' : 'button', onclick: onclick }, label );
	}

	function ApiError( message, code, status ) {
		this.message = message;
		this.code = code;
		this.status = status;
	}

	/** One request to the AJAX endpoint; resolves with the data or rejects with an ApiError. */
	function call( op, data, blob ) {
		var body = new FormData();
		body.append( 'action', 'wpress_restore' );
		body.append( 'op', op );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );
		if ( blob ) {
			body.append( 'chunk', blob, 'chunk' );
		}
		return fetch( cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } ).then(
			function ( response ) {
				return response.text().then( function ( text ) {
					var json = null;
					try {
						json = JSON.parse( text );
					} catch ( e ) {
						throw new ApiError( format( t.serverStopped, response.status ), 'server', response.status );
					}
					if ( ! json || ! json.success ) {
						var info = ( json && json.data ) || {};
						throw new ApiError( info.message || format( t.serverStopped, response.status ), info.code || 'error', response.status );
					}
					return json.data;
				} );
			},
			function () {
				throw new ApiError( t.network, 'network', 0 );
			}
		);
	}

	function report( error ) {
		window.alert( error.message || String( error ) );
	}

	/* Backups */

	var table = document.getElementById( 'wpr-backups' );

	function jobBlocks() {
		return state.job && 'done' !== state.job.status;
	}

	function renderBackups() {
		table.textContent = '';
		if ( ! state.backups.length ) {
			table.appendChild( el( 'tr', {}, el( 'td', { colspan: 4 }, t.noBackups ) ) );
			return;
		}
		state.backups.forEach( function ( backup ) {
			var actions = [];
			if ( ! backup.partial ) {
				var restore = button( t.restore, function () {
					start( backup.name );
				}, true );
				restore.disabled = jobBlocks() || state.uploading;
				actions.push( restore );
			}
			var remove = button( t['delete'], function () {
				if ( window.confirm( format( t.confirmDelete, backup.name ) ) ) {
					call( 'delete', { name: backup.name } ).then( function ( data ) {
						state.backups = data.backups;
						renderBackups();
					}, report );
				}
			} );
			remove.disabled = jobBlocks() && state.job.backup === backup.name;
			actions.push( remove );
			table.appendChild(
				el( 'tr', {}, [
					el( 'td', {}, [ el( 'strong', {}, backup.name ), backup.partial ? el( 'div', { className: 'wpr-muted' }, t.partial ) : null ] ),
					el( 'td', {}, bytes( backup.size ) ),
					el( 'td', {}, when( backup.mtime ) ),
					el( 'td', { className: 'wpr-actions' }, actions ),
				] )
			);
		} );
	}

	/* Upload */

	var fileInput = document.getElementById( 'wpr-file' );
	var sendButton = document.getElementById( 'wpr-send' );
	var uploadStatus = document.getElementById( 'wpr-upload-status' );

	function uploadMessage( text, kind ) {
		uploadStatus.hidden = false;
		uploadStatus.className = 'wpr-status' + ( kind ? ' wpr-' + kind : '' );
		uploadStatus.textContent = text;
	}

	fileInput.addEventListener( 'change', function () {
		sendButton.disabled = ! fileInput.files.length;
	} );

	sendButton.addEventListener( 'click', function () {
		var file = fileInput.files[ 0 ];
		if ( ! file ) {
			return;
		}
		if ( ! /\.wpress$/i.test( file.name ) ) {
			uploadMessage( t.notWpress, 'error' );
			return;
		}
		upload( file, false );
	} );

	async function upload( file, overwrite ) {
		var begin;
		try {
			begin = await call( 'upload_begin', { name: file.name, size: file.size, overwrite: overwrite ? '1' : '0' } );
		} catch ( error ) {
			if ( 'exists' === error.code && window.confirm( format( t.replaceBackup, file.name ) ) ) {
				return upload( file, true );
			}
			uploadMessage( format( t.uploadFailed, error.message ), 'error' );
			return;
		}
		state.uploading = true;
		sendButton.disabled = true;
		fileInput.disabled = true;
		renderBackups();
		var offset = begin.offset;
		var chunk = cfg.chunkSize;
		var failures = 0;
		try {
			while ( offset < file.size ) {
				uploadMessage( format( t.uploading, begin.name, bytes( offset ), bytes( file.size ) ) + ' (' + Math.floor( ( offset / file.size ) * 100 ) + ' %)' );
				try {
					var result = await call( 'upload_chunk', { name: begin.name, size: file.size, offset: offset }, file.slice( offset, Math.min( offset + chunk, file.size ) ) );
					offset = result.offset;
					failures = 0;
					if ( result.done ) {
						state.backups = result.backups;
					}
				} catch ( error ) {
					if ( 'chunk_size' === error.code || 413 === error.status ) {
						if ( chunk <= 64 * 1024 ) {
							throw error;
						}
						chunk = Math.floor( chunk / 2 );
					} else if ( ( 'network' === error.code || 'server' === error.code ) && ++failures <= 5 ) {
						uploadMessage( format( t.retrying, failures * 3 ), 'warning' );
						await sleep( failures * 3000 );
					} else {
						throw error;
					}
				}
			}
			uploadMessage( format( t.uploadDone, begin.name ), 'success' );
			fileInput.value = '';
		} catch ( error ) {
			uploadMessage( format( t.uploadFailed, error.message ), 'error' );
		}
		state.uploading = false;
		fileInput.disabled = false;
		sendButton.disabled = ! fileInput.files.length;
		renderBackups();
	}

	/* Restore */

	var panel = document.getElementById( 'wpr-job' );

	function setJob( job ) {
		// A restore that finishes while this page is open was just done here: offer to log in.
		if ( job && 'done' === job.status && state.job && 'done' !== state.job.status ) {
			state.justFinished = true;
		}
		state.job = job;
		renderJob();
		renderBackups();
	}

	async function start( name ) {
		try {
			var data = await call( 'start', { name: name } );
			setJob( data.job );
			run();
		} catch ( error ) {
			report( error );
		}
	}

	/** Calls "step" until the job stops working; a lost connection is retried, the progress being saved. */
	async function run() {
		if ( state.running ) {
			return;
		}
		state.running = true;
		renderJob();
		var failures = 0;
		while ( state.running && state.job && -1 !== WORKING.indexOf( state.job.status ) ) {
			try {
				var data = await call( 'step' );
				if ( ! state.running ) {
					break; // Cancelled while this step ran: its answer is stale.
				}
				failures = 0;
				setJob( data.job );
				if ( data.busy ) {
					renderJob( t.busy );
					await sleep( 3000 );
				}
			} catch ( error ) {
				if ( ! state.running ) {
					break;
				}
				if ( ( 'network' === error.code || 'server' === error.code ) && ++failures <= 5 ) {
					renderJob( format( t.retrying, failures * 5 ) );
					await sleep( failures * 5000 );
					continue;
				}
				state.running = false;
				renderJob( error.message, true );
				return;
			}
		}
		state.running = false;
		renderJob();
	}

	function act( op, data ) {
		return call( op, data ).then( function ( result ) {
			if ( result.backups ) {
				state.backups = result.backups;
			}
			setJob( result.job || null );
			return result;
		} );
	}

	function cancelButton() {
		return button( t.cancel, function () {
			if ( window.confirm( t.confirmCancel ) ) {
				state.running = false;
				act( 'cancel' ).catch( report );
			}
		} );
	}

	function logButton( holder ) {
		return button( t.showLog, function () {
			call( 'log' ).then( function ( data ) {
				holder.textContent = '';
				holder.appendChild( el( 'pre', { className: 'wpr-log' }, data.log ) );
			}, report );
		} );
	}

	function phaseList( current ) {
		var phases = -1 !== BEFORE_CONFIRM.indexOf( current ) ? BEFORE_CONFIRM : AFTER_CONFIRM;
		var reached = false;
		return el(
			'ol',
			{ className: 'wpr-phases' },
			phases.map( function ( phase ) {
				var status = reached ? 'todo' : phase === current ? 'current' : 'done';
				if ( phase === current ) {
					reached = true;
				}
				return el( 'li', { className: 'wpr-' + status }, t.phases[ phase ] );
			} )
		);
	}

	function progressBar( progress ) {
		if ( ! progress || ! progress[ 1 ] ) {
			return el( 'div', { className: 'wpr-bar wpr-indeterminate' }, el( 'span' ) );
		}
		var percent = Math.min( 100, Math.floor( ( progress[ 0 ] / progress[ 1 ] ) * 100 ) );
		return el( 'div', {}, [
			el( 'div', { className: 'wpr-bar' }, el( 'span', { style: 'width:' + percent + '%' } ) ),
			el( 'p', { className: 'wpr-muted' }, format( t.of, bytes( progress[ 0 ] ), bytes( progress[ 1 ] ) ) + ' (' + percent + ' %)' ),
		] );
	}

	function renderJob( message, isError ) {
		var job = state.job;
		panel.textContent = '';
		panel.hidden = ! job;
		if ( ! job ) {
			return;
		}
		panel.appendChild( el( 'h2', {}, format( 'done' === job.status ? t.restoredFrom : t.restoring, job.backup ) ) );
		if ( -1 !== WORKING.indexOf( job.status ) ) {
			renderWorking( job, message, isError );
		} else if ( 'ready' === job.status ) {
			renderSummary( job );
		} else if ( 'done' === job.status ) {
			renderDone( job );
		} else if ( 'failed' === job.status ) {
			renderFailed( job );
		}
	}

	function renderWorking( job, message, isError ) {
		panel.appendChild( phaseList( job.status ) );
		panel.appendChild( progressBar( job.progress ) );
		if ( message ) {
			panel.appendChild( el( 'p', { className: isError ? 'wpr-status wpr-error' : 'wpr-status wpr-warning' }, message ) );
		}
		var buttons = el( 'p', { className: 'wpr-buttons' } );
		if ( ! state.running ) {
			if ( ! message ) {
				panel.appendChild( el( 'p', { className: 'wpr-status wpr-warning' }, t.paused ) );
			}
			buttons.appendChild( button( t.resume, run, true ) );
		}
		if ( ! job.switching ) {
			buttons.appendChild( cancelButton() );
		}
		panel.appendChild( buttons );
	}

	function row( label, value ) {
		return el( 'tr', {}, [ el( 'th', { scope: 'row' }, label ), el( 'td', {}, value ) ] );
	}

	function warningText( warning ) {
		switch ( warning.about ) {
			case 'wordpress':
				return format( t.warnWordpress, warning.backup, warning.here );
			case 'php':
				return format( t.warnPhp, warning.backup, warning.here );
			case 'disk':
				return format( t.warnDisk, bytes( warning.backup ), bytes( warning.here ) );
			case 'no_database':
				return t.warnNoDatabase;
		}
		return '';
	}

	function renderSummary( job ) {
		var s = job.summary;
		var protection = [ s.encrypted ? t.encryptedNote : null, s.compression ? format( t.compressedNote, s.compression ) : null ].filter( Boolean ).join( ', ' );
		panel.appendChild( el( 'h3', {}, t.summaryTitle ) );
		panel.appendChild(
			el( 'table', { className: 'wpr-summary' }, el( 'tbody', {}, [
				row( t.from, s.source ),
				row( t.madeWith, format( t.madeWithValue, s.made_with || '?', s.wordpress || '?', s.php || '?' ) ),
				row( t.content, format( t.filesCount, count( s.files ), bytes( s.bytes ) ) ),
				row( t.database, s.database ? bytes( s.database_bytes ) : t.none ),
				row( t.plugins, count( s.plugins ) ),
				row( t.theme, s.theme || '?' ),
				protection ? row( t.protection, protection ) : null,
			] ) )
		);
		panel.appendChild( el( 'p', {}, s.same_site ? t.sameSite : format( t.addressChange, s.source, cfg.home ) ) );
		s.warnings.forEach( function ( warning ) {
			panel.appendChild( el( 'div', { className: 'notice notice-warning inline' }, el( 'p', {}, warningText( warning ) ) ) );
		} );
		panel.appendChild( el( 'p', {}, t.whatHappens ) );
		panel.appendChild( el( 'p', {}, el( 'strong', {}, t.loginAfter ) ) );

		var password = null;
		if ( s.encrypted ) {
			password = el( 'input', { type: 'password', id: 'wpr-password', className: 'regular-text', autocomplete: 'off' } );
			panel.appendChild( el( 'p', {}, [ el( 'label', { 'for': 'wpr-password' }, t.password ), el( 'br' ), password ] ) );
		}
		var restore = button( t.restoreNow, function () {
			restore.disabled = true;
			act( 'confirm', { password: password ? password.value : '' } ).then( run, function ( error ) {
				restore.disabled = false;
				report( error );
			} );
		}, true );
		restore.disabled = true;
		var agree = el( 'input', {
			type: 'checkbox',
			id: 'wpr-agree',
			onchange: function () {
				restore.disabled = ! agree.checked;
			},
		} );
		panel.appendChild( el( 'p', {}, [ agree, ' ', el( 'label', { 'for': 'wpr-agree' }, t.understand ) ] ) );
		panel.appendChild( el( 'p', { className: 'wpr-buttons' }, [ restore, cancelButton() ] ) );
	}

	function renderDone( job ) {
		var r = job.result || {};
		var logHolder = el( 'div' );
		panel.appendChild( el( 'div', { className: 'notice notice-success inline' }, el( 'p', {}, el( 'strong', {}, t.doneTitle ) ) ) );
		var lines = [ format( t.doneFiles, count( r.files ) ) ];
		if ( r.database ) {
			lines.push( format( t.doneRows, count( r.rows ) ) );
		}
		if ( r.failed_files ) {
			lines.push( format( t.failedFiles, count( r.failed_files ) ) );
		}
		if ( r.failed_rows ) {
			lines.push( format( t.failedRows, count( r.failed_rows ) ) );
		}
		if ( r.refused ) {
			lines.push( format( t.refused, count( r.refused ) ) );
		}
		if ( r.deactivated && r.deactivated.length ) {
			lines.push( format( t.deactivated, r.deactivated.join( ', ' ) ) );
		}
		if ( r.missing && r.missing.length ) {
			lines.push( format( t.missing, r.missing.join( ', ' ) ) );
		}
		lines.push( t.permalinks );
		panel.appendChild( el( 'ul', { className: 'wpr-list' }, lines.map( function ( line ) {
			return el( 'li', {}, line );
		} ) ) );
		var buttons = el( 'p', { className: 'wpr-buttons' } );
		if ( r.database && state.justFinished ) {
			panel.appendChild( el( 'p', {}, t.doneLogin ) );
			buttons.appendChild( el( 'a', { className: 'button button-primary', href: cfg.loginUrl }, t.logIn ) );
		} else {
			panel.appendChild( el( 'p', { className: 'wpr-muted' }, format( t.restoredOn, job.backup, when( r.finished ) ) ) );
			buttons.appendChild( button( t.deleteBackup, function () {
				if ( window.confirm( format( t.confirmDelete, job.backup ) ) ) {
					call( 'delete', { name: job.backup } ).then( function () {
						return act( 'cancel' );
					} ).catch( report );
				}
			} ) );
			buttons.appendChild( button( t.dismiss, function () {
				act( 'cancel' ).catch( report );
			} ) );
		}
		buttons.appendChild( logButton( logHolder ) );
		panel.appendChild( buttons );
		panel.appendChild( logHolder );
	}

	function renderFailed( job ) {
		var logHolder = el( 'div' );
		panel.appendChild( el( 'div', { className: 'notice notice-error inline' }, [ el( 'p', {}, el( 'strong', {}, t.failedTitle ) ), el( 'p', {}, job.error ) ] ) );
		if ( ! job.switching ) {
			panel.appendChild( el( 'p', {}, t.failedSafe ) );
		}
		panel.appendChild( el( 'p', { className: 'wpr-buttons' }, [
			button( t.retry, function () {
				act( 'retry' ).then( run, report );
			}, true ),
			job.switching ? null : cancelButton(),
			logButton( logHolder ),
		] ) );
		panel.appendChild( logHolder );
	}

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( state.running || state.uploading ) {
			event.preventDefault();
			event.returnValue = t.leaveWarning;
		}
	} );

	renderBackups();
	renderJob();
}() );

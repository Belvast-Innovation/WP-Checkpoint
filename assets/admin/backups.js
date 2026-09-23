/**
 * The Backups tab: sends its actions and lets the server render the result.
 *
 * Making a backup, checking one and deleting one go to the REST routes and
 * then reload the tab, whose job block (assets/admin/jobs.js) takes over.
 * When an export ends, the tab reloads on page 1 with the new backup
 * highlighted (?job=id); a check that ends reloads the tab. Before the first
 * backup, the size estimate is started here (never by a page load) and its
 * job is driven like any other, hidden: it is the plugin's own, so it ends
 * silently, with the database size alone if it could not count the files.
 */
( function () {
	'use strict';

	var jobs = window.wpcheckpointJobs || {};
	var config = window.wpcheckpointBackups || {};

	function request( method, path, body, done ) {
		var xhr = new XMLHttpRequest();
		xhr.open( method, jobs.root + 'wp-checkpoint/v1/backups' + path, true );
		xhr.setRequestHeader( 'X-WP-Nonce', jobs.nonce );
		xhr.setRequestHeader( 'Accept', 'application/json' );
		if ( body ) {
			xhr.setRequestHeader( 'Content-Type', 'application/json' );
		}
		xhr.onreadystatechange = function () {
			if ( xhr.readyState !== 4 ) {
				return;
			}
			var data = null;
			try {
				data = JSON.parse( xhr.responseText );
			} catch ( e ) {
				data = null;
			}
			done( xhr.status, data );
		};
		xhr.send( body ? JSON.stringify( body ) : null );
	}

	function lines( text ) {
		return String( text || '' ).split( /\r?\n/ ).map( function ( line ) {
			return line.trim();
		} ).filter( function ( line ) {
			return line !== '';
		} );
	}

	function status( element, text ) {
		if ( element ) {
			element.textContent = text || '';
		}
	}

	function failure( code, data ) {
		if ( code === 401 || code === 403 ) {
			return jobs.labels.session_expired;
		}
		return ( data && data.message ) || config.labels.failed;
	}

	function reload( args ) {
		var url = config.url;
		Object.keys( args || {} ).forEach( function ( key ) {
			url += '&' + encodeURIComponent( key ) + '=' + encodeURIComponent( args[ key ] );
		} );
		window.location.assign( url );
	}

	function bindCreate() {
		var form = document.querySelector( '[data-wpcheckpoint-create]' );
		if ( ! form ) {
			return;
		}
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var button = form.querySelector( 'button[type="submit"]' );
			var out = form.querySelector( '[data-field="form_status"]' );
			var contents = form.querySelector( 'input[name="contents"]:checked' );
			var include = [];
			form.querySelectorAll( 'input[name="include_group"]:checked' ).forEach( function ( box ) {
				include = include.concat( box.value.split( ',' ) );
			} );
			button.disabled = true;
			status( out, config.labels.starting );
			request( 'POST', '', {
				contents: contents ? contents.value : 'all',
				exclusions: lines( form.elements.exclusions.value ),
				exclude_tables: lines( form.elements.exclude_tables.value ),
				include_tables: include
			}, function ( code, data ) {
				if ( code === 201 ) {
					reload();
					return;
				}
				button.disabled = false;
				status( out, failure( code, data ) );
			} );
		} );
	}

	function bindActions() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-backup-action]' );
			if ( ! button ) {
				return;
			}
			var base = button.getAttribute( 'data-base' );
			var action = button.getAttribute( 'data-backup-action' );
			var out = button.parentNode.querySelector( '[data-field="action_status"]' );
			if ( ! out ) {
				out = document.createElement( 'span' );
				out.setAttribute( 'data-field', 'action_status' );
				out.setAttribute( 'role', 'status' );
				out.className = 'wpcheckpoint-form-status';
				button.parentNode.appendChild( out );
			}
			if ( action === 'delete' && ! window.confirm( config.labels.confirm_delete ) ) {
				return;
			}
			button.disabled = true;
			var method = action === 'delete' ? 'DELETE' : 'POST';
			var path = '/' + encodeURIComponent( base ) + ( action === 'verify' ? '/verify' : '' );
			request( method, path, action === 'verify' ? { depth: 'full' } : null, function ( code, data ) {
				if ( code === 200 || code === 201 ) {
					reload( action === 'verify' || ! config.paged ? {} : { paged: config.paged } );
					return;
				}
				button.disabled = false;
				status( out, failure( code, data ) );
			} );
		} );
	}

	function bindFinished() {
		document.addEventListener( 'wpcheckpoint:job-finished', function ( event ) {
			var job = event.detail || {};
			if ( event.target.hasAttribute( 'data-wpcheckpoint-estimate-job' ) ) {
				return; // Handled by the estimate.
			}
			if ( job.type === 'export' && job.status === 'completed' ) {
				reload( { job: job.id } );
			} else if ( job.type === 'verify' && job.status === 'completed' ) {
				reload();
			}
		} );
	}

	function estimate() {
		var box = document.querySelector( '[data-wpcheckpoint-estimate]' );
		if ( ! box ) {
			return;
		}
		var stop = box.querySelector( '[data-estimate-stop]' );
		var files = box.querySelector( '[data-field="files"]' );

		function drive( id ) {
			var el = document.createElement( 'div' );
			el.setAttribute( 'data-wpcheckpoint-job', String( id ) );
			el.setAttribute( 'data-wpcheckpoint-estimate-job', '' );
			el.setAttribute( 'data-status', 'running' );
			el.hidden = true;
			box.appendChild( el );
			el.addEventListener( 'wpcheckpoint:job-finished', function ( event ) {
				stop.hidden = true;
				if ( event.detail && event.detail.status === 'completed' ) {
					reload(); // The server renders the counts.
				} else {
					status( files, '' ); // Could not count: the database size is all there is.
				}
			} );
			if ( window.wpcheckpointDriveJob ) {
				window.wpcheckpointDriveJob( el );
			}
			stop.hidden = false;
			stop.onclick = function () {
				stop.disabled = true;
				var xhr = new XMLHttpRequest();
				xhr.open( 'POST', jobs.root + 'wp-checkpoint/v1/jobs/' + id + '/cancel', true );
				xhr.setRequestHeader( 'X-WP-Nonce', jobs.nonce );
				xhr.onreadystatechange = function () {
					if ( xhr.readyState === 4 ) {
						stop.hidden = true;
						status( files, '' );
					}
				};
				xhr.send();
			};
		}

		var state = box.getAttribute( 'data-state' );
		if ( state === 'running' && box.getAttribute( 'data-job' ) !== '0' ) {
			drive( box.getAttribute( 'data-job' ) );
			return;
		}
		if ( state !== 'due' ) {
			return;
		}
		request( 'POST', '/estimate', null, function ( code, data ) {
			var result = code === 200 && data && data.estimate ? data.estimate : null;
			if ( result && result.state === 'running' && result.job ) {
				drive( result.job );
			} else if ( result && result.state === 'ready' ) {
				reload();
			} else {
				status( files, '' );
			}
		} );
	}

	function init() {
		if ( ! jobs.root || ! config.url ) {
			return;
		}
		bindCreate();
		bindActions();
		bindFinished();
		estimate();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );

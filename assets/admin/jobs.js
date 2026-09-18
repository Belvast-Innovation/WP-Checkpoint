/**
 * Drives job progress blocks ([data-wpcheckpoint-job]).
 *
 * Without loopback the browser ticks the job itself and paces the next
 * tick by retry_after. With loopback the server chains its own requests;
 * the browser only polls the status every 2 seconds and acts as a watchdog:
 * when a job that should be moving (last tick result "more") has not
 * changed for 15 seconds, the browser ticks once to restart the chain. If
 * the watchdog has to fire twice on one page the chain is considered broken
 * (HTTP authentication or a firewall added after the probe) and the browser
 * drives the job itself from then on, instead of pretending a chain that
 * only moves one step per 15 seconds is alive. A 403 (expired nonce, logged
 * out) stops everything and asks for a reload.
 */
( function () {
	'use strict';

	var config = window.wpcheckpointJobs || {};
	var POLL_MS = 2000;
	var WATCHDOG_MS = 15000;
	var WATCHDOG_MAX_FIRES = 2;
	var TERMINAL = [ 'completed', 'failed', 'cancelled' ];

	function request( method, path, done ) {
		var xhr = new XMLHttpRequest();
		xhr.open( method, config.root + 'wp-checkpoint/v1/jobs/' + path, true );
		xhr.setRequestHeader( 'X-WP-Nonce', config.nonce );
		xhr.setRequestHeader( 'Accept', 'application/json' );
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
		xhr.send();
	}

	function setText( root, field, text ) {
		var el = root.querySelector( '[data-field="' + field + '"]' );
		if ( el ) {
			el.textContent = text;
		}
	}

	function setHidden( el, hidden ) {
		if ( el ) {
			if ( hidden ) {
				el.setAttribute( 'hidden', '' );
			} else {
				el.removeAttribute( 'hidden' );
			}
		}
	}

	function render( root, job ) {
		var bar = root.querySelector( '[data-field="progress"]' );
		if ( bar ) {
			bar.value = job.progress;
		}
		root.setAttribute( 'data-status', job.status );
		setText( root, 'progress_text', job.progress + '%' );
		setText( root, 'status_label', config.labels[ job.status ] || job.status );
		setText( root, 'type_label', job.type_label );
		setText( root, 'step_label', job.step_label );
		setText( root, 'message', job.message );
		setText( root, 'last_error', job.last_error );
		setHidden( root.querySelector( '[data-field="last_error"]' ), ! job.last_error );
		if ( typeof job.log_tail === 'string' ) {
			setText( root, 'log_tail', job.log_tail );
		}
		var active = [ 'queued', 'running', 'paused' ].indexOf( job.status ) !== -1;
		setHidden( root.querySelector( '[data-action="cancel"]' ), ! active );
		setHidden( root.querySelector( '[data-action="retry"]' ), job.status !== 'failed' );
	}

	function notice( root, text ) {
		var el = root.querySelector( '[data-field="notice"]' );
		setText( root, 'notice', text || '' );
		setHidden( el, ! text );
	}

	function signature( job ) {
		return [ job.status, job.step, job.progress, job.updated_at ].join( '|' );
	}

	function Driver( root ) {
		this.root = root;
		this.id = root.getAttribute( 'data-wpcheckpoint-job' );
		this.timer = null;
		this.stopped = false;
		this.lastResult = null;
		this.lastSignature = '';
		this.lastChange = Date.now();
		this.loopback = !! config.loopback;
		this.watchdogFires = 0;
		this.bind();
		if ( TERMINAL.indexOf( root.getAttribute( 'data-status' ) ) === -1 ) {
			this.tick();
		}
	}

	Driver.prototype.bind = function () {
		var self = this;
		this.root.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-action]' );
			if ( ! button ) {
				return;
			}
			button.disabled = true;
			self.action( button.getAttribute( 'data-action' ), function () {
				button.disabled = false;
			} );
		} );
	};

	Driver.prototype.stop = function ( text ) {
		this.stopped = true;
		window.clearTimeout( this.timer );
		if ( text ) {
			notice( this.root, text );
		}
	};

	Driver.prototype.later = function ( fn, ms ) {
		if ( this.stopped ) {
			return;
		}
		window.clearTimeout( this.timer );
		this.timer = window.setTimeout( fn, ms );
	};

	Driver.prototype.handle = function ( status, data, onJob ) {
		if ( status === 403 || status === 401 ) {
			this.stop( config.labels.session_expired );
			return false;
		}
		if ( status === 404 ) {
			this.stop( '' );
			return false;
		}
		if ( ! data || ! data.job ) {
			notice( this.root, config.labels.request_failed );
			return false;
		}
		notice( this.root, '' );
		var sig = signature( data.job );
		if ( sig !== this.lastSignature ) {
			this.lastSignature = sig;
			this.lastChange = Date.now();
		}
		render( this.root, data.job );
		onJob( data.job );
		return true;
	};

	Driver.prototype.tick = function () {
		var self = this;
		request( 'POST', this.id + '/tick', function ( status, data ) {
			if ( ! self.handle( status, data, function () {} ) ) {
				if ( ! self.stopped ) {
					self.later( function () { self.tick(); }, 5000 );
				}
				return;
			}
			self.lastResult = data.result;
			switch ( data.result ) {
				case 'more':
					if ( self.loopback ) {
						self.later( function () { self.poll(); }, POLL_MS );
					} else {
						self.later( function () { self.tick(); }, 0 );
					}
					break;
				case 'waiting':
				case 'blocked':
				case 'busy':
					notice( self.root, data.message || config.labels[ data.result ] || '' );
					self.later( function () { self.tick(); }, Math.max( 1, data.retry_after ) * 1000 );
					break;
				case 'lost':
					self.later( function () { self.poll(); }, POLL_MS );
					break;
				default:
					// completed, failed, finished: nothing more to drive.
					self.stopped = true;
			}
		} );
	};

	Driver.prototype.poll = function () {
		var self = this;
		request( 'GET', this.id, function ( status, data ) {
			if ( ! self.handle( status, data, function () {} ) ) {
				if ( ! self.stopped ) {
					self.later( function () { self.poll(); }, 5000 );
				}
				return;
			}
			if ( TERMINAL.indexOf( data.job.status ) !== -1 ) {
				self.stopped = true;
				return;
			}
			if ( self.lastResult === 'more' && Date.now() - self.lastChange >= WATCHDOG_MS ) {
				// The self-request chain went quiet: bring it back, or give up on it.
				self.watchdogFires += 1;
				if ( self.watchdogFires >= WATCHDOG_MAX_FIRES ) {
					self.loopback = false;
				}
				self.lastChange = Date.now();
				self.tick();
				return;
			}
			self.later( function () { self.poll(); }, POLL_MS );
		} );
	};

	Driver.prototype.action = function ( name, done ) {
		var self = this;
		request( 'POST', this.id + '/' + name, function ( status, data ) {
			done();
			if ( status === 409 && data && data.message ) {
				notice( self.root, data.message );
				return;
			}
			if ( ! self.handle( status, data, function () {} ) ) {
				return;
			}
			notice( self.root, data.message || '' );
			if ( name === 'retry' ) {
				self.stopped = false;
				self.lastResult = null;
				self.tick();
			} else {
				self.stop( data.message || '' );
				self.later( function () { self.poll(); }, POLL_MS );
			}
		} );
	};

	function init() {
		if ( ! config.root ) {
			return;
		}
		var blocks = document.querySelectorAll( '[data-wpcheckpoint-job]' );
		for ( var i = 0; i < blocks.length; i++ ) {
			new Driver( blocks[ i ] );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );

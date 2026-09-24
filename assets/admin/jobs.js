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
 *
 * A job waiting for a decision shows its questions (GET /questions) as a
 * form; the answer is posted and the job ticked. A job that ends sends a
 * "wpcheckpoint:job-finished" event from its block. A finished block can
 * be dismissed; that is remembered in this browser only (a convenience,
 * not state). window.wpcheckpointDriveJob( element ) drives a block added
 * later (the size estimate).
 */
( function () {
	'use strict';

	var config = window.wpcheckpointJobs || {};
	var POLL_MS = 2000;
	var WATCHDOG_MS = 15000;
	var WATCHDOG_MAX_FIRES = 2;
	var TERMINAL = [ 'completed', 'failed', 'cancelled' ];

	var DISMISSED_KEY = 'wpcheckpoint-dismissed-jobs';

	function dismissed() {
		try {
			var list = JSON.parse( window.localStorage.getItem( DISMISSED_KEY ) || '[]' );
			return Array.isArray( list ) ? list : [];
		} catch ( e ) {
			return [];
		}
	}

	function dismiss( id ) {
		try {
			var list = dismissed();
			if ( list.indexOf( id ) === -1 ) {
				list.push( id );
			}
			window.localStorage.setItem( DISMISSED_KEY, JSON.stringify( list.slice( -200 ) ) );
		} catch ( e ) {
			// No storage (private window, blocked): the block is hidden for this page view only.
		}
	}

	function request( method, path, done, body ) {
		var xhr = new XMLHttpRequest();
		xhr.open( method, config.root + 'wp-checkpoint/v1/jobs/' + path, true );
		xhr.setRequestHeader( 'X-WP-Nonce', config.nonce );
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

	// The block's layout: the questions first while the job waits for a decision, what the failure means once it
	// failed, the progress otherwise (JobProgress::state() on the server).
	function state( job ) {
		if ( job.awaiting ) {
			return 'decision';
		}
		return job.status === 'failed' ? 'failed' : 'progress';
	}

	function render( root, job ) {
		var layout = state( job );
		var bar = root.querySelector( '[data-field="progress"]' );
		if ( bar ) {
			bar.value = job.progress;
		}
		root.setAttribute( 'data-status', job.status );
		root.setAttribute( 'data-state', layout );
		setText( root, 'status_text', job.status_text || config.labels[ job.status ] || job.status );
		setText( root, 'type_label', job.type_label );
		setText( root, 'progress_text', job.progress + '%' );
		setText( root, 'step_label', job.step_label );
		setText( root, 'message', job.message );
		setHidden( root.querySelector( '[data-field="progress_box"]' ), layout !== 'progress' );
		setHidden( root.querySelector( '[data-field="failure"]' ), layout !== 'failed' );
		setText( root, 'failure_text', job.failure_text || '' );
		setText( root, 'error_detail', job.error_detail || '' );
		setHidden( root.querySelector( '[data-field="error_detail_line"]' ), ! job.error_detail );
		if ( typeof job.log_tail === 'string' ) {
			setText( root, 'log_tail', job.log_tail );
		}
		var active = [ 'queued', 'running', 'paused' ].indexOf( job.status ) !== -1;
		setHidden( root.querySelector( '[data-action="cancel"]' ), ! active );
		setHidden( root.querySelector( '[data-action="retry"]' ), ! job.retry_useful );
		setHidden( root.querySelector( '[data-action="dismiss"]' ), job.status !== 'failed' );
		setText( root, 'stalled', job.stalled_text || '' );
		setHidden( root.querySelector( '[data-field="stalled"]' ), ! job.stalled_text );
		if ( layout !== 'decision' ) {
			setHidden( root.querySelector( '[data-field="questions"]' ), true );
		}
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
			if ( button.getAttribute( 'data-action' ) === 'dismiss' ) {
				dismiss( self.id );
				setHidden( self.root, true );
				return;
			}
			button.disabled = true;
			self.action( button.getAttribute( 'data-action' ), function () {
				button.disabled = false;
			} );
		} );
	};

	Driver.prototype.finished = function ( job ) {
		if ( this.announced ) {
			return;
		}
		this.announced = true;
		var event;
		try {
			event = new CustomEvent( 'wpcheckpoint:job-finished', { bubbles: true, detail: job } );
		} catch ( e ) {
			event = document.createEvent( 'CustomEvent' );
			event.initCustomEvent( 'wpcheckpoint:job-finished', true, false, job );
		}
		this.root.dispatchEvent( event );
	};

	Driver.prototype.questions = function () {
		var self = this;
		var box = this.root.querySelector( '[data-field="questions"]' );
		if ( ! box || this.asking ) {
			return;
		}
		this.asking = true;
		request( 'GET', this.id + '/questions', function ( status, data ) {
			if ( status !== 200 || ! data || ! data.questions || ! data.questions.length ) {
				self.asking = false;
				return;
			}
			box.textContent = '';
			var form = document.createElement( 'form' );
			var intro = document.createElement( 'p' );
			intro.textContent = config.labels.questions;
			form.appendChild( intro );
			data.questions.forEach( function ( question, n ) {
				var set = document.createElement( 'fieldset' );
				var legend = document.createElement( 'legend' );
				legend.textContent = question.text;
				set.appendChild( legend );
				if ( question.listed && question.listed.length ) {
					var list = document.createElement( 'ul' );
					question.listed.forEach( function ( item ) {
						var li = document.createElement( 'li' );
						li.textContent = item;
						list.appendChild( li );
					} );
					set.appendChild( list );
				}
				question.choices.forEach( function ( choice ) {
					var label = document.createElement( 'label' );
					var input = document.createElement( 'input' );
					input.type = 'radio';
					input.name = 'q' + n;
					input.value = choice;
					input.required = true;
					input.setAttribute( 'data-question', question.id );
					label.appendChild( input );
					label.appendChild( document.createTextNode( ' ' + ( config.labels[ 'choice_' + choice ] || choice ) ) );
					set.appendChild( label );
					set.appendChild( document.createElement( 'br' ) );
				} );
				form.appendChild( set );
			} );
			var submit = document.createElement( 'button' );
			submit.type = 'submit';
			submit.className = 'button button-primary';
			submit.textContent = config.labels.answer;
			form.appendChild( submit );
			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				var answers = {};
				form.querySelectorAll( 'input[data-question]:checked' ).forEach( function ( input ) {
					answers[ input.getAttribute( 'data-question' ) ] = input.value;
				} );
				submit.disabled = true;
				request( 'POST', self.id + '/answer', function ( code, reply ) {
					if ( code !== 200 ) {
						submit.disabled = false;
						notice( self.root, ( reply && reply.message ) || config.labels.answer_failed );
						return;
					}
					box.textContent = '';
					setHidden( box, true );
					self.asking = false;
					self.stopped = false;
					self.lastResult = null;
					render( self.root, reply.job );
					// The answer is taken: say so at once, before the next tick returns (up to a whole budget later).
					self.root.setAttribute( 'data-state', 'progress' );
					setHidden( self.root.querySelector( '[data-field="progress_box"]' ), false );
					setText( self.root, 'status_text', config.labels.running );
					setText( self.root, 'message', config.labels.continuing );
					self.tick();
				}, { answers: answers } );
			} );
			box.appendChild( form );
			setHidden( box, false );
			var first = form.querySelector( 'input' );
			if ( first ) {
				first.focus();
			}
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
		if ( data.job.status === 'paused' && data.job.questions ) {
			this.questions();
		}
		if ( TERMINAL.indexOf( data.job.status ) !== -1 ) {
			this.finished( data.job );
		}
		onJob( data.job );
		return true;
	};

	// While a tick runs, the job's progress is saved at every checkpoint: a read-only GET shows it. Only when the
	// browser drives (no chain polls then), never overlapping, and not while the page is hidden.
	// A read answered after its tick ended is dropped (the tick's own answer is newer), also when the next tick has
	// already started: each watch has its own number.
	Driver.prototype.watch = function () {
		var self = this;
		var round = ( this.round || 0 ) + 1;
		this.round = round;
		window.clearInterval( this.watcher );
		this.watcher = window.setInterval( function () {
			if ( self.reading || document.hidden ) {
				return;
			}
			self.reading = true;
			request( 'GET', self.id, function ( status, data ) {
				self.reading = false;
				if ( status === 200 && data && data.job && self.watcher && self.round === round ) {
					render( self.root, data.job );
				}
			} );
		}, POLL_MS );
	};

	Driver.prototype.unwatch = function () {
		window.clearInterval( this.watcher );
		this.watcher = null;
		this.round = ( this.round || 0 ) + 1;
	};

	Driver.prototype.tick = function () {
		var self = this;
		if ( ! this.loopback ) {
			this.watch();
		}
		request( 'POST', this.id + '/tick', function ( status, data ) {
			self.unwatch();
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
		if ( document.hidden ) {
			// A hidden page does not poll; the server's chain goes on without it. Resume when it is shown.
			document.addEventListener( 'visibilitychange', function resume() {
				if ( ! document.hidden ) {
					document.removeEventListener( 'visibilitychange', resume );
					self.poll();
				}
			} );
			return;
		}
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

	window.wpcheckpointDriveJob = function ( element ) {
		return config.root ? new Driver( element ) : null;
	};

	function init() {
		if ( ! config.root ) {
			return;
		}
		var hidden = dismissed();
		var blocks = document.querySelectorAll( '[data-wpcheckpoint-job]' );
		for ( var i = 0; i < blocks.length; i++ ) {
			var terminal = TERMINAL.indexOf( blocks[ i ].getAttribute( 'data-status' ) ) !== -1;
			if ( terminal && hidden.indexOf( blocks[ i ].getAttribute( 'data-wpcheckpoint-job' ) ) !== -1 ) {
				setHidden( blocks[ i ], true );
				continue;
			}
			new Driver( blocks[ i ] );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );

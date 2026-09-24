/**
 * Browser phases of the Backups tab acceptance (see run.sh): a real
 * browser on the wp-env development site, one phase per run, the login
 * kept in /w/state.json between phases. Each phase prints one JSON line
 * with what it saw; run.sh checks them.
 *
 * Needs the modules "playwright" and "axe-core" in /w/node_modules (run.sh
 * runs this in the Playwright image with /w mounted).
 */
'use strict';

const { chromium } = require( 'playwright' );
const fs = require( 'fs' );

const SITE = process.env.WPC_URL || 'http://localhost:9888';
const TAB = SITE + '/wp-admin/admin.php?page=wp-checkpoint&tab=backups';
const STATE = '/w/state.json';
const AXE = '/w/node_modules/axe-core/axe.min.js';

function out( result ) {
	process.stdout.write( JSON.stringify( result ) + '\n' );
}

async function axe( page ) {
	await page.addScriptTag( { path: AXE } );
	const result = await page.evaluate( async () => window.axe.run( { include: [ [ '.wpcheckpoint-tab-content' ] ] }, { runOnly: [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ] } ) );
	return result.violations.map( ( v ) => v.id + ' (' + v.nodes.length + ')' );
}

// Which of a job block's buttons a person actually sees (computed style, not the hidden attribute).
// What a job block shows: its layout, status and which buttons can be seen.
// Defined in every page (see the context below) so the page itself can take
// it at the moment a job finishes, before the tab reloads.
function snapshotScript() {
	window.wpcSnapshot = ( block ) => {
		const seen = ( sel ) => {
			const el = block.querySelector( sel );
			return !! el && getComputedStyle( el ).display !== 'none' && el.getClientRects().length > 0;
		};
		return {
			state: block.getAttribute( 'data-state' ),
			status: ( block.querySelector( '[data-field="status_text"]' ) || {} ).textContent || '',
			cancel: seen( '[data-action="cancel"]' ),
			retry: seen( '[data-action="retry"]' ),
			dismiss: seen( '[data-action="dismiss"]' ),
			progress: seen( '[data-field="progress_box"]' ),
			questions: seen( '[data-field="questions"]' ),
			failure: ( block.querySelector( '[data-field="failure"]' ) || {} ).innerText || '',
		};
	};
	// The block as it stands when its job ends (the Backups tab reloads right after).
	document.addEventListener( 'wpcheckpoint:job-finished', ( event ) => {
		if ( window.wpcReportFinished ) {
			window.wpcReportFinished( Object.assign( { id: String( event.detail.id ) }, window.wpcSnapshot( event.target ) ) );
		}
	}, true );
}

async function buttons( page, id ) {
	return page.evaluate( ( jobId ) => {
		const block = document.querySelector( '[data-wpcheckpoint-job="' + jobId + '"]' );
		return block ? window.wpcSnapshot( block ) : null;
	}, id );
}

async function text( page ) {
	return ( await page.textContent( '.wpcheckpoint-tab-content' ) ).replace( /\s+/g, ' ' );
}

async function waitFor( page, test, seconds ) {
	const until = Date.now() + seconds * 1000;
	while ( Date.now() < until ) {
		if ( await test() ) {
			return true;
		}
		await page.waitForTimeout( 1000 );
	}
	return false;
}

const phases = {
	async login( browser ) {
		const context = await browser.newContext();
		await context.addInitScript( snapshotScript );
		const finished = [];
		await context.exposeFunction( 'wpcReportFinished', ( snap ) => finished.push( snap ) );
		const page = await context.newPage();
		page.finishedBlocks = finished;
		await page.goto( SITE + '/wp-login.php' );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'password' );
		await page.click( '#wp-submit' );
		await page.waitForURL( /wp-admin/ );
		await context.storageState( { path: STATE } );
		return { ok: true };
	},

	async empty( page ) {
		await page.goto( TAB );
		const before = await text( page );
		const state = await page.getAttribute( '[data-wpcheckpoint-estimate]', 'data-state' );
		// The script starts the estimate; when its job ends the tab reloads with the counts.
		const counted = await waitFor( page, async () => /Files: [\d,.]+ files?, /.test( await text( page ) ), 240 );
		const after = await text( page );
		return {
			empty: before.includes( 'No backups yet' ),
			first: before.includes( 'Create your first backup' ),
			database: /Database: about [\d.,]+ [KMG]?B/.test( before ),
			state,
			counted,
			files: ( after.match( /Files: [^.]+\./ ) || [ '' ] )[ 0 ],
			time: ( after.match( /A full backup took about [^.]+\./ ) || [ '' ] )[ 0 ],
			axe: await axe( page ),
		};
	},

	async create( page, tables ) {
		await page.goto( TAB );
		await page.click( '[data-wpcheckpoint-create] details summary' );
		await page.fill( '#wpcheckpoint-exclusions', 'wp-content/uploads/acceptance/*\nwp-content/plugins/wp-checkpoint/*' );
		await page.fill( '#wpcheckpoint-exclude-tables', ( tables || '' ).split( ',' ).join( '\n' ) );
		// Keyboard: focus the button and press Enter, as someone without a mouse would.
		await page.focus( '[data-wpcheckpoint-create] button[type="submit"]' );
		await page.keyboard.press( 'Enter' );
		await page.waitForSelector( '[data-wpcheckpoint-job][data-type="export"]', { timeout: 60000 } );
		const job = await page.getAttribute( '[data-wpcheckpoint-job][data-type="export"]', 'data-wpcheckpoint-job' );
		const running = await buttons( page, job );
		// The heavy directory raises a question: answer it on the page.
		const asked = await waitFor( page, async () => ( await page.$( '[data-field="questions"]:not([hidden]) form' ) ) !== null, 240 );
		let question = '';
		let decision = null;
		let first = false;
		let answered = null;
		if ( asked ) {
			decision = await buttons( page, job );
			// The question is the first thing in the block, above anything about progress.
			first = await page.evaluate( ( jobId ) => {
				const block = document.querySelector( '[data-wpcheckpoint-job="' + jobId + '"]' );
				const q = block.querySelector( '[data-field="questions"]' );
				const bar = block.querySelector( '[data-field="progress_box"]' );
				return !! ( q.compareDocumentPosition( bar ) & Node.DOCUMENT_POSITION_FOLLOWING );
			}, job );
			question = ( await page.textContent( '[data-field="questions"] legend' ) ).trim();
			for ( const input of await page.$$( '[data-field="questions"] input[value="exclude"]' ) ) {
				await input.check();
			}
			await page.click( '[data-field="questions"] button[type="submit"]' );
			await page.waitForTimeout( 1000 );
			answered = await buttons( page, job );
			answered.message = ( await page.textContent( '[data-wpcheckpoint-job="' + job + '"] [data-field="message"]' ) ).trim();
		}
		const done = await waitFor( page, async () => /[?&]job=\d+/.test( page.url() ) && ( await page.$( '.wpcheckpoint-highlight' ) ) !== null, 600 );
		return {
			job,
			running,
			asked,
			question,
			decision,
			question_first: first,
			answered,
			done,
			completed: page.finishedBlocks.find( ( b ) => b.id === job ) || null,
			finished_block_shown: done ? ( await page.$( '[data-wpcheckpoint-job="' + job + '"]' ) ) !== null : null,
			highlighted: done ? ( await page.textContent( '.wpcheckpoint-highlight' ) ).includes( 'New' ) : false,
			axe: await axe( page ),
		};
	},

	async details( page ) {
		await page.goto( TAB );
		await page.click( 'tbody tr:first-child a.button' );
		await page.waitForURL( /backup=/ );
		const body = await text( page );
		const link = await page.$( 'table tbody tr:first-child td a' );
		const name = ( await link.textContent() ).trim();
		const href = await link.getAttribute( 'href' );
		const response = await page.request.get( href );
		const content = await response.body();
		const sha = require( 'crypto' ).createHash( 'sha256' ).update( content ).digest( 'hex' );
		const shown = ( await page.textContent( 'table tbody tr:first-child code' ) || '' ).trim();
		await page.click( 'button[data-backup-action="verify"]' );
		const checked = await waitFor( page, async () => /Last check \(/.test( await text( page ) ), 300 );
		return {
			holds: body.includes( 'Database: each table as it was when its export started' ),
			files_text: body.includes( 'Files: scanned and packed while the backup was made' ),
			help: body.includes( 'What a backup contains, and when' ),
			download: { name, status: response.status(), bytes: content.length, sha_matches: sha === shown },
			checked,
			check: ( ( await text( page ) ).match( /Last check \([^)]*\): [^.]+\./ ) || [ '' ] )[ 0 ],
			axe: await axe( page ),
		};
	},

	async 'start-db'( page, tables ) {
		await page.goto( TAB );
		await page.click( '[data-wpcheckpoint-create] details summary' );
		await page.check( 'input[name="contents"][value="database"]' );
		await page.fill( '#wpcheckpoint-exclude-tables', ( tables || '' ).split( ',' ).join( '\n' ) );
		await page.click( '[data-wpcheckpoint-create] button[type="submit"]' );
		await page.waitForSelector( '[data-wpcheckpoint-job][data-type="export"][data-status="queued"], [data-wpcheckpoint-job][data-type="export"][data-status="running"]', { timeout: 60000 } );
		const blocks = await page.$$( '[data-wpcheckpoint-job][data-type="export"]' );
		const job = await blocks[ 0 ].getAttribute( 'data-wpcheckpoint-job' );
		return { job }; // The page is closed right away: nothing on it drives the job from now on.
	},

	async reopen( page, id ) {
		await page.goto( TAB );
		const block = await page.$( '[data-wpcheckpoint-job="' + id + '"]' );
		return {
			// Finished while the page was closed: not among the jobs, its backup in the list.
			block_shown: null !== block,
			status: block ? await block.getAttribute( 'data-status' ) : '',
			backups_listed: ( await page.$$( 'tbody tr[data-backup]' ) ).length,
		};
	},

	async stalled( page, id ) {
		await page.goto( TAB );
		const selector = '[data-wpcheckpoint-job="' + id + '"] [data-field="stalled"]';
		const shown = await page.isVisible( selector );
		const said = shown ? ( await page.textContent( selector ) ).trim() : '';
		await page.evaluate( () => { window.wpcBeforeCancel = true; } );
		await page.click( '[data-wpcheckpoint-job="' + id + '"] [data-action="cancel"]' );
		// Cancelled, then the tab reloads by itself without the block.
		await waitFor( page, async () => page.finishedBlocks.some( ( b ) => b.id === String( id ) ), 60 );
		const ended = page.finishedBlocks.find( ( b ) => b.id === String( id ) );
		const reloaded = await waitFor( page, async () => {
			try {
				return await page.evaluate( () => ! window.wpcBeforeCancel && 'complete' === document.readyState );
			} catch ( error ) {
				return false; // Navigating.
			}
		}, 60 );
		return {
			shown,
			said,
			cancelled: ended ? ended.status : null,
			block_after_reload: reloaded ? null !== await page.$( '[data-wpcheckpoint-job="' + id + '"]' ) : null,
		};
	},

	// A backup that stops on its question: run.sh removes its work directory, then the answer makes it fail.
	async 'to-question'( page ) {
		await page.goto( TAB );
		await page.click( '[data-wpcheckpoint-create] button[type="submit"]' );
		await page.waitForSelector( '[data-field="questions"] form', { timeout: 300000 } );
		return { job: await page.getAttribute( '[data-wpcheckpoint-job][data-state="decision"]', 'data-wpcheckpoint-job' ) };
	},

	async 'fail-answer'( page, id ) {
		await page.goto( TAB );
		await page.waitForSelector( '[data-field="questions"] form', { timeout: 60000 } );
		for ( const input of await page.$$( '[data-field="questions"] input[value="exclude"]' ) ) {
			await input.check(); // Every question: an answer is required for each.
		}
		await page.evaluate( () => { window.wpcBeforeFailure = true; } );
		await page.click( '[data-field="questions"] button[type="submit"]' );
		// The block as it is when the job fails, then the tab the page reloads to by itself (the marker is gone).
		await waitFor( page, async () => page.finishedBlocks.some( ( b ) => b.id === String( id ) ), 120 );
		const live = page.finishedBlocks.find( ( b ) => b.id === String( id ) );
		const reloaded = await waitFor( page, async () => {
			try {
				return await page.evaluate( () => ! window.wpcBeforeFailure && 'complete' === document.readyState );
			} catch ( error ) {
				return false; // Navigating.
			}
		}, 60 );
		return {
			says_final: !! live && live.failure.includes( 'retrying would fail the same way' ),
			live: live || null,
			reloaded: reloaded ? await buttons( page, id ) : null,
			create: reloaded ? await page.evaluate( () => ( {
				disabled: document.querySelector( '[data-wpcheckpoint-create] button[type="submit"]' ).disabled,
				reason: document.querySelector( '[data-wpcheckpoint-create] [data-field="form_status"]' ).textContent.trim(),
			} ) ) : null,
			axe: await axe( page ),
		};
	},

	async failed( page, id ) {
		await page.goto( TAB );
		const block = await buttons( page, id );
		// The kind is read, not guessed: the temporary text, not the text for a failure of unknown cause.
		return { block, says_temporary: !! block && block.failure.includes( 'a problem on the server that may pass' ) };
	},

	// A long tick (a large table, the browser driving): the block moves while the tick runs, the reads are not
	// held up behind it, and a hidden page stops reading.
	async polling( page ) {
		const events = [];
		page.on( 'request', ( r ) => { if ( /\/jobs\/\d+(\/tick)?$/.test( r.url() ) ) { events.push( { at: Date.now(), kind: r.method() + ( r.url().endsWith( '/tick' ) ? ' tick' : ' read' ), phase: 'sent' } ); } } );
		page.on( 'response', ( r ) => { if ( /\/jobs\/\d+(\/tick)?$/.test( r.url() ) ) { events.push( { at: Date.now(), kind: r.request().method() + ( r.url().endsWith( '/tick' ) ? ' tick' : ' read' ), phase: 'done' } ); } } );
		await page.goto( TAB );
		await page.click( '[data-wpcheckpoint-create] details summary' );
		await page.check( 'input[name="contents"][value="database"]' );
		const before = await page.$$eval( '[data-wpcheckpoint-job]', ( els ) => els.map( ( e ) => e.getAttribute( 'data-wpcheckpoint-job' ) ) );
		await page.click( '[data-wpcheckpoint-create] button[type="submit"]' );
		// The new export's block, not one left from an earlier phase.
		const handle = await page.waitForFunction( ( old ) => {
			const el = Array.from( document.querySelectorAll( '[data-wpcheckpoint-job][data-type="export"]' ) ).find( ( e ) => ! old.includes( e.getAttribute( 'data-wpcheckpoint-job' ) ) );
			return el ? el.getAttribute( 'data-wpcheckpoint-job' ) : null;
		}, before, { timeout: 60000 } );
		const id = await handle.jsonValue();
		const seen = [];
		const block = '[data-wpcheckpoint-job="' + id + '"]';
		// The progress line as shown; null once the block is gone (the page reloads when the backup is done).
		const message = async () => {
			for ( let attempt = 0; attempt < 3; attempt++ ) {
				try {
					const el = await page.$( block + ' .wpcheckpoint-job-line' );
					return el ? ( await el.textContent() ).replace( /\s+/g, ' ' ).trim() : null;
				} catch ( error ) {
					// The tab is reloading (the backup ended): read the new page.
					await page.waitForLoadState( 'load' );
				}
			}
			return null;
		};
		const watch = async ( ms ) => {
			for ( const end = Date.now() + ms; Date.now() < end; ) {
				const m = await message();
				if ( null === m ) {
					return false;
				}
				if ( ! seen.length || seen[ seen.length - 1 ] !== m ) {
					seen.push( m );
				}
				await page.waitForTimeout( 250 );
			}
			return true;
		};
		await watch( 6000 );
		// Hide the page for 6 seconds while the backup goes on: ticks go on, reads stop.
		await page.evaluate( () => { Object.defineProperty( document, 'hidden', { configurable: true, get: () => true } ); document.dispatchEvent( new Event( 'visibilitychange' ) ); } );
		const hiddenFrom = Date.now();
		await page.waitForTimeout( 6000 );
		const hiddenUntil = Date.now();
		const stillRunning = null !== await message();
		await page.evaluate( () => { Object.defineProperty( document, 'hidden', { configurable: true, get: () => false } ); document.dispatchEvent( new Event( 'visibilitychange' ) ); } );
		// Visible again: follow it to the end (the block goes away when the page reloads on completion).
		await watch( 240000 );
		const ticks = events.filter( ( e ) => e.kind === 'POST tick' );
		const reads = events.filter( ( e ) => e.kind === 'GET read' && e.phase === 'done' && e.at < hiddenFrom );
		// A read answered while a tick was still running: sent after a tick was sent and before that tick was answered.
		let during = 0;
		const sent = ticks.filter( ( e ) => e.phase === 'sent' );
		const done = ticks.filter( ( e ) => e.phase === 'done' );
		sent.forEach( ( t, n ) => {
			const end = done[ n ] ? done[ n ].at : Date.now();
			during += reads.filter( ( r ) => r.at > t.at && r.at < end ).length;
		} );
		const readLatency = [];
		events.filter( ( e ) => e.kind === 'GET read' && e.phase === 'sent' && e.at < hiddenFrom ).forEach( ( s0 ) => {
			const d = events.find( ( e ) => e.kind === 'GET read' && e.phase === 'done' && e.at >= s0.at );
			if ( d ) { readLatency.push( d.at - s0.at ); }
		} );
		const inHidden = ( e ) => e.phase === 'sent' && e.at > hiddenFrom + 100 && e.at < hiddenUntil;
		const readsWhileHidden = events.filter( ( e ) => e.kind === 'GET read' && inHidden( e ) ).length;
		// The control: the backup was being driven while hidden (a tick in flight at some point), so there was something to read.
		let ticksWhileHidden = 0;
		sent.forEach( ( t, n ) => {
			const end = done[ n ] ? done[ n ].at : Date.now();
			ticksWhileHidden += t.at < hiddenUntil && end > hiddenFrom ? 1 : 0;
		} );
		const readsAfterShown = events.filter( ( e ) => e.kind === 'GET read' && e.phase === 'sent' && e.at > hiddenUntil ).length;
		return {
			job: id,
			messages: seen,
			ticks: done.length,
			reads_during_ticks: during,
			slowest_read_ms: readLatency.length ? Math.max( ...readLatency ) : null,
			reads_while_hidden: readsWhileHidden,
			ticks_while_hidden: ticksWhileHidden,
			running_after_hidden: stillRunning,
			reads_after_shown: readsAfterShown,
		};
	},

	async delete( page ) {
		await page.goto( TAB );
		const before = ( await page.$$( 'tbody tr[data-backup]' ) ).length;
		const base = await page.getAttribute( 'tbody tr[data-backup]', 'data-backup' );
		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await page.click( 'tbody tr[data-backup="' + base + '"] [data-backup-action="delete"]' );
		await page.waitForLoadState( 'load' );
		await waitFor( page, async () => ( await page.$( 'tbody tr[data-backup="' + base + '"]' ) ) === null, 30 );
		const after = ( await page.$$( 'tbody tr[data-backup]' ) ).length;
		return { before, after, gone: null === await page.$( 'tbody tr[data-backup="' + base + '"]' ) };
	},
};

( async () => {
	const [ phase, arg ] = process.argv.slice( 2 );
	const browser = await chromium.launch();
	try {
		if ( 'login' === phase ) {
			out( await phases.login( browser ) );
			return;
		}
		const context = await browser.newContext( { storageState: fs.existsSync( STATE ) ? STATE : undefined } );
		await context.addInitScript( snapshotScript );
		const finished = [];
		await context.exposeFunction( 'wpcReportFinished', ( snap ) => finished.push( snap ) );
		const page = await context.newPage();
		page.finishedBlocks = finished;
		out( Object.assign( { phase }, await phases[ phase ]( page, arg ) ) );
	} finally {
		await browser.close();
	}
} )().catch( ( error ) => {
	process.stdout.write( JSON.stringify( { error: String( error.message ).split( '\n' )[ 0 ] } ) + '\n' );
	process.exit( 1 );
} );

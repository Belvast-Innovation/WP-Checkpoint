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
		const page = await context.newPage();
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
		// The heavy directory raises a question: answer it on the page.
		const asked = await waitFor( page, async () => ( await page.$( '[data-field="questions"]:not([hidden]) form' ) ) !== null, 240 );
		let question = '';
		if ( asked ) {
			question = ( await page.textContent( '[data-field="questions"] legend' ) ).trim();
			await page.check( '[data-field="questions"] input[value="exclude"]' );
			await page.click( '[data-field="questions"] button[type="submit"]' );
		}
		const done = await waitFor( page, async () => /[?&]job=\d+/.test( page.url() ) && ( await page.$( '.wpcheckpoint-highlight' ) ) !== null, 600 );
		return {
			job,
			asked,
			question,
			done,
			highlighted: done ? ( await page.textContent( '.wpcheckpoint-highlight' ) ).includes( 'New' ) : false,
			ready: ( await text( page ) ).includes( 'The backup is ready.' ),
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
			shown: null !== block,
			status: block ? await block.getAttribute( 'data-status' ) : '',
			outcome: block ? ( ( await block.textContent() ).match( /The backup is ready\.|The job completed[^.]*\./ ) || [ '' ] )[ 0 ] : '',
		};
	},

	async stalled( page, id ) {
		await page.goto( TAB );
		const selector = '[data-wpcheckpoint-job="' + id + '"] [data-field="stalled"]';
		const shown = await page.isVisible( selector );
		const said = shown ? ( await page.textContent( selector ) ).trim() : '';
		await page.click( '[data-wpcheckpoint-job="' + id + '"] [data-action="cancel"]' );
		const cancelled = await waitFor( page, async () => 'cancelled' === await page.getAttribute( '[data-wpcheckpoint-job="' + id + '"]', 'data-status' ), 60 );
		return { shown, said, cancelled };
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
		const page = await context.newPage();
		out( Object.assign( { phase }, await phases[ phase ]( page, arg ) ) );
	} finally {
		await browser.close();
	}
} )().catch( ( error ) => {
	process.stdout.write( JSON.stringify( { error: String( error.message ).split( '\n' )[ 0 ] } ) + '\n' );
	process.exit( 1 );
} );

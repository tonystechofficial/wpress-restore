// Drives the admin page in headless Chrome over the DevTools protocol, as a person would:
// log in, upload a backup with the page's uploader, restore it, then log in with the backup's account.
//   node dev/ui-test.mjs <backup.wpress> <user> <password> <backup user> <backup password> <screenshot folder>
// Uses the installed Chrome with a throwaway profile. Prints what the page showed and any script errors.
import { spawn } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, join, resolve } from 'node:path';

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const SITE = 'http://localhost:8082';
const PORT = 9333;
const [backup, user, password, backupUser, backupPassword, shots] = process.argv.slice(2);

const profile = mkdtempSync(join(tmpdir(), 'wpr-chrome-'));
const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', 'about:blank'], { stdio: 'ignore' });
const sleep = (ms) => new Promise((done) => setTimeout(done, ms));

async function until(test, timeout, what) {
	const end = Date.now() + timeout;
	while (Date.now() < end) {
		if (await test()) return;
		await sleep(250);
	}
	throw new Error(`Timed out waiting for ${what}`);
}

let target;
await until(async () => {
	try {
		target = (await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json()).find((t) => t.type === 'page');
		return Boolean(target);
	} catch {
		return false;
	}
}, 20000, 'Chrome');

const ws = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((open) => ws.addEventListener('open', open));
let lastId = 0;
const pending = new Map();
const listeners = new Set();
const problems = [];
ws.addEventListener('message', (event) => {
	const message = JSON.parse(event.data);
	if (message.id && pending.has(message.id)) {
		pending.get(message.id)(message);
		pending.delete(message.id);
		return;
	}
	if (message.method === 'Runtime.exceptionThrown') problems.push(message.params.exceptionDetails.exception?.description ?? message.params.exceptionDetails.text);
	if (message.method === 'Runtime.consoleAPICalled' && message.params.type === 'error') problems.push(message.params.args.map((a) => a.value ?? a.description).join(' '));
	if (message.method === 'Page.javascriptDialogOpening') {
		console.log(`  dialog: "${message.params.message}" -> OK`);
		send('Page.handleJavaScriptDialog', { accept: true });
	}
	for (const listener of listeners) listener(message);
});

function send(method, params = {}) {
	return new Promise((done, fail) => {
		const id = ++lastId;
		pending.set(id, (m) => (m.error ? fail(new Error(`${method}: ${JSON.stringify(m.error)}`)) : done(m.result)));
		ws.send(JSON.stringify({ id, method, params }));
	});
}

function nextLoad() {
	return new Promise((done) => {
		const listener = (m) => {
			if (m.method === 'Page.loadEventFired') {
				listeners.delete(listener);
				done();
			}
		};
		listeners.add(listener);
	});
}

async function run(expression) {
	const result = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
	if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description ?? result.exceptionDetails.text);
	return result.result.value;
}

const text = (selector) => run(`(document.querySelector(${JSON.stringify(selector)}) || {}).innerText || ''`);

async function go(url) {
	const loaded = nextLoad();
	await send('Page.navigate', { url });
	await loaded;
}

async function shot(name) {
	const { data } = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
	writeFileSync(join(shots, `${name}.png`), Buffer.from(data, 'base64'));
}

async function logIn(login, pass) {
	await go(`${SITE}/wp-login.php`);
	const loaded = nextLoad();
	await run(`document.getElementById('user_login').value = ${JSON.stringify(login)}; document.getElementById('user_pass').value = ${JSON.stringify(pass)}; document.getElementById('wp-submit').click(); true`);
	await loaded;
}

try {
	await send('Page.enable');
	await send('Runtime.enable');
	await send('DOM.enable');
	await send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 1000, deviceScaleFactor: 1, mobile: false });

	await logIn(user, password);
	await go(`${SITE}/wp-admin/tools.php?page=wpress-restore`);
	await shot('1-page');
	console.log('page:', (await text('.wpress-restore')).split('\n').slice(0, 4).join(' | '));

	const { root } = await send('DOM.getDocument');
	const { nodeId } = await send('DOM.querySelector', { nodeId: root.nodeId, selector: '#wpr-file' });
	await send('DOM.setFileInputFiles', { nodeId, files: [resolve(backup)] });
	await run(`document.getElementById('wpr-file').dispatchEvent(new Event('change')); document.getElementById('wpr-send').click(); true`);
	let lastStatus = '';
	await until(async () => {
		const status = await text('#wpr-upload-status');
		if (status !== lastStatus && /Uploading/.test(status) && lastStatus === '') console.log('upload:', status);
		lastStatus = status;
		return /is on the server|stopped/.test(status);
	}, 300000, 'the upload');
	console.log('upload:', lastStatus);
	await shot('2-uploaded');

	const name = basename(backup);
	await run(`[...document.querySelectorAll('#wpr-backups tr')].find((row) => row.innerText.includes(${JSON.stringify(name)})).querySelector('.button-primary').click(); true`);
	await until(async () => /Check before restoring|stopped/.test(await text('#wpr-job')), 120000, 'the summary');
	await shot('3-summary');
	console.log('summary:\n  ' + (await text('#wpr-job')).split('\n').filter(Boolean).join('\n  '));

	await run(`document.getElementById('wpr-agree').click(); [...document.querySelectorAll('#wpr-job button')].find((b) => b.innerText === 'Restore now').click(); true`);
	await sleep(400);
	await shot('4-progress');
	await until(async () => /The site is restored|stopped/.test(await text('#wpr-job')), 600000, 'the restore');
	await shot('5-done');
	console.log('done:\n  ' + (await text('#wpr-job')).split('\n').filter(Boolean).join('\n  '));

	const loginLink = await run(`(document.querySelector('#wpr-job a.button-primary') || {}).href || ''`);
	console.log('log in link:', loginLink);
	await logIn(backupUser, backupPassword);
	await go(`${SITE}/wp-admin/tools.php?page=wpress-restore`);
	await shot('6-after-login');
	console.log('after logging in again:\n  ' + (await text('#wpr-job')).split('\n').filter(Boolean).join('\n  '));
	console.log('site title now:', await run(`document.getElementById('wp-admin-bar-site-name')?.innerText ?? ''`));
} catch (error) {
	console.log('ERROR:', error.message);
	await shot('error').catch(() => {});
	process.exitCode = 1;
} finally {
	console.log('script errors on the pages:', problems.length ? problems : 'none');
	ws.close();
	chrome.kill();
	await sleep(500);
	rmSync(profile, { recursive: true, force: true });
}

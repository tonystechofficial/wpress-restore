// Headless Chrome over the DevTools protocol, for the admin page tests: the installed Chrome with a
// throwaway profile; the page's script errors are collected, and dialogs answered OK and recorded.
import { spawn } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';

export const sleep = (ms) => new Promise((done) => setTimeout(done, ms));

export async function until(test, timeout, what) {
	const end = Date.now() + timeout;
	while (Date.now() < end) {
		if (await test()) return;
		await sleep(250);
	}
	throw new Error(`Timed out waiting for ${what}`);
}

export async function openChrome(site, shots, port = 9333) {
	const profile = mkdtempSync(join(tmpdir(), 'wpr-chrome-'));
	const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', 'about:blank'], { stdio: 'ignore' });

	let target;
	await until(async () => {
		try {
			target = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === 'page');
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
	const dialogs = [];

	function send(method, params = {}) {
		return new Promise((done, fail) => {
			const id = ++lastId;
			pending.set(id, (m) => (m.error ? fail(new Error(`${method}: ${JSON.stringify(m.error)}`)) : done(m.result)));
			ws.send(JSON.stringify({ id, method, params }));
		});
	}

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
			dialogs.push(message.params.message);
			send('Page.handleJavaScriptDialog', { accept: true });
		}
		for (const listener of listeners) listener(message);
	});

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
		await go(`${site}/wp-login.php`);
		const loaded = nextLoad();
		await run(`document.getElementById('user_login').value = ${JSON.stringify(login)}; document.getElementById('user_pass').value = ${JSON.stringify(pass)}; document.getElementById('wp-submit').click(); true`);
		await loaded;
	}

	async function close() {
		ws.close();
		chrome.kill();
		await sleep(500);
		rmSync(profile, { recursive: true, force: true });
	}

	await send('Page.enable');
	await send('Runtime.enable');
	await send('DOM.enable');
	await send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 1000, deviceScaleFactor: 1, mobile: false });
	return { send, run, text, go, shot, logIn, problems, dialogs, close };
}

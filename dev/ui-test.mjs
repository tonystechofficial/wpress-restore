// Drives the admin page in headless Chrome, as a person would: log in, upload a backup with the
// page's uploader, restore it, then log in with the backup's account.
//   node dev/ui-test.mjs <backup.wpress> <user> <password> <backup user> <backup password> <screenshot folder>
// Prints what the page showed and any script errors.
import { basename, resolve } from 'node:path';
import { openChrome, sleep, until } from './chrome.mjs';

const SITE = 'http://localhost:8082';
const [backup, user, password, backupUser, backupPassword, shots] = process.argv.slice(2);
const page = await openChrome(SITE, shots);
const { send, run, text, go, shot, logIn } = page;

try {
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
	console.log('script errors on the pages:', page.problems.length ? page.problems : 'none');
	await page.close();
}

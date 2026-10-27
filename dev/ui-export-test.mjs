// Drives the "Create a backup" panel in headless Chrome, as a person would: a mistyped password
// is caught, a backup is made with options, its Download link serves the file, Close clears it.
//   node dev/ui-export-test.mjs <site> <user> <password> <screenshot folder>
// Prints what the page showed and any script errors.
import { openChrome, sleep, until } from './chrome.mjs';

const [site, user, password, shots] = process.argv.slice(2);
const page = await openChrome(site, shots, 9334);
const { run, text, go, shot, logIn } = page;
const lines = async (selector) => '\n  ' + (await text(selector)).split('\n').filter(Boolean).join('\n  ');

try {
	await logIn(user, password);
	await go(`${site}/wp-admin/tools.php?page=wpress-restore`);
	await shot('export-1-page');
	console.log('create panel:', await lines('.wpr-create'));
	console.log('compression choices:', await run(`[...document.querySelectorAll('#wpr-compression option')].map((o) => o.value + (o.disabled ? ' (disabled)' : '')).join(', ')`));

	await run(`document.getElementById('wpr-export-password').value = 'one'; document.getElementById('wpr-export-password2').value = 'two'; document.getElementById('wpr-create').click(); true`);
	await sleep(500);
	console.log('mistyped password started a job:', !(await run(`document.getElementById('wpr-job').hidden`)));

	await run(`document.getElementById('wpr-export-password').value = ''; document.getElementById('wpr-export-password2').value = '';
		document.getElementById('wpr-no-spam').click(); document.getElementById('wpr-no-cache').click();
		document.getElementById('wpr-compression').value = 'gzip'; document.getElementById('wpr-create').click(); true`);
	await until(async () => /Creating|Backup created|stopped/.test(await text('#wpr-job')), 30000, 'the job panel');
	await shot('export-2-progress');
	console.log('while running, create button disabled:', await run(`document.getElementById('wpr-create').disabled`));
	await until(async () => /The backup is ready|stopped/.test(await text('#wpr-job')), 600000, 'the backup');
	await shot('export-3-done');
	console.log('done:', await lines('#wpr-job'));

	const link = await run(`(() => { const a = document.querySelector('#wpr-job a.button-primary'); return a ? { href: a.href, download: a.getAttribute('download') } : null; })()`);
	console.log('download link:', link);
	const response = await fetch(link.href);
	const body = Buffer.from(await response.arrayBuffer());
	const listed = await run(`window.wpressRestore && true`);
	console.log(`download: HTTP ${response.status}, ${response.headers.get('content-type')}, ${body.length} bytes, starts with package.json: ${body.subarray(0, 12).toString() === 'package.json'}`, listed ? '' : '');

	const row = await run(`[...document.querySelectorAll('#wpr-backups tr')].map((r) => r.innerText.replace(/\\s+/g, ' ')).find((t) => t.includes(${JSON.stringify(link.download)})) || ''`);
	console.log('in the list:', row);
	await run(`[...document.querySelectorAll('#wpr-job button')].find((b) => b.innerText === 'Close').click(); true`);
	await until(async () => run(`document.getElementById('wpr-job').hidden`), 10000, 'the panel to close');
	console.log('after Close, create button enabled:', !(await run(`document.getElementById('wpr-create').disabled`)));
	await shot('export-4-closed');
	if (page.dialogs[0] !== 'The two passwords are not the same.') throw new Error('the mistyped password was not caught');
} catch (error) {
	console.log('ERROR:', error.message);
	await shot('export-error').catch(() => {});
	process.exitCode = 1;
} finally {
	console.log('script errors on the pages:', page.problems.length ? page.problems : 'none');
	await page.close();
}

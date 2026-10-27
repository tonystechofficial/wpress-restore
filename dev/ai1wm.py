#!/usr/bin/env python3
"""Drives All-in-One WP Migration itself on a test site, as its admin pages do: one AJAX request
per step, each answering with the parameters of the next. Used to check that both plugins read
each other's backups.

    python dev/ai1wm.py --site http://localhost:8082 --cli cli-b import backup.wpress [--secret pw]
    python dev/ai1wm.py --site http://localhost:8081 --cli cli-a export

Import restores a backup from the backups folder (ai1wm's "Restore" in the backups list); export
writes one there. Run from dev/: the secret key is read with WP-CLI in the --cli container.
"""
import argparse
import json
import secrets
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request


def wp(cli, *args):
    command = ['docker', 'compose', 'exec', '-T', cli, 'wp'] + list(args)
    return subprocess.run(command, capture_output=True, text=True, check=True).stdout.strip()


def flatten(params, prefix=''):
    """PHP-style form fields: nested arrays become key[sub]=value."""
    fields = []
    for key, value in params.items():
        name = f'{prefix}[{key}]' if prefix else str(key)
        if isinstance(value, dict):
            fields += flatten(value, name)
        elif isinstance(value, list):
            fields += flatten({str(i): v for i, v in enumerate(value)}, name)
        elif value is True:
            fields.append((name, '1'))
        elif value is not None and value is not False:
            fields.append((name, str(value)))
    return fields


def run(site, action, params):
    url = f'{site}/wp-admin/admin-ajax.php?action={action}&{action}=1'
    started, requests = time.time(), 0
    while True:
        body = urllib.parse.urlencode(flatten(params)).encode()
        try:
            with urllib.request.urlopen(urllib.request.Request(url, body), timeout=600) as response:
                text = response.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as error:
            text = error.read().decode('utf-8', 'replace')
            print(f'HTTP {error.code}: {text[:800]}')
            return False
        requests += 1
        try:
            answer = json.loads(text)
        except ValueError:
            # The last step returns nothing: admin-ajax answers "0".
            print(f'finished after {requests} requests, {time.time() - started:.1f}s (last answer {text[:80]!r})')
            return True
        if not isinstance(answer, dict):
            print(f'unexpected answer: {text[:800]}')
            return False
        if answer.get('errors'):
            print('errors:', json.dumps(answer['errors']))
            return False
        params = answer


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--site', required=True)
    parser.add_argument('--cli', required=True)
    parser.add_argument('command', choices=['import', 'export'])
    parser.add_argument('file', nargs='?')
    parser.add_argument('--secret', help='password of an encrypted backup')
    args = parser.parse_args()

    key = wp(args.cli, 'option', 'get', 'ai1wm_secret_key')
    storage = secrets.token_hex(7)[:13]
    if args.command == 'import':
        params = {
            'secret_key': key, 'archive': args.file, 'storage': storage, 'priority': 10,
            'ai1wm_manual_restore': 1, 'ai1wm_confirmed': 1,
        }
        if args.secret:
            params['decryption_password'] = args.secret
        ok = run(args.site, 'ai1wm_import', params)
    else:
        ok = run(args.site, 'ai1wm_export', {'secret_key': key, 'storage': storage, 'ai1wm_manual_export': 1, 'options': {}})
    status = wp(args.cli, 'option', 'get', 'ai1wm_status_' + storage, '--format=json') if ok else ''
    print('status:', status[:1500])
    sys.exit(0 if ok and '"error"' not in status else 1)


if __name__ == '__main__':
    main()

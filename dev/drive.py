#!/usr/bin/env python3
"""Drives a restore or a backup on a test site through the plugin's AJAX endpoint, as the admin page does.

    python dev/drive.py --site http://localhost:8082 --user bob --password pass-b restore backup.wpress [--secret pw]
    python dev/drive.py ... upload path/to/file.wpress [--chunk 1500000]
    python dev/drive.py ... export [--secret pw] [--compression gzip|bzip2] [--no-media] [--no-revisions] [--no-spam] [--no-cache]
    python dev/drive.py ... download backup.wpress path/to/save.wpress
    python dev/drive.py ... list

Prints each phase with its progress, the summary, the result and the log. Exits non-zero on failure.
"""
import argparse
import http.cookiejar
import json
import os
import re
import sys
import time
import urllib.parse
import urllib.request
import uuid


class Site:
    def __init__(self, base, user, password):
        self.base = base.rstrip('/')
        self.jar = http.cookiejar.CookieJar()
        self.http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.http.open(self.base + '/wp-login.php').read()
        form = urllib.parse.urlencode({'log': user, 'pwd': password, 'wp-submit': 'Log In', 'testcookie': '1'}).encode()
        self.http.open(self.base + '/wp-login.php', form).read()
        page = self.http.open(self.base + '/wp-admin/tools.php?page=wpress-restore').read().decode()
        found = re.search(r'window\.wpressRestore = (\{.*?\});\n', page)
        if not found:
            sys.exit('Not logged in, or the plugin page did not load.')
        self.config = json.loads(found.group(1))

    def call(self, op, fields=None, chunk=None):
        fields = dict(fields or {}, action='wpress_restore', op=op, nonce=self.config['nonce'])
        boundary = uuid.uuid4().hex
        body = b''
        for name, value in fields.items():
            body += f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode()
        if chunk is not None:
            body += f'--{boundary}\r\nContent-Disposition: form-data; name="chunk"; filename="chunk"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode() + chunk + b'\r\n'
        body += f'--{boundary}--\r\n'.encode()
        request = urllib.request.Request(self.config['ajaxUrl'], body, {'Content-Type': f'multipart/form-data; boundary={boundary}'})
        try:
            response = self.http.open(request, timeout=300)
            status, text = response.status, response.read().decode()
        except urllib.error.HTTPError as error:
            status, text = error.code, error.read().decode()
        try:
            data = json.loads(text)
        except ValueError:
            raise RuntimeError(f'HTTP {status}, not JSON: {text[:500]}')
        if not data.get('success'):
            raise RuntimeError(f'HTTP {status}: {data.get("data")}')
        return data['data']


def upload(site, path, chunk_size):
    size = os.path.getsize(path)
    begin = site.call('upload_begin', {'name': os.path.basename(path), 'size': size, 'overwrite': '1'})
    offset, requests = begin['offset'], 0
    with open(path, 'rb') as handle:
        while offset < size:
            handle.seek(offset)
            result = site.call('upload_chunk', {'name': begin['name'], 'size': size, 'offset': offset}, handle.read(chunk_size))
            offset, requests = result['offset'], requests + 1
    print(f'uploaded {begin["name"]}: {size} bytes in {requests} requests')
    return begin['name']


def restore(site, name, secret):
    started = time.time()
    job = site.call('start', {'name': name})['job']
    steps, last = 0, None
    while True:
        if job['status'] == 'ready':
            print('summary:', json.dumps(job['summary'], indent=1))
            job = site.call('confirm', {'password': secret or ''})['job']
            continue
        if job['status'] not in ('scanning', 'verifying', 'extracting_db', 'importing_db', 'extracting_files', 'finalizing'):
            break
        data = site.call('step')
        job, steps = data['job'], steps + 1
        if job['status'] != last:
            print(f'{time.time() - started:7.1f}s  {job["status"]}')
            last = job['status']
    print(f'{job["status"]} after {steps} steps, {time.time() - started:.1f}s')
    print('result:', json.dumps(job.get('result'), indent=1))
    if job['status'] == 'failed':
        print('error:', job['error'])
    return job


def export(site, args):
    started = time.time()
    job = site.call('export_start', {
        'no_media': '1' if args.no_media else '0',
        'no_post_revisions': '1' if args.no_revisions else '0',
        'no_spam_comments': '1' if args.no_spam else '0',
        'no_cache': '1' if args.no_cache else '0',
        'compression': args.compression or '',
        'password': args.secret or '',
    })['job']
    print(f'creating {job["backup"]}')
    steps, last = 0, None
    while job['status'] in ('listing', 'dumping', 'packing', 'sealing'):
        data = site.call('step')
        job, steps = data['job'], steps + 1
        if job['status'] != last:
            print(f'{time.time() - started:7.1f}s  {job["status"]}')
            last = job['status']
    print(f'{job["status"]} after {steps} steps, {time.time() - started:.1f}s')
    print('result:', json.dumps(job.get('result'), indent=1))
    if job['status'] == 'failed':
        print('error:', job['error'])
    print('--- log ---\n' + site.call('log')['log'])
    return job


def download(site, name, target):
    backup = next((b for b in site.call('backups')['backups'] if b['name'] == name), None)
    if not backup or not backup['url']:
        sys.exit(f'{name} is not listed with a download link')
    with site.http.open(backup['url'], timeout=300) as response, open(target, 'wb') as out:
        kind = response.headers.get('Content-Type')
        while True:
            piece = response.read(1 << 20)
            if not piece:
                break
            out.write(piece)
    size = os.path.getsize(target)
    print(f'downloaded {backup["url"]} ({kind}): {size} bytes, listed {backup["size"]}')
    if size != backup['size']:
        sys.exit('size mismatch')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--site', default='http://localhost:8082')
    parser.add_argument('--user', default='bob')
    parser.add_argument('--password', default='pass-b')
    parser.add_argument('command', choices=['list', 'upload', 'restore', 'export', 'download'])
    parser.add_argument('file', nargs='?')
    parser.add_argument('target', nargs='?')
    parser.add_argument('--secret', help='password of an encrypted backup')
    parser.add_argument('--chunk', type=int, default=1500000)
    parser.add_argument('--compression', choices=['gzip', 'bzip2'])
    parser.add_argument('--no-media', action='store_true')
    parser.add_argument('--no-revisions', action='store_true')
    parser.add_argument('--no-spam', action='store_true')
    parser.add_argument('--no-cache', action='store_true')
    args = parser.parse_args()

    site = Site(args.site, args.user, args.password)
    if args.command == 'list':
        print(json.dumps(site.call('backups')['backups'], indent=1))
    elif args.command == 'upload':
        upload(site, args.file, args.chunk)
    elif args.command == 'export':
        sys.exit(0 if export(site, args)['status'] == 'done' else 1)
    elif args.command == 'download':
        download(site, args.file, args.target)
    else:
        job = restore(site, args.file, args.secret)
        if job['status'] == 'done':
            print('--- log (fetched before the switch left us logged out) ---')
        sys.exit(0 if job['status'] == 'done' else 1)


if __name__ == '__main__':
    main()

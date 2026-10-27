#!/usr/bin/env python3
"""Compares a WordPress site with its restored copy over public HTTP, with no admin access.

    python dev/compare-sites.py https://original.example https://copy.example [report.json]

Checks, on both sites: the sitemap pages; content counts, IDs, dates and titles from the REST
API; the REST namespaces (which name the active plugins); each page's status, title, H1, meta
description, image count and visible text, once the copy's domain is replaced by the original's;
that every file the copy's pages load answers 200, and that its media and theme files are
identical byte for byte; and what still names the old domain on the copy. Blocks that change on
every load (OceanWP's related posts) are left out of the text comparison. Prints a summary;
the details go to the JSON report.
"""
import argparse
import difflib
import hashlib
import html
import json
import random
import re
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from urllib.parse import urlparse

UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'
RANDOM_BLOCKS = [r'(?is)<section id="related-posts".*?</section>']
MEDIA = r'\.(jpe?g|png|gif|webp|avif|svg|pdf|woff2?|ttf|mp4|ico)$'


def fetch(url, method='GET'):
    request = urllib.request.Request(url, method=method, headers={'User-Agent': UA, 'Accept': '*/*'})
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            return response.status, {k.lower(): v for k, v in response.headers.items()}, response.read()
    except urllib.error.HTTPError as error:
        return error.code, {k.lower(): v for k, v in (error.headers or {}).items()}, error.read()
    except Exception as error:  # noqa: BLE001 -- any failure is a result here.
        return 0, {}, str(error).encode()


def sitemap_paths(base):
    found, seen, queue = set(), set(), [base + p for p in ('/sitemap_index.xml', '/wp-sitemap.xml', '/sitemap.xml')]
    while queue:
        url = queue.pop(0)
        if url in seen:
            continue
        seen.add(url)
        status, _, body = fetch(url)
        if status != 200:
            continue
        for loc in re.findall(r'<loc>\s*(.*?)\s*</loc>', body.decode('utf-8', 'replace')):
            loc = html.unescape(loc)
            if loc.split('?')[0].endswith('.xml'):
                queue.append(loc)
            else:
                found.add(urlparse(loc).path or '/')
    return found


def rest(base, path):
    status, headers, body = fetch(base + '/wp-json' + path)
    try:
        return status, headers, json.loads(body.decode('utf-8', 'replace'))
    except ValueError:
        return status, headers, None


def site_facts(base):
    _, _, root = rest(base, '/')
    facts = {'namespaces': sorted((root or {}).get('namespaces') or []), 'name': (root or {}).get('name'), 'counts': {}, 'items': {}}
    _, _, types = rest(base, '/wp/v2/types')
    for slug, info in (types or {}).items():
        if info.get('rest_base'):
            status, headers, _ = rest(base, '/wp/v2/%s?per_page=1' % info['rest_base'])
            facts['counts'][slug] = headers.get('x-wp-total') if status == 200 else 'HTTP %d' % status
    for kind in ('pages', 'posts'):
        page = 1
        while True:
            status, headers, items = rest(base, '/wp/v2/%s?per_page=100&page=%d&_fields=id,slug,link,modified,title' % (kind, page))
            if status != 200 or not isinstance(items, list):
                break
            for item in items:
                facts['items']['%s/%s' % (kind, item.get('slug'))] = [item.get('id'), item.get('modified'), (item.get('title') or {}).get('rendered'), urlparse(item.get('link', '')).path]
            if page >= int(headers.get('x-wp-totalpages', '1') or 1):
                break
            page += 1
    return facts


def visible_text(doc):
    for block in RANDOM_BLOCKS:
        doc = re.sub(block, ' ', doc)
    doc = re.sub(r'(?is)<(script|style|noscript|svg)\b.*?</\1>', ' ', doc)
    doc = re.sub(r'(?s)<!--.*?-->', ' ', doc)
    doc = re.sub(r'(?s)<[^>]+>', '\n', doc)
    return [line for line in (re.sub(r'\s+', ' ', html.unescape(x)).strip() for x in doc.split('\n')) if line]


def first(pattern, doc):
    found = re.search(pattern, doc, re.I | re.S)
    return html.unescape(re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', '', found.group(1)))).strip() if found else None


def files_loaded(doc, host):
    urls = set()
    for found in re.finditer(r'(?:src|href|data-src|data-lazy-src|srcset|data-srcset|data-bg)\s*=\s*["\']([^"\']+)["\']|url\(\s*["\']?([^"\')]+)', doc, re.I):
        for part in (found.group(1) or found.group(2)).split(','):
            url = html.unescape(part.strip().split(' ')[0])
            url = 'https:' + url if url.startswith('//') else url
            url = 'https://' + host + url if url.startswith('/') else url
            if urlparse(url).netloc == host and re.search(r'\.(jpe?g|png|gif|webp|avif|svg|css|js|woff2?|ttf|pdf|mp4|ico)(\?|$)', url, re.I):
                urls.add(url)
    return urls


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('original')
    parser.add_argument('copy')
    parser.add_argument('report', nargs='?', default='compare-report.json')
    args = parser.parse_args()
    old, new = args.original.rstrip('/'), args.copy.rstrip('/')
    old_host, new_host = urlparse(old).netloc, urlparse(new).netloc
    bust = 'cmp=%d' % random.randint(10**8, 10**9)
    report = {}

    with ThreadPoolExecutor(4) as pool:
        old_paths, new_paths = pool.map(sitemap_paths, (old, new))
        old_facts, new_facts = pool.map(site_facts, (old, new))
    report['sitemap'] = {'old': len(old_paths), 'new': len(new_paths), 'only_old': sorted(old_paths - new_paths), 'only_new': sorted(new_paths - old_paths)}
    report['counts'] = {'old': old_facts['counts'], 'new': new_facts['counts']}
    report['namespaces'] = {'only_old': sorted(set(old_facts['namespaces']) - set(new_facts['namespaces'])), 'only_new': sorted(set(new_facts['namespaces']) - set(old_facts['namespaces'])), 'common': len(set(old_facts['namespaces']) & set(new_facts['namespaces']))}
    report['items'] = {
        'only_old': sorted(set(old_facts['items']) - set(new_facts['items'])),
        'only_new': sorted(set(new_facts['items']) - set(old_facts['items'])),
        'changed': sorted(k for k in set(old_facts['items']) & set(new_facts['items']) if old_facts['items'][k] != new_facts['items'][k]),
    }

    def page(path):
        sep = '&' if '?' in path else '?'
        o_status, _, o_body = fetch(old + path + sep + bust)
        n_status, n_headers, n_body = fetch(new + path + sep + bust)
        o_doc, n_raw = o_body.decode('utf-8', 'replace'), n_body.decode('utf-8', 'replace')
        n_doc = n_raw.replace(new_host, old_host).replace(new_host.replace('.', '\\.'), old_host.replace('.', '\\.'))
        o_text, n_text = visible_text(o_doc), visible_text(n_doc)
        diff = [d for d in difflib.unified_diff(o_text, n_text, lineterm='', n=0) if d[:1] in '+-' and d[:3] not in ('+++', '---')]
        return {
            'path': path, 'status': [o_status, n_status],
            'title': first(r'<title>(.*?)</title>', o_doc) == first(r'<title>(.*?)</title>', n_doc),
            'h1': first(r'<h1[^>]*>(.*?)</h1>', o_doc) == first(r'<h1[^>]*>(.*?)</h1>', n_doc),
            'description': first(r'<meta name="description" content="([^"]*)"', o_doc) == first(r'<meta name="description" content="([^"]*)"', n_doc),
            'images': [len(re.findall(r'<img\b', o_doc, re.I)), len(re.findall(r'<img\b', n_doc, re.I))],
            'text_diff': diff[:40], 'text_diff_count': len(diff),
            'old_domain_on_copy': sorted({n_raw[max(0, m.start() - 50):m.end() + 30].replace('\n', ' ') for m in re.finditer(re.escape(old_host), n_raw)}),
            'copy_cache': n_headers.get('x-proxy-cache'),
            'files': sorted(files_loaded(n_raw, new_host)),
        }

    paths = sorted((old_paths | new_paths | {v[3] for v in old_facts['items'].values()} | {'/'}) - {''})
    with ThreadPoolExecutor(4) as pool:
        pages = list(pool.map(page, paths))
    files = sorted({f for p in pages for f in p.pop('files')})

    def check(url):
        n_status, _, n_body = fetch(url)
        same = None
        if n_status == 200 and re.search(MEDIA, urlparse(url).path, re.I) and '/elementor/css/' not in url:
            o_status, _, o_body = fetch(url.replace(new_host, old_host, 1))
            same = o_status == 200 and hashlib.sha1(o_body).digest() == hashlib.sha1(n_body).digest()
        return url, n_status, same

    with ThreadPoolExecutor(8) as pool:
        checked = list(pool.map(check, files))
    report['pages'] = pages
    report['files'] = {
        'loaded': len(checked),
        'failing': [(u, s) for u, s, _ in checked if s != 200],
        'media_compared': sum(1 for _, _, same in checked if same is not None),
        'media_different': [u for u, _, same in checked if same is False],
    }
    with open(args.report, 'w', encoding='utf-8') as out:
        json.dump(report, out, indent=1, ensure_ascii=False)

    differing = [p for p in pages if p['status'][0] != p['status'][1] or not (p['title'] and p['h1'] and p['description']) or p['images'][0] != p['images'][1] or p['text_diff_count']]
    print(f"sitemap pages: {report['sitemap']['old']} / {report['sitemap']['new']}, only on one side: {report['sitemap']['only_old'] + report['sitemap']['only_new']}")
    print(f"content counts identical: {old_facts['counts'] == new_facts['counts']}  ({json.dumps(new_facts['counts'])})")
    print(f"REST namespaces: {report['namespaces']['common']} common, only original {report['namespaces']['only_old']}, only copy {report['namespaces']['only_new']}")
    print(f"pages and posts: only original {report['items']['only_old']}, only copy {report['items']['only_new']}, changed {report['items']['changed']}")
    print(f"pages compared: {len(pages)}, differing: {[p['path'] for p in differing]}")
    print(f"files loaded by the copy: {len(checked)}, failing: {len(report['files']['failing'])}, media identical: {report['files']['media_compared'] - len(report['files']['media_different'])} of {report['files']['media_compared']}")
    print(f"old domain still on the copy: {[p['path'] for p in pages if p['old_domain_on_copy']]} (text mentions may be legitimate; see the report)")
    print(f'details: {args.report}')


if __name__ == '__main__':
    main()

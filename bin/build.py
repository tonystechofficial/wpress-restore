#!/usr/bin/env python3
"""Builds dist/wpress-restore.zip, installable from Plugins > Add New > Upload Plugin.

Python's zipfile writes "/" separators; archives made with PowerShell's Compress-Archive
can use "\\", which WordPress unpacks into a broken plugin.
"""
import pathlib
import re
import zipfile

ROOT = pathlib.Path(__file__).resolve().parent.parent
RUNTIME = ['wpress-restore.php', 'uninstall.php', 'readme.txt', 'LICENSE']


def main():
    version = re.search(r'Version:\s*([\d.]+)', (ROOT / 'wpress-restore.php').read_text(encoding='utf-8')).group(1)
    stable = re.search(r'Stable tag:\s*([\d.]+)', (ROOT / 'readme.txt').read_text(encoding='utf-8')).group(1)
    if version != stable:
        raise SystemExit(f'Version {version} in wpress-restore.php but stable tag {stable} in readme.txt')
    files = RUNTIME + sorted(
        path.relative_to(ROOT).as_posix()
        for folder in ('includes', 'assets')
        for path in (ROOT / folder).iterdir()
        if path.is_file()
    )
    target = ROOT / 'dist' / 'wpress-restore.zip'
    target.parent.mkdir(exist_ok=True)
    with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED) as archive:
        for name in files:
            archive.write(ROOT / name, 'wpress-restore/' + name)
    print(f'{target} ({version}, {len(files)} files, {target.stat().st_size} bytes)')


if __name__ == '__main__':
    main()

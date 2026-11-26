#!/usr/bin/env python3
"""Verify that the GitHub staging copy matches the live OpenMVP portal."""
import re
import sys
from pathlib import Path

ROOT = Path('/home/tapapp/openmvp-repo')
LIVE = Path('/var/www/openmvp-portal')

FILES = [
    'index.html',
    'assets/index-live.js',
    'assets/site-extra.css',
    'donate/index.html',
    'donate/create.php',
    'api/dv_webhook.php',
] + ['assets/coins/%s.svg' % c for c in
     ('BTC', 'ETH', 'USDT', 'USDC', 'DAI', 'TRX',
      'LTC', 'BCH', 'BNB', 'POL', 'DOGE')]

SECRETS = [
    ('openrouter', re.compile(r'sk-or-v1-[A-Za-z0-9_-]{10,}')),
    ('telegram', re.compile(r'\b\d{8,}:[A-Za-z0-9_-]{30,}')),
    ('dbpass', re.compile(r"DB_PASS(?:WORD)?\s*=\s*['\"](?!CHANGE_ME|REDACTED|\*\*\*)([^'\"]{4,})['\"]")),
    ('dsn', re.compile(r"DB_DSN\s*=\s*['\"]?mysql:host=")),
]

fails = []
for rel in FILES:
    live = LIVE / rel
    repo = ROOT / 'portal' / rel
    if not live.is_file():
        fails.append('MISSING_LIVE %s' % rel)
    elif not repo.is_file():
        fails.append('MISSING_REPO %s' % rel)
    elif live.read_bytes() != repo.read_bytes():
        fails.append('DIFF %s' % rel)

checks = {
    'index.html': [b'index-live.js?v=35'],
    'assets/index-live.js': [
        b'/* --- 9:16 project previews --- */',
    ],
    'donate/index.html': [b'/donate/create.php', b'assets/coins/'],
    'donate/create.php': [b'/etc/openmvp/dv.env'],
}
for rel, needles in checks.items():
    p = ROOT / 'portal' / rel
    if p.is_file():
        data = p.read_bytes()
        for needle in needles:
            if needle not in data:
                fails.append('CONTENT %s: %s' % (rel, needle.decode()))

js = (ROOT / 'portal/assets/index-live.js').read_text() if (ROOT / 'portal/assets/index-live.js').is_file() else ''
repo_count = js.count('repo:"https://github.com/martinroot/')
coin_count = (ROOT / 'portal/donate/index.html').read_text().count('assets/coins/') if (ROOT / 'portal/donate/index.html').is_file() else 0
if repo_count != 23:
    fails.append('REPOS_EXPECTED_23_GOT_%d' % repo_count)
if coin_count != 11:
    fails.append('COINS_EXPECTED_11_GOT_%d' % coin_count)

for p in ROOT.rglob('*'):
    if not p.is_file() or '.git' in p.parts:
        continue
    try:
        text = p.read_text()
    except (UnicodeDecodeError, OSError):
        continue
    for name, pattern in SECRETS:
        if pattern.search(text):
            fails.append('SECRET_%s %s' % (name, p.relative_to(ROOT)))

if fails:
    print('\n'.join(fails))
    print('SYNC_FAIL count=%d' % len(fails))
    sys.exit(1)
print('SYNC_OK files=%d previews=1 repos=%d coins=%d secrets=0' % (len(FILES), repo_count, coin_count))

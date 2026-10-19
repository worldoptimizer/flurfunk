#!/usr/bin/env python3
"""Build assets/emoji.json for the reaction picker from an unpacked emojibase-data package (MIT,
https://emojibase.dev): every emoji with its group and its English and German name and a few
keywords for the search. Usage: python3 tools/emoji.py path/to/emojibase-data/package"""
import json, pathlib, sys

src = pathlib.Path(sys.argv[1])
root = pathlib.Path(__file__).resolve().parent.parent
version = json.loads((src / 'package.json').read_text())['version']
names = {}
for loc in ('en', 'de'):
    for e in json.loads((src / loc / 'compact.json').read_text()):
        if e.get('group') is None or e['group'] == 2:  # 2: skin tones and hair, only parts of others
            continue
        words = [e['label']] + [t for t in e.get('tags', []) if t not in e['label']][:6]
        names.setdefault(e['hexcode'], {'emoji': e['unicode'], 'group': e['group'], 'order': e.get('order', 0)})[loc] = ' '.join(words).lower()
groups = {}
for loc in ('en', 'de'):
    msg = json.loads((src / loc / 'messages.json').read_text())
    groups[loc] = {str(g['order']): g['message'] for g in msg['groups'] if g['order'] != 2}
rows = sorted(names.values(), key=lambda e: (e['group'], e['order']))
out = {
    'source': 'emojibase-data ' + version + ' (MIT)',
    'groups': groups,
    'list': [[e['emoji'], e['group'], e.get('en', ''), e.get('de', e.get('en', ''))] for e in rows],
}
(root / 'assets' / 'emoji.json').write_text(json.dumps(out, ensure_ascii=False, separators=(',', ':')) + '\n')
print(len(rows), 'emoji,', (root / 'assets' / 'emoji.json').stat().st_size // 1024, 'KB')

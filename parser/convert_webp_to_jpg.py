#!/usr/bin/env python3
"""Convert all .webp screenshots to .jpg and update DB references."""
import os
import sys
from pathlib import Path
from PIL import Image

storage = Path('/var/www/html/storage/app/public/screenshots')
webp_files = list(storage.rglob('*.webp'))
print(f'Found {len(webp_files)} webp files', flush=True)

converted = []
failed = []
for wf in webp_files:
    jpg = wf.with_suffix('.jpg')
    if jpg.exists():
        print(f'SKIP (jpg exists): {wf.name}', flush=True)
        converted.append(str(wf.relative_to(storage.parent)))
        continue
    try:
        with Image.open(wf) as img:
            img.convert('RGB').save(jpg, 'JPEG', quality=85, optimize=True)
        print(f'OK: {wf.name} -> {jpg.name}', flush=True)
        converted.append(str(wf.relative_to(storage.parent)))
    except Exception as e:
        print(f'ERROR {wf.name}: {e}', flush=True)
        failed.append(str(wf))

print(f'\nDone: {len(converted)} converted, {len(failed)} failed', flush=True)

# Print old->new path mapping for DB update
for wf in webp_files:
    rel = str(wf.relative_to(Path('/var/www/html/storage/app/public')))
    new_rel = rel.replace('.webp', '.jpg')
    print(f'DB_UPDATE:{rel}|{new_rel}', flush=True)

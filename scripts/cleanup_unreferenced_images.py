#!/usr/bin/env python3
"""
Clean up unreferenced puzzle images in public/uploads.
Preserves all images that are referenced by questions in var/gameshow.db.
"""

import os
import sqlite3
import json

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB_PATH = os.path.join(BASE_DIR, "var", "gameshow.db")
UPLOADS_DIR = os.path.join(BASE_DIR, "public", "uploads")

if not os.path.exists(DB_PATH):
    print(f"Error: Database not found at {DB_PATH}")
    exit(1)

conn = sqlite3.connect(DB_PATH)
cursor = conn.cursor()

referenced_relpaths = set()
referenced_basenames = set()

for row in cursor.execute("SELECT media_path, media_config FROM questions"):
    mp = (row[0] or "").strip()
    if mp:
        norm = mp.lstrip("/")
        if norm.startswith("public/"):
            norm = norm[len("public/"):]
        referenced_relpaths.add(norm)
        referenced_basenames.add(os.path.basename(norm))

    cfg_raw = row[1] or ""
    if cfg_raw:
        try:
            cfg = json.loads(cfg_raw) if isinstance(cfg_raw, str) else cfg_raw
            if isinstance(cfg, dict):
                for k, v in cfg.items():
                    if isinstance(v, str) and (v.endswith(".svg") or v.endswith(".png") or v.endswith(".jpg") or v.endswith(".webp")):
                        v_norm = v.lstrip("/")
                        if v_norm.startswith("public/"):
                            v_norm = v_norm[len("public/"):]
                        referenced_relpaths.add(v_norm)
                        referenced_basenames.add(os.path.basename(v_norm))
        except Exception:
            pass

print(f"Total referenced images in DB: {len(referenced_relpaths)}")

deleted_count = 0
freed_bytes = 0
kept_count = 0

for root, dirs, files in os.walk(UPLOADS_DIR):
    for f in files:
        if f.startswith("."):
            continue

        full_path = os.path.join(root, f)
        rel_from_public = os.path.relpath(full_path, os.path.join(BASE_DIR, "public"))
        basename = f

        if rel_from_public in referenced_relpaths or basename in referenced_basenames:
            kept_count += 1
        else:
            size = os.path.getsize(full_path)
            os.remove(full_path)
            deleted_count += 1
            freed_bytes += size

print(f"Kept (referenced in DB): {kept_count} files")
print(f"Deleted (unreferenced):  {deleted_count} files")
print(f"Space freed:             {freed_bytes / (1024 * 1024):.2f} MB")


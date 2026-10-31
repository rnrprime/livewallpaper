#!/usr/bin/env python3
"""Run the real API against a separate, repeatable SQLite demo catalog."""
import base64, os, subprocess
from pathlib import Path
repo=Path(__file__).resolve().parents[1]
folder=Path('/tmp/liper-demo'); folder.mkdir(exist_ok=True)
db=folder/'liper-demo.sqlite'; db.touch(exist_ok=True)
env=os.environ.copy()
env.update(APP_ENV='local', APP_DEBUG='false', APP_URL='https://localhost:9443',
    APP_KEY='base64:'+base64.b64encode(b'd'*32).decode(), APP_CONFIG_CACHE=str(folder/'uncached-config.php'),
    DB_CONNECTION='sqlite', DB_DATABASE=str(db), DB_URL='', CACHE_STORE='array',
    SESSION_DRIVER='array', QUEUE_CONNECTION='sync')
for args in [['migrate','--force'],['db:seed','--class=DemoCatalogSeeder','--force'],
             ['serve','--host=127.0.0.1','--port=8765','--no-reload']]:
    subprocess.run(['php','artisan',*args],cwd=repo,env=env,check=True)

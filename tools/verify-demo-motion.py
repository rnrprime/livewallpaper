#!/usr/bin/env python3
"""Verify a rendered 60 fps bundle before deployment; never changes the media."""
import argparse
import hashlib
import json
import subprocess
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument('directory', type=Path)
args = parser.parse_args()
root = args.directory
manifest = json.loads((root / 'manifest.json').read_text())
assert manifest['version'] == 'v2-60fps' and manifest['fps'] == 60
report = []
for name, checksum in manifest['files'].items():
    assert Path(name).name == name and Path(name).suffix in ('.jpg', '.mp4')
    path = root / name
    assert hashlib.sha256(path.read_bytes()).hexdigest() == checksum, name
    if path.suffix != '.mp4':
        continue
    data = json.loads(subprocess.check_output([
        'ffprobe', '-v', 'error', '-show_streams', '-show_frames', '-select_streams', 'v', '-of', 'json', str(path)]))
    stream = data['streams'][0]
    frames = data['frames']
    assert stream['codec_name'] == 'h264' and (stream['width'], stream['height']) == (540, 960), name
    assert len(frames) == 240 and stream['avg_frame_rate'] == '60/1', name
    assert all(abs(float(frame['best_effort_timestamp_time']) - i / 60) < 1e-5
               for i, frame in enumerate(frames)), name
    lines = subprocess.check_output(['ffmpeg', '-v', 'error', '-i', str(path), '-f', 'framemd5', '-']).decode().splitlines()
    hashes = [line.rsplit(',', 1)[-1].strip() for line in lines if not line.startswith('#')]
    repeats = sum(a == b for a, b in zip(hashes, hashes[1:]))
    assert repeats == 0, (name, repeats)
    report.append({'file': name, 'fps': 60, 'frames': 240, 'adjacent_identical_frames': repeats, 'sha256': checksum})
assert len(report) == 12 and len(manifest['files']) == 24
print(json.dumps(report, indent=2))

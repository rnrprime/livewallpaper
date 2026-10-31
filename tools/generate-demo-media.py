#!/usr/bin/env python3
"""Create 12 original procedural motion fixtures and their matching posters with FFmpeg.
No downloaded assets. These are test graphics, not a licensed production catalog.
"""
import argparse, subprocess, hashlib, json
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

PALETTES = {
    'aurora': ['081923', '128B7C', '91E3AD', '151C49'],
    'ocean': ['031222', '125C85', '6BCBDA', '081F4B'],
    'prism': ['191332', '8768BD', 'F2B3B1', '4254A2'],
    'ember': ['26120E', 'AB542F', 'F4CB83', '583321'],
    'fluid': ['11251F', '617D58', 'CAD6A5', '294A46'],
    'noir': ['080B0F', '47505D', 'C5CED5', '1A2029'],
}
def render(job):
    out, slug, colors, variant, fps = job
    path = out / f'{slug}-{variant}'
    # Slow-moving curved colour fields, portrait H.264, four seconds, silent.
    source = f'gradients=s=540x960:r={fps}:d=4:n=4:' + ':'.join(f'c{i}=0x{c}' for i,c in enumerate(colors))
    source += f':type={"spiral" if variant == 0 else "circular"}:speed={0.035 * 30 / fps}:seed={17+variant}:x0=130:y0=240:x1=450:y1=810'
    subprocess.run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i',source,
        '-c:v','libx264','-preset','fast','-crf','20','-pix_fmt','yuv420p','-movflags','+faststart',str(path)+'.mp4'],check=True)
    subprocess.run(['ffmpeg','-hide_banner','-loglevel','error','-y','-ss','1','-i',str(path)+'.mp4','-frames:v','1','-q:v','2',str(path)+'.jpg'],check=True)
    print(path.name, flush=True)
if __name__ == '__main__':
    parser=argparse.ArgumentParser(); parser.add_argument('output',type=Path)
    parser.add_argument('--fps', type=int, choices=[30,60], default=60)
    args=parser.parse_args()
    args.output.mkdir(parents=True,exist_ok=True)
    with ThreadPoolExecutor(max_workers=2) as pool:
        list(pool.map(render,[(args.output,s,c,v,args.fps) for s,c in PALETTES.items() for v in range(2)]))

    # New paths are required when deploying replacements so device/CDN caches
    # cannot serve the previous 30 fps bytes under the same URL.
    manifest = {'version': 'v2-60fps' if args.fps == 60 else 'v1', 'fps': args.fps, 'files': {}}
    for file in sorted(args.output.iterdir()):
        if file.suffix in ['.mp4', '.jpg']:
            manifest['files'][file.name] = hashlib.sha256(file.read_bytes()).hexdigest()
    (args.output / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')

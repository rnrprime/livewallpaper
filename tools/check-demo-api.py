#!/usr/bin/env python3
"""Verify real HTTP responses, cursor pagination, category filters and media ranges."""
import argparse, json, ssl, urllib.request, urllib.parse
p=argparse.ArgumentParser(); p.add_argument('--cert',required=True); a=p.parse_args()
ctx=ssl.create_default_context(cafile=a.cert)
base='https://localhost:9443'
def get(path):
    with urllib.request.urlopen(base+path,context=ctx,timeout=15) as r: return json.load(r)
categories=get('/api/v1/categories')['data']; assert len(categories)==6
items=[]; sizes=[]; path='/api/v1/wallpapers?per_page=20'
while path:
    response=get(path); items.extend(response['data']); sizes.append(len(response['data']))
    cursor=response['meta']['next_cursor']
    path='/api/v1/wallpapers?'+urllib.parse.urlencode({'per_page':20,'cursor':cursor}) if cursor else None
assert sizes==[20,20,8] and len(set(w['id'] for w in items))==48
for category in categories:
    result=get('/api/v1/wallpapers?category_id='+str(category['id']))
    assert len(result['data'])==8 and all(w['category']['id']==category['id'] for w in result['data'])
urls={w['file_url'] for w in items}; assert len(urls)==12
for url in urls:
    request=urllib.request.Request(url,headers={'Range':'bytes=0-1023'})
    with urllib.request.urlopen(request,context=ctx) as r:
        assert r.status==206 and r.headers['Content-Type']=='video/mp4' and len(r.read())==1024
for url in {w['preview_image'] for w in items}:
    with urllib.request.urlopen(url,context=ctx) as r: assert r.status==200 and r.headers['Content-Type']=='image/jpeg'
print(json.dumps({'categories':len(categories),'wallpapers':len(items),'page_sizes':sizes,'unique_videos':len(urls),'filters':'passed','video_byte_ranges':'passed','posters':'passed'},indent=2))

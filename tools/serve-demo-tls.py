#!/usr/bin/env python3
"""Loopback HTTPS gateway to the real local Laravel API + generated media.
Uses an explicitly trusted simulator-only development certificate. No authentication bypass.
"""
import argparse, http.client, mimetypes, re, ssl
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import unquote, urlsplit

class Handler(BaseHTTPRequestHandler):
    def do_HEAD(self): self.respond(head=True)
    def do_GET(self): self.respond()
    def respond(self, head=False):
        path = unquote(urlsplit(self.path).path)
        if path.startswith('/api/v1/'):
            conn = http.client.HTTPConnection('127.0.0.1', 8765, timeout=20)
            conn.request('GET', self.path, headers={'Accept':'application/json'})
            response = conn.getresponse(); data = response.read()
            self.send_response(response.status)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(data)))
            self.send_header('Cache-Control', 'no-store')
            self.end_headers()
            if not head: self.wfile.write(data)
            conn.close(); return
        if not path.startswith('/media/'):
            self.send_error(404); return
        file = (self.server.media / path.removeprefix('/media/')).resolve()
        if file.parent != self.server.media or not file.is_file():
            self.send_error(404); return
        size=file.stat().st_size; start=0; end=size-1; partial=False
        requested=self.headers.get('Range')
        if requested:
            match=re.fullmatch(r'bytes=(\d*)-(\d*)',requested)
            if not match or not any(match.groups()): self.send_error(416); return
            a,b=match.groups()
            if a: start=int(a); end=min(int(b),end) if b else end
            else: start=max(0,size-int(b))
            if start>end or start>=size: self.send_error(416); return
            partial=True
        self.send_response(206 if partial else 200)
        self.send_header('Content-Type',mimetypes.guess_type(file.name)[0] or 'application/octet-stream')
        self.send_header('Accept-Ranges','bytes')
        self.send_header('Content-Length',str(end-start+1))
        if partial: self.send_header('Content-Range',f'bytes {start}-{end}/{size}')
        self.end_headers()
        if not head:
            with file.open('rb') as stream:
                stream.seek(start); remaining=end-start+1
                while remaining:
                    chunk=stream.read(min(65536,remaining))
                    if not chunk: break
                    self.wfile.write(chunk); remaining-=len(chunk)
    def log_message(self, format, *args): pass
if __name__=='__main__':
    p=argparse.ArgumentParser(); p.add_argument('--media',type=Path,required=True); p.add_argument('--cert',required=True); p.add_argument('--key',required=True); a=p.parse_args()
    server=ThreadingHTTPServer(('127.0.0.1',9443),Handler); server.media=a.media.resolve()
    tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); tls.load_cert_chain(a.cert,a.key)
    server.socket=tls.wrap_socket(server.socket,server_side=True)
    print('Demo HTTPS: https://localhost:9443 (loopback only)',flush=True)
    server.serve_forever()

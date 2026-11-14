"""Faux serveur Portainer pour les tests manuels et de bout en bout (données fictives).

Répond comme l'API Portainer aux seules requêtes que le plugin envoie, à partir d'un fichier JSON
relu à chaque requête (on peut donc retirer un service pendant un test en modifiant le fichier).
Refuse toute requête sans l'en-tête X-API-Key attendu (401). N'écrit jamais la clé dans le
journal : seulement le chemin demandé et si la clé était valide.

Usage : MOCK_API_KEY=... python server.py fixture.json [port]
"""
import json
import os
import re
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

FIXTURE = sys.argv[1]
PORT = int(sys.argv[2]) if len(sys.argv) > 2 else 9000
API_KEY = os.environ.get('MOCK_API_KEY', '')
DOCKER = re.compile(r'^/api/endpoints/(\d+)/docker/(services|nodes|tasks|networks)$')
# Hôte Docker autonome : informations, conteneurs, images, détail d'un conteneur.
STANDALONE = re.compile(r'^/api/endpoints/(\d+)/docker/(info|containers/json|images/json|containers/([0-9a-f]+)/json)$')


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        pass

    def reply(self, status, payload):
        body = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        path = self.path.split('?', 1)[0]
        authorized = API_KEY != '' and self.headers.get('X-API-Key') == API_KEY
        print(f'GET {path} key_ok={authorized}', flush=True)
        if not authorized:
            return self.reply(401, {'message': 'Unauthorized'})
        with open(FIXTURE, encoding='utf-8') as handle:
            data = json.load(handle)
        if path == '/api/endpoints':
            return self.reply(200, data['endpoints'])
        if path == '/api/stacks':
            return self.reply(200, data['stacks'])
        match = DOCKER.match(path)
        if match:
            endpoint = data['docker'].get(match.group(1))
            if endpoint is None:
                return self.reply(404, {'message': 'Unable to find an environment'})
            if not endpoint.get('swarm', False):
                return self.reply(503, {'message': 'This node is not a swarm manager.'})
            return self.reply(200, endpoint[match.group(2)])
        match = STANDALONE.match(path)
        if match:
            endpoint = data['docker'].get(match.group(1))
            if endpoint is None:
                return self.reply(404, {'message': 'Unable to find an environment'})
            if match.group(3):
                detail = endpoint.get('inspect', {}).get(match.group(3))
                if detail is None:
                    return self.reply(404, {'message': 'No such container'})
                return self.reply(200, detail)
            key = match.group(2).split('/')[0]
            return self.reply(200, endpoint.get(key, {} if key == 'info' else []))
        return self.reply(404, {'message': 'Not found'})


if __name__ == '__main__':
    ThreadingHTTPServer(('0.0.0.0', PORT), Handler).serve_forever()

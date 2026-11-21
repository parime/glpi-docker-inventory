"""Faux Traefik (API en lecture seule) pour les tests manuels et de bout en bout (données fictives).

Répond comme l'API de Traefik 3.x aux seules requêtes que le plugin envoie (GET /api/version,
/api/entrypoints, /api/http/routers, /api/http/services, /api/http/middlewares, /api/tcp/routers),
à partir d'un fichier JSON relu à chaque requête. Toute autre méthode (POST, PUT, DELETE...) est
refusée (405) : le plugin ne doit jamais rien modifier.

Authentification optionnelle : si MOCK_USER est défini, l'en-tête Authorization basique attendu
est exigé ; sinon, si MOCK_TOKEN est défini, l'en-tête « Bearer <jeton> ». Le secret n'est jamais
écrit dans le journal.

Erreurs simulées (variable MOCK_SIMULATE, ou clé « simulate » du fichier) : 401, 500, delay:<s>.

Usage : [MOCK_USER=... MOCK_PASSWORD=... | MOCK_TOKEN=...] python server.py fixture.json [port]
"""
import base64
import json
import os
import sys
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

FIXTURE = sys.argv[1]
PORT = int(sys.argv[2]) if len(sys.argv) > 2 else 8080
USER = os.environ.get('MOCK_USER', '')
PASSWORD = os.environ.get('MOCK_PASSWORD', '')
TOKEN = os.environ.get('MOCK_TOKEN', '')

ROUTES = {
    '/api/version': 'version',
    '/api/entrypoints': 'entrypoints',
    '/api/http/routers': 'routers',
    '/api/http/services': 'services',
    '/api/http/middlewares': 'middlewares',
    '/api/tcp/routers': 'tcp_routers',
}


def expected_authorization():
    if USER:
        return 'Basic ' + base64.b64encode(f'{USER}:{PASSWORD}'.encode()).decode()
    if TOKEN:
        return 'Bearer ' + TOKEN
    return None


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

    def refuse_write(self):
        print(f'{self.command} {self.path} refused (read-only mock)', flush=True)
        self.reply(405, {'error': 'Method not allowed by the fake Traefik'})

    do_POST = do_PUT = do_PATCH = do_DELETE = refuse_write

    def do_GET(self):
        path = self.path.split('?', 1)[0]
        with open(FIXTURE, encoding='utf-8') as handle:
            data = json.load(handle)
        simulate = os.environ.get('MOCK_SIMULATE', '') or str(data.get('simulate') or '')
        expected = expected_authorization()
        authorized = expected is None or self.headers.get('Authorization') == expected
        print(f'GET {path} auth_ok={authorized} simulate={simulate or "-"}', flush=True)
        if simulate.startswith('delay:'):
            time.sleep(float(simulate.split(':', 1)[1]))
        if simulate == '401' or not authorized:
            return self.reply(401, {'error': 'Unauthorized'})
        if simulate == '500':
            return self.reply(500, {'error': 'Internal server error (simulated)'})
        if path in ROUTES:
            return self.reply(200, data[ROUTES[path]])
        return self.reply(404, {'error': 'Not found'})


if __name__ == '__main__':
    ThreadingHTTPServer(('0.0.0.0', PORT), Handler).serve_forever()

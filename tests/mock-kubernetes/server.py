"""Faux serveur d'API Kubernetes pour les tests manuels et de bout en bout (données fictives).

Répond aux seules requêtes GET que le plugin envoie, à partir d'un fichier JSON relu à chaque
requête (on peut donc ajouter une charge ou changer un état pendant un test) :
  GET /version                                   version du cluster ;
  GET /api/v1/{namespaces,nodes,pods}            listes de tout le cluster ;
  GET /apis/apps/v1/{deployments,statefulsets,daemonsets,replicasets} ;
  GET /apis/batch/v1/cronjobs.
Pagination comme l'API : paramètre limit, jeton metadata.continue à renvoyer en paramètre continue.
Toute autre méthode (POST, PUT, PATCH, DELETE...) est refusée (405) : le plugin ne doit jamais rien
modifier dans le cluster. Aucun « watch » n'est servi.

Jeton : si MOCK_TOKEN est défini, toute requête sans « Authorization: Bearer <MOCK_TOKEN> » reçoit
401. Le jeton n'est jamais écrit dans le journal.

HTTPS (le plugin n'accepte que HTTPS pour Kubernetes) : MOCK_CERT et MOCK_KEY (fichiers PEM). Un
certificat autosigné de test se crée ainsi, puis son contenu se colle dans le champ « Certificat
d'autorité du cluster » de la connexion :
  openssl req -x509 -newkey rsa:2048 -nodes -days 30 -keyout cle.pem -out cert.pem \
    -subj "/CN=kubernetes.exemple.test" -addext "subjectAltName=DNS:kubernetes.exemple.test,DNS:localhost"

Erreurs simulées (variable MOCK_SIMULATE, ou clé « simulate » du fichier, relue à chaque requête) :
  429                limite de requêtes atteinte (toutes les listes) ;
  500                erreur interne du serveur ;
  403:<ressource>    lecture refusée pour cette ressource (« 403:cronjobs ») ;
  empty              le compte ne voit rien (listes vides) ;
  delay:<s>          attend <s> secondes avant de répondre (délai d'attente du client).

Usage : [MOCK_TOKEN=...] [MOCK_CERT=cert.pem MOCK_KEY=cle.pem] [MOCK_SIMULATE=...] \
        python server.py fixture.json [port]
"""
import base64
import json
import os
import ssl
import sys
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlsplit

FIXTURE = sys.argv[1]
PORT = int(sys.argv[2]) if len(sys.argv) > 2 else 6443
TOKEN = os.environ.get('MOCK_TOKEN', '')
RESOURCES = {
    '/api/v1/namespaces': 'namespaces',
    '/api/v1/nodes': 'nodes',
    '/api/v1/pods': 'pods',
    '/apis/apps/v1/deployments': 'deployments',
    '/apis/apps/v1/statefulsets': 'statefulsets',
    '/apis/apps/v1/daemonsets': 'daemonsets',
    '/apis/apps/v1/replicasets': 'replicasets',
    '/apis/batch/v1/cronjobs': 'cronjobs',
    '/api/v1/services': 'services',
    '/apis/networking.k8s.io/v1/ingresses': 'ingresses',
}


def status(code, reason, message):
    return {'kind': 'Status', 'apiVersion': 'v1', 'metadata': {}, 'status': 'Failure',
            'message': message, 'reason': reason, 'code': code}


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        pass

    def reply(self, code, payload):
        body = json.dumps(payload).encode()
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        if code == 429:
            self.send_header('Retry-After', '1')
        self.end_headers()
        self.wfile.write(body)

    def refuse_write(self):
        print(f'{self.command} {self.path} refused (read-only mock)', flush=True)
        self.reply(405, status(405, 'MethodNotAllowed', 'read-only fake Kubernetes API'))

    do_POST = do_PUT = do_PATCH = do_DELETE = do_HEAD = refuse_write

    def do_GET(self):
        with open(FIXTURE, encoding='utf-8') as handle:
            data = json.load(handle)
        simulate = os.environ.get('MOCK_SIMULATE') or data.get('simulate') or ''
        url = urlsplit(self.path)
        query = parse_qs(url.query)
        path = url.path.rstrip('/') or '/'
        print(f'GET {path}', flush=True)
        if simulate.startswith('delay:'):
            time.sleep(float(simulate.split(':', 1)[1]))
        if TOKEN and self.headers.get('Authorization', '') != 'Bearer ' + TOKEN:
            return self.reply(401, status(401, 'Unauthorized', 'Unauthorized'))
        if query.get('watch'):
            return self.reply(400, status(400, 'BadRequest', 'watch is not served by this mock'))
        if simulate == '429':
            return self.reply(429, status(429, 'TooManyRequests', 'too many requests, please try again later'))
        if simulate == '500':
            return self.reply(500, status(500, 'InternalError', 'fictitious internal error'))
        if path == '/version':
            return self.reply(200, data['version'])
        resource = RESOURCES.get(path)
        if resource is None:
            return self.reply(404, status(404, 'NotFound', 'the server could not find the requested resource'))
        if simulate == '403:' + resource:
            return self.reply(403, status(403, 'Forbidden', f'{resource} is forbidden: User cannot list resource'))
        items = [] if simulate == 'empty' else data.get(resource, [])
        limit = max(1, int((query.get('limit') or ['500'])[0]))
        token = (query.get('continue') or [''])[0]
        offset = int(base64.b64decode(token).decode()) if token else 0
        page = items[offset:offset + limit]
        metadata = {'resourceVersion': '1000'}
        if offset + limit < len(items):
            metadata['continue'] = base64.b64encode(str(offset + limit).encode()).decode()
            metadata['remainingItemCount'] = len(items) - offset - limit
        kind = resource[:-1].capitalize() + 'List'
        return self.reply(200, {'kind': kind, 'apiVersion': 'v1', 'metadata': metadata, 'items': page})


if __name__ == '__main__':
    server = ThreadingHTTPServer(('0.0.0.0', PORT), Handler)
    cert, key = os.environ.get('MOCK_CERT'), os.environ.get('MOCK_KEY')
    if cert and key:
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        # TLS 1.2 au moins, comme un vrai serveur d'API Kubernetes (CodeQL py/insecure-protocol).
        context.minimum_version = ssl.TLSVersion.TLSv1_2
        context.load_cert_chain(cert, key)
        server.socket = context.wrap_socket(server.socket, server_side=True)
    scheme = 'https' if cert and key else 'http'
    print(f'Faux Kubernetes sur {scheme}://0.0.0.0:{PORT} ({FIXTURE})', flush=True)
    server.serve_forever()

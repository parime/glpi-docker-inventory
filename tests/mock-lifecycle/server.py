"""Fausse source de cycles de vie pour les tests manuels et de bout en bout (données fictives).

Répond aux seules requêtes que le plugin envoie, à partir d'un fichier JSON relu à chaque requête
(on peut donc changer une date pendant un test en modifiant le fichier) :
  GET /api/v1/products/<produit>/   comme l'API v1 d'endoflife.date ({"result": {"releases": [...]}}) ;
  GET /api/<produit>.json           comme l'ancienne API (liste de cycles).
Produit absent du fichier : 404. Toute autre méthode (POST, PUT, DELETE...) est refusée (405).

Dates relatives : une date « +30d » ou « -400d » du fichier devient la date du jour décalée d'autant,
pour que les états (fin de support passée, bientôt, maintenue) ne dépendent pas du jour du test.

Erreurs simulées (variable MOCK_SIMULATE, ou clé « simulate » du fichier, relue à chaque requête) :
  429          limite de requêtes atteinte ;
  500          erreur interne du serveur ;
  delay:<s>    attend <s> secondes avant de répondre (délai d'attente du client).

Chaque requête est écrite dans le journal (chemin seulement) : on vérifie ainsi que le plugin
n'envoie que le nom du produit, une fois par jour au plus.

Usage : [MOCK_SIMULATE=...] python server.py fixture.json [port]
"""
import datetime
import json
import os
import re
import sys
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlsplit

FIXTURE = sys.argv[1]
PORT = int(sys.argv[2]) if len(sys.argv) > 2 else 5100
V1 = re.compile(r'^/api/v1/products/([a-z0-9._-]+)/?$')
LEGACY = re.compile(r'^/api/([a-z0-9._-]+)\.json$')
RELATIVE = re.compile(r'^([+-]\d+)d$')


def resolve(value):
    """« +30d » => date du jour + 30 jours ; autre valeur inchangée."""
    if isinstance(value, str):
        match = RELATIVE.match(value)
        if match:
            day = datetime.date.today() + datetime.timedelta(days=int(match.group(1)))
            return day.isoformat()
    return value


def v1_release(cycle):
    eol = resolve(cycle.get('eol'))
    is_eol = eol is True or (isinstance(eol, str) and eol <= datetime.date.today().isoformat())
    return {
        'name': cycle['cycle'],
        'codename': cycle.get('codename'),
        'label': cycle['cycle'],
        'releaseDate': resolve(cycle.get('releaseDate')),
        'isLts': False,
        'isEol': is_eol,
        'eolFrom': eol if isinstance(eol, str) else None,
        'isMaintained': not is_eol,
        'latest': {'name': cycle.get('latest', cycle['cycle'])},
    }


def legacy_cycle(cycle):
    return {
        'cycle': cycle['cycle'],
        'codename': cycle.get('codename'),
        'releaseDate': resolve(cycle.get('releaseDate')),
        'eol': resolve(cycle.get('eol')),
        'latest': cycle.get('latest', cycle['cycle']),
    }


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
        self.reply(405, {'message': 'read-only fake lifecycle source'})

    do_POST = do_PUT = do_PATCH = do_DELETE = do_HEAD = refuse_write

    def do_GET(self):
        path = urlsplit(self.path).path
        with open(FIXTURE, encoding='utf-8') as handle:
            data = json.load(handle)
        simulate = os.environ.get('MOCK_SIMULATE', '') or str(data.get('simulate') or '')
        print(f'GET {path} simulate={simulate or "-"}', flush=True)
        if simulate.startswith('delay:'):
            time.sleep(float(simulate.split(':', 1)[1]))
        if simulate == '500':
            return self.reply(500, {'message': 'simulated'})
        if simulate == '429':
            return self.reply(429, {'message': 'Too Many Requests (simulated)'})

        match = V1.match(path) or LEGACY.match(path)
        if not match:
            return self.reply(404, {'message': 'not found'})
        cycles = data.get('products', {}).get(match.group(1))
        if cycles is None:
            return self.reply(404, {'message': 'Product not found'})
        if V1.match(path):
            return self.reply(200, {
                'schema_version': '1.2.0',
                'result': {'name': match.group(1), 'releases': [v1_release(c) for c in cycles]},
            })
        return self.reply(200, [legacy_cycle(c) for c in cycles])


if __name__ == '__main__':
    ThreadingHTTPServer(('0.0.0.0', PORT), Handler).serve_forever()

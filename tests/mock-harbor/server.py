"""Faux Harbor 2.x pour les tests manuels et de bout en bout (données fictives).

Répond aux seules requêtes GET que le plugin envoie, à partir d'un fichier JSON relu à chaque
requête (on peut donc pousser un nouvel artefact ou changer une analyse pendant un test) :
  GET /api/v2.0/projects?page=&page_size=                                   projets visibles ;
  GET /api/v2.0/projects/<projet>/repositories?page=&page_size=              dépôts d'un projet ;
  GET /api/v2.0/projects/<projet>/repositories/<dépôt>/artifacts?...         artefacts, tags et
      résumé d'analyse (with_scan_overview=true) ; nom de dépôt encodé deux fois (a%252Fb) ;
  GET /v2/                                                                   ping du registre ;
  GET /v2/<projet>/<dépôt>/tags/list                                         tags (repli sans WUD).
Pagination comme Harbor : page et page_size (100 au plus), en-têtes X-Total-Count et Link.
Toute autre méthode (POST, PUT, DELETE...) est refusée (405) : le plugin ne doit jamais rien
modifier dans Harbor. Aucune image n'est servie, aucune analyse n'est lancée.

Authentification basique optionnelle (compte robot) : si MOCK_USER est défini, seuls les projets
publics sont visibles sans l'en-tête attendu, et /v2/ répond 401. Le mot de passe n'est jamais écrit
dans le journal.

Format du fichier (compact, développé ici au format de l'API Harbor) : voir fixture.json. Clé
« generate » : {"<projet>/<dépôt>": N} ajoute N artefacts fictifs sans analyse (pagination).

Erreurs simulées (variable MOCK_SIMULATE, ou clé « simulate » du fichier, relue à chaque requête) :
  429          limite de requêtes atteinte ;
  500          erreur interne du serveur ;
  500:<dépôt>  erreur interne pour les artefacts de ce seul dépôt (« outils/wiki ») ;
  delay:<s>    attend <s> secondes avant de répondre (délai d'attente du client).

Usage : [MOCK_USER=... MOCK_PASSWORD=...] [MOCK_SIMULATE=...] python server.py fixture.json [port]
"""
import base64
import json
import os
import re
import sys
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, unquote, urlsplit

FIXTURE = sys.argv[1]
PORT = int(sys.argv[2]) if len(sys.argv) > 2 else 5200
USER = os.environ.get('MOCK_USER', '')
PASSWORD = os.environ.get('MOCK_PASSWORD', '')
REPORT_MIME = 'application/vnd.security.vulnerability.report; version=1.1'
PROJECTS = re.compile(r'^/api/v2\.0/projects/?$')
REPOSITORIES = re.compile(r'^/api/v2\.0/projects/([^/]+)/repositories/?$')
ARTIFACTS = re.compile(r'^/api/v2\.0/projects/([^/]+)/repositories/([^/]+)/artifacts/?$')
TAGS = re.compile(r'^/v2/(.+)/tags/list$')


def expected_authorization():
    token = base64.b64encode(f'{USER}:{PASSWORD}'.encode()).decode()
    return 'Basic ' + token


def scan_overview(scan):
    """Résumé d'analyse au format Harbor 2.x (rapport « vulnerability.report; version=1.1 »)."""
    if not scan:
        return None
    counts = {name: int(scan.get(name.lower(), 0)) for name in ('Critical', 'High', 'Medium', 'Low', 'Unknown')}
    total = sum(counts.values())
    severity = next((name for name in ('Critical', 'High', 'Medium', 'Low', 'Unknown') if counts[name] > 0), 'None')
    report = {
        'report_id': 'rapport-fictif',
        'scan_status': scan.get('status', 'Success'),
        'severity': severity,
        'duration': 12,
        'start_time': scan.get('end_time', ''),
        'end_time': scan.get('end_time', ''),
        'complete_percent': 100,
        'scanner': {'name': scan.get('scanner', 'Trivy'), 'vendor': 'Aqua Security', 'version': 'v0.57.0'},
    }
    if report['scan_status'] == 'Success':
        report['summary'] = {
            'total': total,
            'fixable': int(scan.get('fixable', 0)),
            'summary': {name: count for name, count in counts.items() if count > 0},
        }
    return {REPORT_MIME: report}


def expand_artifact(index, repository_id, artifact):
    return {
        'id': index,
        'type': 'IMAGE',
        'media_type': 'application/vnd.oci.image.manifest.v1+json',
        'manifest_media_type': 'application/vnd.oci.image.manifest.v1+json',
        'repository_id': repository_id,
        'digest': artifact['digest'],
        'size': artifact.get('size', 0),
        'push_time': artifact.get('push_time', ''),
        'pull_time': '0001-01-01T00:00:00.000Z',
        'tags': [
            {'id': index * 10 + i, 'name': name, 'push_time': artifact.get('push_time', ''), 'immutable': False}
            for i, name in enumerate(artifact.get('tags', []))
        ] or None,
        'references': [{'child_digest': d, 'platform': {'architecture': 'arm64', 'os': 'linux'}}
                       for d in artifact.get('children', [])] or None,
        'scan_overview': scan_overview(artifact.get('scan')),
        # Champ inconnu du plugin : doit être ignoré.
        'extra_attrs': {'architecture': 'amd64', 'os': 'linux'},
    }


def projects_of(data):
    projects = []
    for project in data.get('projects', []):
        repositories = {name: list(items) for name, items in project.get('repositories', {}).items()}
        for full, count in (data.get('generate') or {}).items():
            name, _, repo = full.partition('/')
            if name == project['name']:
                repositories.setdefault(repo, [])
                for i in range(int(count)):
                    repositories[repo].append({
                        'digest': 'sha256:' + format(i, '064x'),
                        'tags': [f'build-{i}'],
                        'push_time': '2026-10-01T08:00:00.000Z',
                        'size': 1000 + i,
                    })
        projects.append(dict(project, repositories=repositories))
    return projects


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        pass

    def reply(self, status, payload, total=None, page=1, size=10):
        body = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        if total is not None:
            self.send_header('X-Total-Count', str(total))
            if page * size < total:
                # Référence relative limitée à la requête (RFC 3986) : aucune donnée de la requête dans l'en-tête
                # (CodeQL py/http-response-splitting).
                self.send_header('Link', f'<?page={page + 1}&page_size={size}>; rel="next"')
        self.end_headers()
        self.wfile.write(body)

    def page(self, items, query):
        page = max(1, int((query.get('page') or ['1'])[0]))
        size = max(1, min(100, int((query.get('page_size') or ['10'])[0])))
        chunk = items[(page - 1) * size:page * size]
        return self.reply(200, chunk, total=len(items), page=page, size=size)

    def refuse_write(self):
        print(f'{self.command} {self.path} refused (read-only mock)', flush=True)
        self.reply(405, {'errors': [{'code': 'METHOD_NOT_ALLOWED', 'message': 'read-only fake Harbor'}]})

    do_POST = do_PUT = do_PATCH = do_DELETE = do_HEAD = refuse_write

    def do_GET(self):
        url = urlsplit(self.path)
        path = url.path
        query = parse_qs(url.query)
        with open(FIXTURE, encoding='utf-8') as handle:
            data = json.load(handle)
        simulate = os.environ.get('MOCK_SIMULATE', '') or str(data.get('simulate') or '')
        authorized = USER == '' or self.headers.get('Authorization') == expected_authorization()
        print(f'GET {self.path} auth_ok={authorized} simulate={simulate or "-"}', flush=True)
        if simulate.startswith('delay:'):
            time.sleep(float(simulate.split(':', 1)[1]))
        if simulate == '500':
            return self.reply(500, {'errors': [{'code': 'UNKNOWN', 'message': 'simulated'}]})
        if simulate == '429':
            return self.reply(429, {'errors': [{'code': 'TOO_MANY_REQUESTS', 'message': 'simulated'}]})

        visible = [p for p in projects_of(data) if authorized or p.get('public')]
        by_name = {p['name']: p for p in visible}

        if path in ('/v2/', '/v2'):
            if not authorized:
                return self.reply(401, {'errors': [{'code': 'UNAUTHORIZED', 'message': 'unauthorized'}]})
            return self.reply(200, {})
        if PROJECTS.match(path):
            items = [{
                'project_id': p['project_id'],
                'name': p['name'],
                'repo_count': len(p['repositories']),
                'metadata': {'public': 'true' if p.get('public') else 'false'},
                'creation_time': '2026-01-05T09:00:00.000Z',
            } for p in visible]
            return self.page(items, query)
        match = REPOSITORIES.match(path)
        if match:
            project = by_name.get(unquote(match.group(1)))
            if project is None:
                return self.reply(404 if authorized else 401, {'errors': [{'code': 'NOT_FOUND', 'message': 'project'}]})
            items = [{
                'id': project['project_id'] * 100 + i,
                'project_id': project['project_id'],
                'name': project['name'] + '/' + name,
                'artifact_count': len(artifacts),
                'pull_count': 0,
                'update_time': '2026-11-10T08:00:00.000Z',
            } for i, (name, artifacts) in enumerate(sorted(project['repositories'].items()))]
            return self.page(items, query)
        match = ARTIFACTS.match(path)
        if match:
            project = by_name.get(unquote(match.group(1)))
            # Harbor attend le nom du dépôt encodé deux fois : « anciens%252Fportail ».
            repo = unquote(unquote(match.group(2)))
            if project is None or repo not in project['repositories']:
                return self.reply(404, {'errors': [{'code': 'NOT_FOUND', 'message': 'repository'}]})
            if simulate == '500:' + project['name'] + '/' + repo:
                return self.reply(500, {'errors': [{'code': 'UNKNOWN', 'message': 'simulated'}]})
            with_scan = (query.get('with_scan_overview') or ['false'])[0] == 'true'
            items = []
            for i, artifact in enumerate(project['repositories'][repo]):
                item = expand_artifact(i + 1, project['project_id'] * 100, artifact)
                if not with_scan:
                    item.pop('scan_overview')
                items.append(item)
            return self.page(items, query)
        listing = TAGS.match(path)
        if listing:
            name, _, repo = listing.group(1).partition('/')
            project = by_name.get(name)
            if project is None:
                return self.reply(401 if not authorized else 404,
                                  {'errors': [{'code': 'NAME_UNKNOWN', 'message': 'repository name not known'}]})
            if repo not in project['repositories']:
                return self.reply(404, {'errors': [{'code': 'NAME_UNKNOWN', 'message': 'repository name not known'}]})
            tags = [t for a in project['repositories'][repo] for t in a.get('tags', [])]
            return self.reply(200, {'name': listing.group(1), 'tags': tags})
        return self.reply(404, {'errors': [{'code': 'NOT_FOUND', 'message': 'not found'}]})


if __name__ == '__main__':
    ThreadingHTTPServer(('0.0.0.0', PORT), Handler).serve_forever()

# Architecture

Plugin GLPI « Inventaire Docker » : clé `dockerinventory` (dossier `plugins/dockerinventory`), espace de
noms `GlpiPlugin\Dockerinventory`.

## Arborescence

Les éléments marqués *(prévu)* n'existent pas encore.

```text
dockerinventory/
├── setup.php                  version, compatibilité GLPI 11 et 12, hooks
├── hook.php                   installation, mise à jour, désinstallation
├── composer.json
├── front/                     pages (une par écran)
│   ├── connection.php         liste des connexions Portainer, WUD et Harbor (droit Configuration en modification)
│   ├── connection.form.php    choix du type, formulaire, « Tester la connexion », « Importer maintenant »,
│   │                          « Vérifier les registres maintenant »
│   ├── service.php            liste des services importés (recherche GLPI)
│   ├── service.form.php       fiche d'un service, en lecture seule
│   ├── version.php            « Versions des images » : compteurs, filtres, badges (Twig)
│   └── imageversion.php       recherche GLPI sur les versions (vues enregistrées, exports)
├── src/
│   ├── Compat/
│   │   ├── GlpiVersion.php    GLPI 11 ou 12
│   │   ├── Http.php           GET par le client HTTP de GLPI, sans redirection
│   │   └── Base/              classes parentes : propriétés typées (GLPI 12) ou non (GLPI 11)
│   ├── Install/Installer.php  tables, droit, tâche automatique, préférences d'affichage ; désinstallation
│   ├── Connection.php         connexion Portainer, WUD ou Harbor (type figé à la création) : URL, clé ou
│   │                          mot de passe chiffré (GLPIKey), utilisateur WUD ou compte robot Harbor, hôte
│   │                          du registre (Harbor), environnement des applications créées, dernier compte
│   │                          rendu ; tâches automatiques horaires
│   ├── Importer.php           import Portainer : stacks, services, nœuds, tâches en cours, retraits ;
│   │                          hôtes autonomes : stacks et projets Compose, conteneurs, hôte
│   ├── ImageVersion.php       version d'image d'un service (ou d'un conteneur sans service) ; onglet
│   │                          « Versions » des services et des applications, page et recherche
│   ├── Service.php            service Swarm, service Compose ou conteneur autonome (lecture seule) ;
│   │                          onglet « Services » des applications
│   ├── Node.php               nœud Swarm ou hôte autonome ; onglet « Services du nœud » des ordinateurs
│   ├── Profile.php            onglet « Inventaire Docker » des profils (droit plugin_dockerinventory_service)
│   ├── Portainer/
│   │   ├── PortainerClient.php     lecture seule : /api/endpoints, /api/stacks, /docker/{services,
│   │   │                           nodes,tasks,networks} ; hôtes autonomes : /docker/{info,
│   │   │                           containers/json,images/json,containers/<id>/json} ; en-tête
│   │   │                           X-API-Key, transport injectable
│   │   ├── PortainerContext.php    contexte réseau (réseaux privés autorisés sous GLPI 12)
│   │   ├── PortainerException.php  codes d'erreur stables, jamais le texte d'origine ni la clé
│   │   ├── PortainerMapper.php     réponse Docker → valeurs enregistrées (fonctions pures)
│   │   └── UrlPolicy.php           HTTPS obligatoire sauf autorisation, ni identifiants ni requête
│   ├── Wud/
│   │   ├── WudClient.php      lecture seule de /api/app et /api/containers, authentification basique
│   │   ├── WudContext.php     contexte réseau (réseaux privés autorisés sous GLPI 12)
│   │   ├── WudException.php   codes d'erreur stables, jamais le texte d'origine ni le mot de passe
│   │   ├── WudMapper.php      conteneur WUD → valeurs lues (lecture défensive), clés d'image
│   │   └── WudImporter.php    lecture d'une connexion WUD → table des versions
│   ├── Version/
│   │   ├── VersionComparator.php  écart : majeure, mineure, correctif, préversion, empreinte seule,
│   │   │                          non suivable (`latest`...), non vérifié ; calcul de WUD repris
│   │   ├── VersionLinker.php      conteneurs → services (empreinte, nom, image et tag), une ligne
│   │   │                          par service, réplicas désynchronisés
│   │   └── TagSelector.php        dernière version parmi les tags d'un registre (même nombre de
│   │                              composantes, même variante, préversions ignorées sauf déployées)
│   ├── Registry/              repli sans WUD
│   │   ├── RegistryClient.php      lecture seule : tags Docker Hub (anonyme, 3 pages au plus) et
│   │   │                           /v2/<dépôt>/tags/list de Harbor (compte robot), transport injectable
│   │   ├── RegistryContext.php     contexte réseau (réseaux privés autorisés sous GLPI 12)
│   │   ├── RegistryException.php   codes stables, jamais le texte d'origine ni le mot de passe
│   │   ├── RegistryResolver.php    registre d'une image : Docker Hub, Harbor déclaré (hôte, entité), inconnu
│   │   └── RegistryImporter.php    services sans WUD → table des versions (source « registry »),
│   │                               cache d'un jour pour Docker Hub
│   ├── Ticket/
│   │   ├── TicketPolicy.php        seuil atteint, ticket, suivi ou rien (fonctions pures) ;
│   │   │                           déclencheur « vulnérabilité critique » réservé au lot 3
│   │   └── UpdateTickets.php       après chaque lecture : tickets et suivis, table de suivi
│   └── Harbor/                projets, dépôts, artefacts, analyses           (prévu, lot 3)
├── templates/                 Twig uniquement (aucun HTML dans le PHP)
├── locales/                   fr_FR, en_GB, de_DE, es_ES, it_IT, pl_PL, pt_BR (+ variantes régionales)
└── tests/
    ├── Unit/                  sans base de données
    ├── Integration/           import complet contre la vraie base GLPI (transport en mémoire)
    ├── security/setup.php     jeu de données des contrôles de sécurité communs
    ├── mock-portainer/        faux Portainer (Python) et jeu de données fictif
    ├── mock-wud/              faux WUD (Python, format WUD 8.x) et jeu de données fictif
    └── mock-registry/         faux registre (Python) : tags façon Docker Hub, /v2/.../tags/list façon Harbor
```

## Tables

| Table | Contenu |
| --- | --- |
| `glpi_plugin_dockerinventory_connections` | Connexion Portainer, WUD ou Harbor (`type`) : URL, clé ou mot de passe chiffré, utilisateur (WUD) ou compte robot (Harbor), hôte du registre dans les images (Harbor), HTTP autorisé ou non, entité, environnement des applications créées (Portainer), dernier import et son compte rendu |
| `glpi_plugin_dockerinventory_stacks` | Stack Swarm ou Compose de Portainer (`stack_uid`), ou projet Compose hors Portainer (`stack_uid` 0, `compose_project`) ↔ Application GLPI, nature (`kind`), état « retiré » |
| `glpi_plugin_dockerinventory_services` | Service Swarm, service Compose ou conteneur autonome (`kind`) : stack, Application, image, tag, empreinte, réplicas voulus et en cours (conteneurs et conteneurs en cours hors Swarm), ports, réseaux, volumes et secrets par leur nom, état des conteneurs, politique de redémarrage, identifiants et noms des conteneurs (rattachement WUD), état « retiré » |
| `glpi_plugin_dockerinventory_nodes` | Nœud Swarm ou hôte autonome (rôle `standalone`) : nom d'hôte, rôle, état, version de Docker, ordinateur GLPI rattaché |
| `glpi_plugin_dockerinventory_services_nodes` | Tâches en cours d'un service sur chaque nœud (recalculé à chaque import) |
| `glpi_plugin_dockerinventory_imageversions` | Une ligne par service suivi (ou par conteneur sans service, `plugin_dockerinventory_services_id` = 0) et par connexion WUD : conteneurs, image et registre, tag et empreinte déployés, dernière version, écart, réplicas désynchronisés, méthode de rattachement (ou raison de l'absence), source (`wud` ou `registry`), raison d'une version non vérifiée, date de vérification. Recalculée à chaque lecture de WUD ; repli sans WUD : une ligne par service, connexion Harbor ou 0 (Docker Hub, registre inconnu) |
| `glpi_plugin_dockerinventory_tickets` | Ticket de mise à jour suivi pour chaque service (un seul) et la situation qu'il décrit : écart, version déployée, dernière version (dernier ticket ou suivi) |
| `glpi_plugin_dockerinventory_registrytags` | Cache des tags de Docker Hub : dépôt, tags et empreintes (JSON), raison d'un échec durable (introuvable), date de lecture ; valable un jour |

## Portainer (lot 1)

- Lecture seule : uniquement des requêtes GET, en-tête `X-API-Key`. Clé chiffrée avec la clé de
  GLPI (déclarée en champ sécurisé : rechiffrée par `glpi:security:change_key`), jamais renvoyée au
  navigateur, absente de l'API REST de GLPI (`$undisclosedFields`), des journaux et des messages.
- HTTPS obligatoire, sauf case « Autoriser HTTP » ; aucune redirection suivie (la clé ne part
  jamais vers un autre serveur).
- Environnements lus : types Docker (local, agent, agent Edge) ; Kubernetes et Azure sont ignorés.
  Un environnement dont l'API Swarm répond « pas un manager Swarm » (503) est lu comme un hôte
  autonome (#25) : `/docker/info` (hôte), `/docker/containers/json?all=1`, `/docker/images/json`
  (empreintes du dépôt) et, pour chaque conteneur, `/docker/containers/<id>/json`, dont seul
  `HostConfig.RestartPolicy` est lu (jamais `Config.Env`). Stacks Portainer de type Compose (2) et
  projets Compose des conteneurs (`com.docker.compose.project`) → Applications ; un service par
  service Compose (projet et `com.docker.compose.service`), un par conteneur hors Compose ; secrets
  Compose = noms des montages `/run/secrets/<nom>` ; conteneurs de tâches Swarm ignorés. Un
  conteneur disparu entre la liste et son détail (404) n'empêche pas l'import.
- Stack Swarm → Application (Appliance) : celle déjà liée, sinon une application du même nom dans
  l'entité de la connexion, sinon une nouvelle. **C'est la seule écriture hors des tables du
  plugin.** Une application existante n'est jamais modifiée ; l'environnement (production,
  préproduction...) choisi sur la connexion n'est appliqué qu'aux applications créées. Le plugin ne
  crée jamais d'environnement (`ApplianceEnvironment`).
- Nœud → ordinateur dont le nom est le nom d'hôte complet ou court, dans l'entité de la connexion
  et ses sous-entités, s'il est unique. Aucun ordinateur n'est créé ni modifié.
- Service, stack ou nœud absent d'un environnement lu avec succès (ou d'un environnement disparu)
  → « retiré », jamais supprimé ; il redevient actif s'il réapparaît. Un environnement en erreur ne
  retire rien (import « partiel »).
- Droits : connexions réservées au droit Configuration en modification ; lecture des services par
  le droit `plugin_dockerinventory_service` (Lire), donné à l'installation aux profils qui
  administrent la configuration. Onglets affichés seulement avec ce droit.

## WUD (lot 2)

- Lecture seule de l'API de WUD : `GET /api/app` (version, affichée au test et sur la connexion) et
  `GET /api/containers` ; les déclencheurs de WUD ne sont jamais appelés. Authentification basique
  facultative, mot de passe chiffré dans la colonne `api_key` (champ sécurisé), jamais réaffiché.
- Champs lus par conteneur (documentation publique de WUD 8.x, **à valider sur une vraie instance**,
  #2) : `id`, `name`, `watcher`, `image.{registry.{name, url}, name, tag.{value, semver},
  digest.{watch, value, repo}}`, `result.{tag, digest}`, `updateAvailable`, `updateKind.{kind,
  semverDiff, localValue, remoteValue}`, `labels`, `error.message`. Champ absent toléré, champ
  inconnu ignoré.
- Écart : celui de WUD (`updateKind`) est repris tel quel ; le plugin ne le calcule que si WUD ne le
  donne pas. Tag mobile (`latest`, `stable`...) ou non versionné : « non suivable », quelle que soit
  l'empreinte. Pas encore de résultat (ou erreur de registre) : « non vérifié ».
- Rattachement au service (services actifs de l'entité de la connexion WUD et de ses sous-entités) :
  par l'identifiant ou le nom du conteneur (services des hôtes autonomes, #25), puis par l'empreinte de l'image (celle que Swarm épingle), puis par le nom du service Swarm
  (`com.docker.swarm.service.name`, ou nom de tâche `service.emplacement.tâche`), puis par image et
  tag. À chaque étape, plusieurs services possibles sont départagés par les critères suivants ;
  s'il en reste plusieurs, le conteneur est listé « plusieurs services possibles » plutôt que
  rattaché au mauvais. Conteneur Compose d'un hôte absent de Portainer : « hors Swarm non importé ».
- Une ligne par service : le conteneur qui correspond au service (même empreinte, sinon même tag)
  donne les valeurs ; des conteneurs dans des versions différentes sont signalés « réplicas
  désynchronisés » avec le détail.
- WUD injoignable ou en erreur : rien n'est modifié dans la table des versions.
- Sans WUD : le plugin interroge lui-même le registre (ci-dessous).

## Registres sans WUD (lot 2, #5)

- Services concernés : actifs, avec une image, sans ligne WUD dans la table des versions. Dès
  qu'une lecture de WUD rattache un service, sa ligne « registry » est supprimée.
- Lecture seule : `GET /v2/repositories/<espace>/<dépôt>/tags` (Docker Hub, anonyme, 100 tags par
  page, 3 pages au plus, les plus récents d'abord) ; `GET /v2/<projet>/<dépôt>/tags/list` et
  `GET /v2/` (test) pour Harbor, authentification basique du compte robot (champ sécurisé, comme
  les autres mots de passe). Aucune image n'est téléchargée.
- Docker Hub : cache d'un jour par dépôt (table `registrytags`), y compris « introuvable ou privé » ;
  un refus (429) ou une panne arrête les appels à Docker Hub pour la vérification en cours, sans
  rien mettre en cache (nouvel essai à la suivante). Adresse changeable par la constante
  `PLUGIN_DOCKERINVENTORY_DOCKER_HUB_URL` (miroir, tests).
- Harbor : la connexion active dont l'hôte du registre (champ, sinon hôte de l'adresse) est celui de
  l'image, et dont l'entité (ou une entité parente) est celle du service. Relu à chaque vérification.
- Non vérifié (écart « non vérifié », raison enregistrée sous forme de code et traduite à
  l'affichage) : registre inconnu, privé sans compte (401 sans identifiants), image introuvable,
  limite ou panne. Seules les vraies erreurs d'appel rendent la vérification « partielle ».
- Dernière version (`TagSelector`) : tags versionnés seulement, même nombre de composantes, même
  variante, préversions ignorées sauf si une préversion est déployée (même canal), `v` facultatif.
  Docker Hub donne l'empreinte de chaque tag : même tag reconstruit → « empreinte seule » ; tag
  mobile → « non suivable », avec « nouvelle empreinte disponible » si elle a changé.

## Tickets de mise à jour (lot 2, #7)

- Réglages sur la connexion Portainer des services (colonnes `ticket_*`), désactivés par défaut :
  seuil (`major`, `minor`, `patch` : écart minimal), catégorie, groupe affecté à la création, modèle
  de ticket (champs prédéfinis appliqués comme valeurs de départ, sans contrôle des champs
  obligatoires du modèle).
- Appelé à la fin de chaque lecture de WUD (services rattachés) et de chaque vérification des
  registres (services vérifiés), donc par les tâches automatiques et les boutons. Situation d'un
  service : sa ligne de version la plus grave.
- `TicketPolicy::decide()` : ticket si un déclencheur est atteint et qu'aucun ticket ouvert n'existe
  (un ticket fermé pour la même dernière version n'est pas recréé) ; suivi si le ticket est ouvert
  et que l'écart, la version déployée ou la dernière version a changé ; rien si la version n'est pas
  vérifiée.
- Ticket : type demande (sauf modèle), entité du service, application de la stack en élément lié,
  description et suivis en Twig (`ticket_content`, `ticket_followup`). Suivis ajoutés avec
  `_no_reopen`, `_do_not_compute_status` et `_do_not_compute_takeintoaccount` : ni statut, ni
  priorité, ni affectation modifiés.
- Lot 3 : `TicketPolicy::TRIGGER_VULNERABILITY` (paramètre `$signals` de `triggers()`), avec son
  propre réglage sur la connexion.
- Désinstallation : la table de suivi est supprimée, les tickets restent (objets GLPI).

## Règles communes

- Aucune action d'écriture vers Portainer, WUD, Harbor ou un registre (uniquement des GET).
- Aucun changement automatique de statut, priorité, affectation ou droits dans GLPI.
- Pas de SQL brut hors de l'installateur ; Twig pour tout l'affichage.
- Appels HTTP par le client de GLPI ; sous GLPI 12, les adresses privées ne sont joignables que si
  le contexte du plugin est autorisé (`GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS`).

## Tests

- `vendor/bin/phpunit` (dans une instance GLPI où le plugin est installé) : tests unitaires
  (correspondances, adresses, client) et d'intégration (import complet, idempotence, retraits).
- Faux serveurs Python dans `tests/mock-*` : jeux de données fictifs, aucune adresse ni nom réel ;
  les tests n'appellent jamais le vrai Docker Hub (transports en mémoire, adresse fictive).
- Test dans une vraie instance GLPI 11 et 12 avant chaque version, en naviguant comme un
  utilisateur.

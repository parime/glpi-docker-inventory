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
│   ├── config.php             page de configuration du plugin (Configuration > Plugins), en onglets :
│   │                          connexions, registres maîtrisés, fin de support (droit Configuration en
│   │                          modification) ; seul endroit où le plugin se règle (#35)
│   ├── connection.php         ancienne adresse de la liste des connexions : redirige vers config.php
│   ├── connection.form.php    choix du type, formulaire, « Tester la connexion », « Importer maintenant »,
│   │                          « Vérifier les registres maintenant »
│   ├── service.php            liste des services importés (recherche GLPI) : page d'accueil de la seule
│   │                          entrée de menu, Parc > Inventaire Docker
│   ├── service.form.php       fiche d'un service, en lecture seule
│   ├── version.php            « Versions des images » : compteurs, filtres, badges (Twig) ;
│   │                          sous-pages « Images Harbor et vulnérabilités » (?view=harbor) et
│   │                          « Fin de support des images » (?view=eol, lecture seule)
│   ├── imageversion.php       recherche GLPI sur les versions (vues enregistrées, exports)
│   └── lifecycle.php          ancienne adresse de la fin de support : redirige vers l'onglet de
│                              config.php (administrateurs) ou vers la sous-page de lecture (autres)
├── src/
│   ├── Compat/
│   │   ├── GlpiVersion.php    GLPI 11 ou 12
│   │   ├── Http.php           GET par le client HTTP de GLPI, sans redirection
│   │   └── Base/              classes parentes : propriétés typées (GLPI 12) ou non (GLPI 11)
│   ├── Exposure.php           exposition des services (#72) : onglet « Exposition » des services,
│   │                          sous-page de l'entrée, flux proposés et documentés dans GRC Manager
│   ├── Exposure/
│   │   └── ExposureMapper.php routes Traefik (étiquettes), ingress et services Kubernetes, ports
│   │                          publiés, portée, flux proposé (fonctions pures)
│   ├── Hardening.php          configuration à risque (#71) : onglet des services, sous-page, synthèse
│   ├── HardeningException.php exception justifiée et datée sur un écart (droit propre, CREATE et PURGE)
│   ├── Hardening/
│   │   └── HardeningMapper.php réglages de sécurité Docker, Swarm et Kubernetes => écarts et gravités
│   │                          (fonctions pures)
│   ├── Menu.php               seule entrée de menu d'usage (Parc > Inventaire Docker) et onglets de
│   │                          ses sous-pages (templates/_section_nav.html.twig)
│   ├── ConfigPage.php         onglets et adresses de la page de configuration du plugin
│   ├── Install/Installer.php  tables, droit, tâche automatique, préférences d'affichage ; désinstallation
│   ├── Connection.php         connexion Portainer, WUD ou Harbor (type figé à la création) : URL, clé ou
│   │                          mot de passe chiffré (GLPIKey), utilisateur WUD ou compte robot Harbor, hôte
│   │                          du registre (Harbor), environnement des applications créées, dernier compte
│   │                          rendu ; tâches automatiques horaires
│   ├── Importer.php           import Portainer : stacks, services, nœuds, tâches en cours, retraits ;
│   │                          hôtes autonomes : stacks et projets Compose, conteneurs, hôte
│   ├── InventoryStore.php     écritures communes des imports (Portainer, Kubernetes) : stacks et
│   │                          applications, services, nœuds rattachés (nom ou adresse IP), répartition,
│   │                          retraits, compte rendu
│   ├── ImageVersion.php       version d'image d'un service (ou d'un conteneur sans service) ; onglet
│   │                          « Versions » des services et des applications, page et recherche
│   ├── Service.php            service Swarm, service Compose ou conteneur autonome (lecture seule) ;
│   │                          onglet « Services » des applications
│   ├── Node.php               nœud Swarm ou hôte autonome ; onglet « Services du nœud » des ordinateurs
│   ├── Profile.php            onglet « Inventaire Docker » des profils (droit plugin_dockerinventory_service)
│   ├── HarborArtifact.php     artefact Harbor (lecture seule) : rattachement d'un service, provenance,
│   │                          carte de l'onglet « Versions », sous-page « Images Harbor » de l'entrée de menu
│   ├── LifecycleProduct.php   correspondance image → produit (table modifiable), états du support,
│   │                          onglet de la configuration du plugin, tâche quotidienne `lifecyclecheck`
│   ├── Lifecycle/             fin de support des images (#26)
│   │   ├── LifecycleClient.php     lecture seule : GET d'un produit (API v1 d'endoflife.date ou
│   │   │                           ancienne API), transport injectable, adresse changeable
│   │   ├── LifecycleContext.php    contexte réseau (réseaux privés autorisés sous GLPI 12)
│   │   ├── LifecycleException.php  codes d'erreur stables, jamais le texte d'origine
│   │   ├── ProductMap.php          correspondances fournies, nom d'image normalisé (fonctions pures)
│   │   ├── CycleMatcher.php        branche d'un tag, état et jours restants (fonctions pures)
│   │   ├── LifecycleChecker.php    cache d'un jour, panne sans blocage, évaluation à l'affichage
│   │   └── Settings.php            réglages (configuration de GLPI, désactivé par défaut)
│   ├── Portainer/
│   │   ├── PortainerClient.php     lecture seule : /api/endpoints, /api/stacks, /docker/{services,
│   │   │                           nodes,tasks,networks} ; hôtes autonomes : /docker/{info,
│   │   │                           containers/json,images/json,containers/<id>/json} ; en-tête
│   │   │                           X-API-Key, transport injectable
│   │   ├── PortainerContext.php    contexte réseau (réseaux privés autorisés sous GLPI 12)
│   │   ├── PortainerException.php  codes d'erreur stables, jamais le texte d'origine ni la clé
│   │   ├── PortainerMapper.php     réponse Docker → valeurs enregistrées (fonctions pures)
│   │   └── UrlPolicy.php           HTTPS obligatoire sauf autorisation, ni identifiants ni requête
│   ├── Kubernetes/            source Kubernetes (#31)
│   │   ├── KubernetesClient.php    lecture seule : GET /version, /api/v1/{namespaces,nodes,pods},
│   │   │                           /apis/apps/v1/{deployments,statefulsets,daemonsets,replicasets},
│   │   │                           /apis/batch/v1/cronjobs, /api/v1/services,
│   │                           /apis/networking.k8s.io/v1/ingresses ; jeton Bearer, limit/continue, certificat
│   │   │                           d'autorité de la connexion, transport injectable
│   │   ├── KubernetesContext.php   contexte réseau (réseaux privés autorisés sous GLPI 12)
│   │   ├── KubernetesException.php codes stables, jamais le texte d'origine ni le jeton
│   │   ├── KubernetesMapper.php    réponses => nœuds, charges (une par conteneur), pods => empreintes et
│   │   │                           répartition, états (fonctions pures)
│   │   └── KubernetesImporter.php  lecture d'une connexion Kubernetes, retraits, garde-fous
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
│   │   ├── UpdateTickets.php       après chaque lecture : tickets et suivis, table de suivi
│   │   ├── EolTicketPolicy.php     ticket de fin de support, suivi ou rien (fonctions pures)
│   │   ├── EolTickets.php          après chaque mise à jour des cycles de vie : tickets et suivis
│   │   ├── VulnTicketPolicy.php    déclencheur « vulnérabilité » : ticket, suivi ou rien (fonctions pures)
│   │   └── VulnTickets.php         après chaque lecture de Harbor : tickets et suivis
│   └── Harbor/                projets, dépôts, artefacts, analyses (#9)
│       ├── HarborClient.php        lecture seule : GET /api/v2.0/projects, .../repositories,
│       │                           .../artifacts?with_scan_overview=true ; pagination, compte robot
│       ├── HarborMapper.php        réponses => valeurs (projet, artefact, résumé d'analyse ; fonctions pures)
│       ├── HarborLinker.php        empreinte, tag ou empreinte différente ; provenance (fonctions pures)
│       ├── HarborImporter.php      lecture d'une connexion Harbor, retraits, compte rendu
│       └── HarborSettings.php      registres maîtrisés (configuration de GLPI)
├── templates/                 Twig uniquement (aucun HTML dans le PHP)
├── locales/                   fr_FR, en_GB, de_DE, es_ES, it_IT, pl_PL, pt_BR (+ variantes régionales)
└── tests/
    ├── Unit/                  sans base de données
    ├── Integration/           import complet contre la vraie base GLPI (transport en mémoire)
    ├── security/setup.php     jeu de données des contrôles de sécurité communs
    ├── mock-portainer/        faux Portainer (Python) et jeu de données fictif
    ├── mock-wud/              faux WUD (Python, format WUD 8.x) et jeu de données fictif
    ├── mock-registry/         faux registre (Python) : tags façon Docker Hub, /v2/.../tags/list façon Harbor
    ├── mock-harbor/           faux Harbor 2.x (Python) : projets, dépôts, artefacts, analyses, tags
    ├── mock-kubernetes/       faux API Kubernetes (Python, HTTPS facultatif) : listes paginées, 403, 429
    └── mock-lifecycle/        fausse source des cycles de vie (Python, API v1 et ancienne API), dates
                               relatives (+30d) et cycles fictifs
```

## Tables

| Table | Contenu |
| --- | --- |
| `glpi_plugin_dockerinventory_connections` | Connexion Portainer, WUD, Harbor ou Kubernetes (`type`) : URL, clé, jeton ou mot de passe chiffré, certificat d'autorité et namespaces ignorés (Kubernetes), utilisateur (WUD) ou compte robot (Harbor), hôte du registre dans les images (Harbor), HTTP autorisé ou non, entité, environnement des applications créées (Portainer), dernier import et son compte rendu |
| `glpi_plugin_dockerinventory_stacks` | Stack Swarm ou Compose de Portainer (`stack_uid`), ou projet Compose hors Portainer (`stack_uid` 0, `compose_project`) ↔ Application GLPI, nature (`kind`), état « retiré » |
| `glpi_plugin_dockerinventory_services` | Service Swarm, service Compose ou conteneur autonome (`kind`) : stack, Application, image, tag, empreinte, réplicas voulus et en cours (conteneurs et conteneurs en cours hors Swarm), ports, réseaux, volumes et secrets par leur nom, état des conteneurs, politique de redémarrage, identifiants et noms des conteneurs (rattachement WUD), état « retiré » |
| `glpi_plugin_dockerinventory_nodes` | Nœud Swarm ou hôte autonome (rôle `standalone`) : nom d'hôte, rôle, état, version de Docker, ordinateur GLPI rattaché |
| `glpi_plugin_dockerinventory_services_nodes` | Tâches en cours d'un service sur chaque nœud (recalculé à chaque import) |
| `glpi_plugin_dockerinventory_imageversions` | Une ligne par service suivi (ou par conteneur sans service, `plugin_dockerinventory_services_id` = 0) et par connexion WUD : conteneurs, image et registre, tag et empreinte déployés, dernière version, écart, réplicas désynchronisés, méthode de rattachement (ou raison de l'absence), source (`wud` ou `registry`), raison d'une version non vérifiée, date de vérification. Recalculée à chaque lecture de WUD ; repli sans WUD : une ligne par service, connexion Harbor ou 0 (Docker Hub, registre inconnu) |
| `glpi_plugin_dockerinventory_tickets` | Ticket de mise à jour suivi pour chaque service (un seul) et la situation qu'il décrit : écart, version déployée, dernière version (dernier ticket ou suivi) |
| `glpi_plugin_dockerinventory_lifecycleproducts` | Correspondance image normalisée → produit de la source (`is_default` : fournie à l'installation) |
| `glpi_plugin_dockerinventory_lifecycles` | Cache des cycles de vie d'un produit : branches (JSON : nom, nom de code, fin de support, dernière version), erreur, dernier essai, dernière lecture réussie ; valable un jour |
| `glpi_plugin_dockerinventory_eoltickets` | Ticket de fin de support suivi pour chaque service (un seul) et la branche qu'il décrit |
| `glpi_plugin_dockerinventory_harborprojects` | Projet d'une connexion Harbor : nom, public ou non, nombre de dépôts, état « retiré » |
| `glpi_plugin_dockerinventory_harborartifacts` | Artefact d'un dépôt Harbor (une ligne par connexion, dépôt et empreinte) : projet, tags, empreintes par plateforme, taille, date de publication, état de l'analyse, gravité, analyseur, date d'analyse, nombre de vulnérabilités par gravité et corrigibles, état « retiré » |
| `glpi_plugin_dockerinventory_vulntickets` | Ticket de vulnérabilité suivi pour chaque service (un seul) et l'image qu'il décrit : empreinte, nombre de vulnérabilités critiques et hautes |
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
- Lot 3 : `TicketPolicy::TRIGGER_VULNERABILITY`, évalué par `VulnTicketPolicy`, avec son propre
  réglage et son propre ticket par service (voir Harbor ci-dessous).
- Désinstallation : la table de suivi est supprimée, les tickets restent (objets GLPI).

## Fin de support des images (#26)

- Désactivé par défaut (réglage `lifecycle_enabled` du contexte `plugin:dockerinventory` de la
  configuration de GLPI) : tant qu'il l'est, aucun appel sortant et aucune évaluation affichée.
- Source : [endoflife.date](https://endoflife.date), `GET /api/v1/products/<produit>/` (seul le nom du
  produit est envoyé) ; l'ancienne API (`/api/<produit>.json`) est aussi lue. Adresse changeable par
  la constante `PLUGIN_DOCKERINVENTORY_LIFECYCLE_URL` (modèle avec `{product}` : miroir, tests).
- Produits lus : ceux des images en service (services actifs, conteneurs WUD sans service) qui ont
  une correspondance ; au plus une lecture par produit et par jour (y compris « produit inconnu »,
  404). Une limite (429) ou une panne arrête les appels pour la mise à jour en cours, garde les
  cycles déjà lus (dernière lecture réussie affichée) et rend la mise à jour « partielle ».
- Correspondance explicite, jamais devinée : image normalisée (sans tag ni empreinte, `docker.io` et
  `library/` retirés, hôte gardé pour les autres registres) ; 33 images officielles fournies à
  l'installation, une correspondance supprimée n'est pas rajoutée par les mises à jour suivantes.
- Évaluation à l'affichage (`CycleMatcher`) à partir du cache : branche la plus précise qui commence
  la version, tag moins précis suivant la branche la plus récente, nom de code ; fin de support
  passée ou du jour : « fin de support » ; dans le seuil (90 jours par défaut) : « bientôt » ; sinon
  « maintenue » ; sans correspondance, tag non versionné, produit inconnu, branche absente ou source
  non lue : « inconnue », jamais « maintenue ».
- Tickets (`EolTickets`, désactivés par défaut, réglés sur la connexion Portainer : `ticket_eol_enabled`,
  `ticket_eol_days`) après chaque mise à jour des cycles de vie ; mêmes catégorie, groupe et modèle
  que les tickets de mise à jour ; un ticket par service, des suivis, jamais de changement de statut.
- Désinstallation : tables et réglages supprimés, tickets gardés.
- Pas encore de colonne « fin de support » dans la recherche GLPI des versions (valeur calculée à
  l'affichage) ; preuve GRC Manager (A.8.8) avec #10.

## Harbor (lot 3, #9)

- Lecture seule de l'API REST de Harbor 2.x avec le compte robot de la connexion Harbor
  (authentification basique, champ sécurisé) : `GET /api/v2.0/projects`,
  `GET /api/v2.0/projects/<projet>/repositories`,
  `GET /api/v2.0/projects/<projet>/repositories/<dépôt>/artifacts?with_tag=true&with_scan_overview=true`
  (nom de dépôt encodé deux fois, en-tête `X-Accept-Vulnerabilities`). Aucune analyse lancée.
- Pagination : `page` et `page_size=100` ; une page incomplète est la dernière ; 20 pages au plus
  par liste (au-delà, la liste est « incomplète » et rien n'en est retiré).
- Résumé d'analyse : premier rapport de `scan_overview` qui a un état ; état (réussie, en échec, en
  cours, en attente, arrêtée, jamais analysée), analyseur, fin d'analyse, `summary.summary` par
  gravité (Critical, High, Medium, Low ; le reste en « autres »), `summary.fixable`. Gravité
  seulement pour une analyse réussie (« aucune vulnérabilité » si rien) ; sinon « non analysée ».
- Retraits : artefact absent d'un dépôt lu en entier, dépôt absent d'un projet lu en entier, projet
  absent de la liste lue en entier => « retiré » (jamais supprimé, réactivé s'il réapparaît).
  Erreur sur un dépôt : la suite continue, lecture « partielle » ; limite (429) : plus d'appel
  jusqu'à la lecture suivante ; liste des projets en erreur : échec, rien n'est modifié.
- Rattachement d'un service (à l'affichage et pour les tickets, rien n'est stocké) : connexion
  Harbor de l'image (même règle que le repli des registres : hôte et entité), puis artefacts actifs
  du dépôt : empreinte (ou empreinte par plateforme), sinon tag si l'empreinte déployée est inconnue,
  sinon « empreinte différente » (empreinte connue absente, tag présent : index multi-plateforme
  gardé par Docker 29 alors que Harbor n'a que le manifeste d'une plateforme, ou reconstruction ;
  gravité du tag affichée et prise en compte pour les tickets). Pastille : gravité si l'analyse a
  réussi, sinon état de l'analyse (en cours, en attente, en échec, arrêtée).
- Provenance : hôte de l'image comparé aux registres maîtrisés (réglage `harbor_managed_registries`
  du contexte `plugin:dockerinventory`, saisi sur la page de configuration du plugin ; vide : hôtes
  des connexions Harbor actives ; aucun : « non évaluée »).
- Affichage sans nouvelle entrée de menu : sous-page de Parc > Inventaire Docker (`?view=harbor`) et carte
  de l'onglet « Versions » des services, droit de lecture des services ; entités actives.
- Tickets (`VulnTickets`, désactivés par défaut : `ticket_vuln_enabled`, `ticket_vuln_severity` sur
  la connexion Portainer) après chaque lecture de Harbor ; mêmes catégorie, groupe et modèle que les
  tickets de mise à jour ; un ticket par service, suivi si l'empreinte ou le nombre de
  vulnérabilités critiques ou hautes change, jamais de changement de statut ; ticket clos : pas de
  nouveau ticket pour la même empreinte.
- Désinstallation : tables et réglage supprimés, tickets gardés.
- Corrélation des vulnérabilités de GRC Manager : #10.

## Kubernetes (#31)

- Lecture seule de l'API du cluster avec le jeton d'un compte de service (ClusterRole `get`, `list`,
  `watch` sur namespaces, nodes, pods, deployments, statefulsets, daemonsets, replicasets,
  cronjobs) : uniquement des GET, jamais de « watch ». Jeton chiffré dans `api_key` (champ
  sécurisé), jamais réaffiché ; HTTPS obligatoire ; certificat d'autorité facultatif
  (`ca_certificate`, PEM vérifié par OpenSSL, écrit dans un fichier temporaire nommé d'après son
  empreinte et passé au client HTTP de GLPI : `cafile` sous GLPI 12, `verify` sous GLPI 11).
- Listes de tout le cluster paginées (`limit=500`, `continue`), 40 pages au plus (au-delà : lue en
  partie, rien n'en est retiré). 403 sur une liste : avertissement, rien n'en est retiré ; 429 :
  plus aucun appel jusqu'à l'import suivant, import « partiel » ; 401 ou namespaces illisibles :
  échec, rien n'est modifié.
- Mêmes tables que Portainer : namespace = stack de nature `namespace` (`endpoint_uid` 0,
  `compose_project` = nom), application créée seulement pour un namespace qui a des charges ;
  conteneur d'une charge = service (`kind` : `deployment`, `statefulset`, `daemonset`, `cronjob` ;
  `service_uid` « k8s/nature/namespace/nom/conteneur », `stack_name` = namespace, `mode` =
  planification d'un CronJob, `state` = état `k8s_*`) ; nœud = nœud (rôle `k8s_control_plane` ou
  `k8s_worker`, version du kubelet), rattaché à l'ordinateur du même nom, sinon de la même adresse
  IP (`glpi_ipaddresses`). Pods rattachés à leur charge par leurs propriétaires (ReplicaSet =>
  Deployment, Job « <cronjob>-<minutes> » => CronJob) : empreinte la plus vue (imageID) et pods en
  cours par nœud. Init containers, Jobs isolés et pods sans contrôleur ignorés.
- Namespaces ignorés (`k8s_excluded_namespaces`, défaut `kube-system, kube-public,
  kube-node-lease`, `*` accepté) : leurs charges ne sont pas importées (retirées si elles l'étaient).
- Retraits : services des natures lues en entier ; namespaces si toutes les natures sont lues ; nœuds
  si leur liste est lue ; aucun si l'import ne trouve plus aucune charge alors que des services
  étaient actifs (avertissement). Pods illisibles : l'empreinte enregistrée est gardée.
- Versions (WUD, registres), Harbor et tickets : rien de propre à Kubernetes ; les services sont
  traités comme ceux de Portainer, tickets réglés sur la connexion Kubernetes
  (`Connection::WORKLOAD_TYPES`).

## Configuration à risque (#71)

- Relevée à l'import, sans requête de plus, dans la colonne `hardening` des services (JSON, liste de
  `{code, detail}` ; `[]` : analysé sans écart ; NULL : jamais analysé) :
  - hôte autonome : détail du conteneur déjà lu pour la politique de redémarrage,
    `HostConfig.{Privileged, NetworkMode, PidMode, IpcMode, CapAdd, Memory, NanoCpus, CpuQuota,
    ReadonlyRootfs, Binds}`, `Config.User`, `Mounts[].{Type, Source}` ; un service Compose réunit les
    écarts de ses conteneurs ; sans détail lu, l'analyse précédente est gardée ;
  - Swarm : `Spec.TaskTemplate.ContainerSpec.{User, CapabilityAdd, ReadOnly, Mounts}`,
    `Resources.Limits`, réseau `host` ;
  - Kubernetes : modèle de pod (`hostNetwork`, `hostPID`, `hostIPC`, `securityContext`, volumes
    `hostPath` montés par le conteneur, `resources.limits`).
  Jamais `Config.Env`, les commandes ni le contenu des secrets.
- Gravités (`HardeningMapper::RULES`) : critique (privilégié, socket d'un moteur), haute (réseau ou PID
  de l'hôte, capacités dangereuses, répertoire sensible), moyenne (IPC de l'hôte, autres capacités,
  root), basse (limites, écriture).
- Exceptions : table `glpi_plugin_dockerinventory_hardeningexceptions` (une par service et par code,
  entité du service, raison, date de fin à 366 jours au plus, auteur, historique). Écrites seulement par
  `front/hardening.form.php` (droit `plugin_dockerinventory_hardeningexception` CREATE ou PURGE, et
  service visible). Supprimées avec le service.
- Preuve : `Evidence::hardening()` (taux sans écart ouvert, sans écart critique ni haut, répartition).

## Exposition (#72)

- Relevée à l'import, en lecture seule, dans la colonne `routes` des services (JSON) :
  - Portainer : étiquettes Traefik des services Swarm (`Spec.Labels`) et des conteneurs (`Labels`) :
    `traefik.http.routers.<routeur>.{rule, tls, tls.certresolver, entrypoints}`,
    `traefik.tcp.routers.<routeur>.rule` (`HostSNI`), `traefik.http.services.<s>.loadbalancer.server.port`
    et l'ancienne forme `traefik.frontend.rule` ; `traefik.enable=false` : rien. Seuls `Host`, `HostSNI`,
    `Path` et `PathPrefix` sont lus dans la règle ;
  - Kubernetes : services (`/api/v1/services`) rattachés aux charges dont le modèle de pod porte toutes
    les étiquettes du sélecteur ; ports des services NodePort et LoadBalancer (adresse du répartiteur) ;
    ingress (`/apis/networking.k8s.io/v1/ingresses`) : hôte, chemin, TLS (hôte listé dans `spec.tls`),
    classe, service de destination. Routes recalculées seulement si les deux listes sont lues en entier ;
    sinon celles du dernier import sont gardées (403 : avertissement).
- Ports publiés : colonne `ports` (Portainer). Pour Kubernetes, `ports` contient les ports des
  conteneurs, qui ne sont pas une exposition.
- Portée (à l'affichage) : nom d'hôte public ou adresse publique => « Internet (probable) » ; nom sans
  point, suffixes internes (`.local`, `.lan`, `.internal`, `.intranet`, `.svc`, `.cluster.local`...),
  adresse privée ou port de nœud => « réseau interne » ; port publié sur toutes les adresses => « réseau de
  l'hôte » ; adresse de bouclage => « hôte seul » (aucun flux proposé). Les adresses de documentation
  (RFC 5737, RFC 3849) comptent comme publiques. Une route HTTP est comptée sur le port 443 avec TLS, 80
  sinon.
- GRC Manager (`GlpiPlugin\Grcmanager\NetworkFlow`, plugin actif et droit de lecture de sa matrice) :
  lecture seule des flux actifs (`destination_itemtype`, `destination_items_id`, `destination_label`,
  `protocol`, `ports`). Exposition documentée : même destination (application du service, ou libellé qui
  contient l'hôte ou le nom du service), même protocole (ou tous), port couvert. Sinon, avec le droit de
  création des flux, lien vers `networkflow.form.php` avec les valeurs proposées en paramètres
  (`flow_type` `internet_publication` ou `internal`, `name`, `source_label`, `destination_itemtype` et
  `destination_items_id` de l'application, `destination_label`, `protocol`, `ports`). Rien n'est jamais
  écrit dans GRC.

## Règles communes

- Aucune action d'écriture vers Portainer, WUD, Harbor, Kubernetes ou un registre (uniquement des GET).
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

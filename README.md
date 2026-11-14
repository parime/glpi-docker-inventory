# Inventaire Docker pour GLPI

[🇬🇧 English](README.en.md)

Plugin GLPI qui donne une vue sur les applications en conteneurs, **en lecture seule** :

- **Portainer** : stacks et services Docker Swarm, nœuds du cluster ; hôtes Docker autonomes, leurs
  stacks Compose et leurs conteneurs ;
- **What's Up Docker (WUD)** : version déployée et dernière version disponible de chaque image ;
- **Harbor** : projets, dépôts, artefacts et résumé d'analyse de vulnérabilités des images.

Il répond à la question « qu'est-ce qui tourne, en quelle version, est-ce à jour, est-ce
vulnérable ? », que GLPI ne sait pas traiter seul : son onglet « Virtualisation » liste des
conteneurs isolés, sans stack, sans service, sans réplicas ni version.

> **État** : version 0.2.0 publiée (lot 1 : Portainer ; cœur du lot 2 : What's Up Docker). Le
> repli sans WUD par les registres (Docker Hub, Harbor) et les tickets de mise à jour selon des
> seuils sont prêts pour la prochaine version ; Harbor (lot 3) suit. Voir [ROADMAP.md](ROADMAP.md).

## Principes

- **Lecture seule.** Le plugin ne redéploie, ne redémarre et ne supprime jamais rien. La mise à jour
  automatique de WUD n'est pas utilisée.
- **Rien n'est modifié automatiquement dans GLPI non plus.** Un service disparu est marqué
  « retiré », pas supprimé. Les écarts de version donnent lieu à un ticket proposé selon des seuils
  choisis, jamais à un changement de statut, de priorité ou d'affectation.
- **Comptes aux droits minimaux.** Utilisateur dédié en lecture seule côté Portainer, WUD et Harbor ;
  clés d'API chiffrées avec la clé de GLPI ; noms des secrets Docker importés, jamais leur contenu.
- **Données fictives** dans les tests, les captures et la documentation.

## Ce que fait le plugin

| Source | Import | Dans GLPI |
| --- | --- | --- |
| Portainer | Stacks Swarm et Compose, services Swarm, services Compose et conteneurs autonomes (image, tag, empreinte, réplicas ou conteneurs, état, politique de redémarrage, ports publiés, réseaux, volumes nommés, noms des secrets), nœuds Swarm et hôtes autonomes | Stack = Application native (analyse d'impact) ; fiche Service ; nœuds et hôtes rattachés aux ordinateurs inventoriés |
| WUD | Conteneurs suivis : tag et empreinte locaux, dernière version, type d'écart | Version déployée, dernière version, écart (correctif, mineure, majeure, empreinte seule, non suivable) par service ; conteneurs sans service listés à part |
| Registres (sans WUD) | Docker Hub et API v2 de Harbor | Même tableau des versions, calculé par le plugin |
| Harbor | Projets, dépôts, artefacts, résumé d'analyse de vulnérabilités | Vulnérabilités par image, images hors registre maîtrisé signalées |

## Compatibilité

- GLPI 11.0 et 12.x
- PHP 8.2 à 8.5 (GLPI 11), 8.3 à 8.5 (GLPI 12)
- Portainer CE ou BE (API 2.x), WUD 8.x, Harbor 2.x

Portainer CE n'a que des rôles grossiers : l'utilisateur en lecture seule y voit plus que
nécessaire. La documentation d'installation le précisera.

## Installation

Téléchargez l'archive `glpi-docker-inventory-X.Y.Z.zip` de la [dernière version](https://github.com/parime/glpi-docker-inventory/releases/latest) (son
empreinte SHA-256 est publiée à côté), puis décompressez-la dans le dossier `plugins` de GLPI :

```sh
cd /var/www/glpi/plugins
unzip glpi-docker-inventory-X.Y.Z.zip   # crée le dossier dockerinventory/
```

Puis **Configuration > Plugins**, installer et activer « Inventaire Docker ».

## Utilisation (lot 1 : Portainer)

1. **Dans Portainer**, créer un utilisateur dédié aux droits minimaux (lecture seule sur les
   environnements à inventorier) et un jeton d'accès pour lui (*My account > Access tokens*).
2. **Dans GLPI**, *Configuration > Connexions Docker* (ou le nom du plugin dans *Configuration >
   Plugins*) : « Ajouter », choisir **Portainer**, puis saisir l'adresse de Portainer
   (`https://portainer.exemple.test:9443`) et la clé. Choisir l'environnement (Production, Préproduction...) à donner aux applications que
   l'import créera. « Tester la connexion », puis « Importer maintenant ».
3. L'import tourne ensuite **toutes les heures** (*Configuration > Actions automatiques*,
   `portainerimport`).

Ce qui apparaît :

- *Parc > Services de conteneurs* : tous les services, avec image, tag, empreinte, réplicas, état ;
- sur chaque **Application** créée pour une stack, l'onglet « Services » ;
- sur chaque **ordinateur** dont le nom correspond à un nœud du Swarm ou à un hôte Docker autonome,
  l'onglet « Services du nœud ».

**Hôtes Docker autonomes et stacks Compose** ([#25](https://github.com/parime/glpi-docker-inventory/issues/25)) :
une même connexion peut mêler environnements Swarm et environnements Docker sans Swarm. Pour ces
derniers, l'import lit l'hôte (nom, version de Docker), ses conteneurs (arrêtés compris) et ses
images, toujours en lecture seule :

- chaque stack Compose de Portainer, et chaque projet Compose déployé hors Portainer (étiquette
  `com.docker.compose.project` des conteneurs), devient une Application, comme une stack Swarm ;
- chaque service Compose (un ou plusieurs conteneurs) et chaque conteneur hors Compose devient un
  service, de nature « Service Compose » ou « Conteneur autonome » : image, tag, empreinte, état
  des conteneurs, politique de redémarrage, ports publiés, réseaux, volumes et secrets par leur
  nom seulement. Les valeurs des variables d'environnement et le contenu des secrets ne sont
  jamais lus ni enregistrés ;
- l'hôte est rattaché à l'ordinateur du même nom, comme un nœud Swarm ;
- un conteneur ou un projet disparu est marqué « retiré », jamais supprimé.

Ce que le plugin écrit **hors de ses propres tables** : uniquement la création, une fois, de
l'Application d'une stack quand aucune application de ce nom n'existe dans l'entité de la
connexion. Une application existante, un ordinateur ou un environnement ne sont jamais modifiés ni
créés. Le droit de lecture des services se donne dans *Administration > Profils*, onglet
« Inventaire Docker » ; les connexions sont réservées au droit *Configuration* en modification.

## Utilisation (lot 2 : What's Up Docker)

[What's Up Docker](https://github.com/getwud/wud) (WUD) surveille les images des conteneurs et
connaît, pour chacune, la dernière version publiée. Le plugin lit ce résultat **sans rien modifier
dans WUD** (aucun déclencheur, aucune mise à jour) et le rattache aux services importés de
Portainer.

1. **Importer d'abord Portainer** : les conteneurs vus par WUD sont rattachés aux services
   importés (par l'identifiant ou le nom du conteneur pour les hôtes autonomes, puis par
   l'empreinte de l'image, puis par le nom du service Swarm, puis par image et tag).
2. **Dans GLPI**, *Configuration > Connexions Docker* : « Ajouter », choisir **What's Up Docker**,
   saisir l'adresse de WUD (`https://wud.exemple.test:3000`) et, si WUD demande une
   authentification, l'utilisateur et le mot de passe (chiffré avec la clé de GLPI, jamais
   réaffiché). « Tester la connexion » affiche la version de WUD et le nombre de conteneurs suivis,
   puis « Lire WUD maintenant ».
3. La lecture tourne ensuite **toutes les heures** (*Configuration > Actions automatiques*,
   `wudimport`).

Ce qui apparaît :

- *Parc > Versions des images* : pour chaque service, version déployée, dernière version et écart
  (badges colorés : version majeure, mineure, correctif, empreinte seule, non suivable, à jour),
  filtres par écart et par environnement de l'application, conteneurs sans service listés à part ;
  « Recherche avancée et export » ouvre la recherche GLPI sur les mêmes colonnes ;
- sur chaque **service** et chaque **application**, l'onglet « Versions ».

Règles de lecture :

- l'écart calculé par WUD est repris tel quel ; le plugin ne le recalcule que si WUD ne le donne pas ;
- un tag mobile (`latest`, `stable`...) ou sans numéro de version est « non suivable » : seule
  l'empreinte dit si l'image a changé ;
- plusieurs conteneurs d'un même service donnent une seule ligne ; des réplicas dans des versions
  différentes (mise à jour en cours ou bloquée) sont signalés ;
- un conteneur sans service correspondant (hôte absent de Portainer, ou plusieurs services possibles) n'est pas
  ignoré : il est listé à part avec la raison.

> La correspondance avec l'API de WUD (`/api/containers`, `/api/app`) suit la documentation
> publique de WUD 8.x et un faux WUD (`tests/mock-wud`) ; elle reste à valider sur une vraie
> instance WUD 8.x ([#2](https://github.com/parime/glpi-docker-inventory/issues/2)).

Le droit de lecture des services (*Administration > Profils*, onglet « Inventaire Docker ») donne
aussi accès aux versions.

## Utilisation (lot 2 : registres, sans WUD)

Pour les services qu'**aucune connexion WUD ne couvre**, le plugin lit lui-même la liste des tags
de leur image dans le registre, **uniquement en lecture** (requêtes GET de la liste des tags), et
remplit le même tableau des versions (source « Registre de l'image »).

- **Docker Hub** (images `postgres`, `grafana/grafana`, `docker.io/...`) : sans compte, avec au
  plus une lecture par image et par jour (cache), et plus aucun appel pendant la vérification dès
  que Docker Hub refuse (limite de requêtes) ou ne répond pas. Seuls les noms des images publiques
  utilisées sont envoyés à Docker Hub.
- **Harbor** (API v2 des registres, `/v2/<projet>/<dépôt>/tags/list`) : *Configuration >
  Connexions Docker*, « Ajouter », choisir **Harbor**, saisir l'adresse
  (`https://harbor.exemple.test`), un **compte robot en lecture seule** (droit *pull* sur les
  projets utiles ; mot de passe chiffré avec la clé de GLPI, jamais réaffiché) et, s'il diffère de
  l'adresse, l'hôte tel qu'il est écrit dans les images (`registry.exemple.test:5000`). La connexion
  couvre les services de son entité et de ses sous-entités ; « Tester la connexion », puis
  « Vérifier les registres maintenant ».
- **Registre inconnu** (ni Docker Hub, ni un Harbor déclaré) ou **privé sans identifiants** :
  version « non vérifiée », avec la raison, sans erreur bloquante.

Même comparaison qu'avec WUD : versions SemVer (`1.2`, `v1.2.3`, `2024.10.1`), même nombre de
composantes que le tag déployé (`16` suit `17`, pas `16.4`), même variante (`-alpine`), préversions
(`-rc1`, `-beta2`) ignorées sauf si une préversion est déployée, `latest` « non suivable ».

La vérification tourne **toutes les heures** (*Configuration > Actions automatiques*,
`registrycheck`) ; « Vérifier les registres maintenant » existe sur la page *Parc > Versions des
images* et sur chaque connexion Harbor. Pour un miroir de Docker Hub, définir
`PLUGIN_DOCKERINVENTORY_DOCKER_HUB_URL` dans `config/local_define.php`. Pour ne jamais interroger
Docker Hub, désactiver l'action automatique `registrycheck`.

> L'appel à Harbor suit la spécification de l'API v2 des registres (authentification basique du
> compte robot) et un faux registre (`tests/mock-registry`) ; il reste à valider sur un vrai Harbor
> 2.x.

## Utilisation (lot 2 : tickets de mise à jour)

**Désactivés par défaut.** Sur la connexion **Portainer** des services, section « Tickets de mise à
jour » : « Créer des tickets de mise à jour », le **seuil** (version majeure seulement, mineure ou
majeure, tout écart correctifs compris), la **catégorie**, le **groupe chargé du ticket** et un
**modèle de ticket** (ses champs prédéfinis : type, urgence, demandeur... servent de départ).

Après chaque lecture de WUD ou des registres (tâches automatiques et boutons), pour chaque service
au-delà du seuil : un ticket « Mettre à jour le service X de la stack Y : 1.2 → 1.4 », rattaché à
l'application de la stack, avec l'image, les versions, l'écart et un lien vers le service.

- **Un ticket par service** : tant qu'il est ouvert, un **suivi** est ajouté quand la situation
  change (nouvelle version, autre version déployée, écart résolu), jamais un second ticket.
- **Rien n'est changé automatiquement** sur un ticket existant : ni statut (pas de réouverture, pas
  de clôture quand l'écart est résolu), ni priorité, ni affectation (le groupe n'est donné qu'à la
  création).
- Ticket résolu ou clos : un nouveau ticket seulement pour une nouvelle dernière version.
- Sous le seuil, préversion, empreinte seule, tag non suivable ou version non vérifiée : pas de
  ticket.
- Le lien vers le ticket apparaît dans l'onglet « Versions » du service ; le compte rendu (tickets
  créés, suivis ajoutés) dans le message de lecture et le journal des actions automatiques.
- Vulnérabilité critique de l'image : déclencheur prévu avec Harbor au lot 3.

**Portainer, WUD ou Harbor sur un réseau privé (GLPI 12).** GLPI 12 refuse par défaut les appels
vers une adresse privée. Pour les autoriser pour ces sources seulement, ajouter dans
`config/local_define.php` :

```php
define('GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS', [
    \GlpiPlugin\Dockerinventory\Portainer\PortainerContext::class,
    \GlpiPlugin\Dockerinventory\Wud\WudContext::class,
    \GlpiPlugin\Dockerinventory\Registry\RegistryContext::class,
]);
```

## Liens avec les autres plugins

- **GRC Manager** ([glpi-iso27001-management](https://github.com/parime/glpi-iso27001-management)) :
  preuves pour les mesures A.5.9 (inventaire), A.8.8 (vulnérabilités techniques), A.8.9
  (configuration) et A.8.32 (changements) ; vulnérabilités des images Harbor dans la corrélation
  des vulnérabilités.
- **Configuration GLPI Auto** ([Configuration-glpi-auto](https://github.com/parime/Configuration-glpi-auto)) :
  étape de l'assistant pour déclarer les connexions Portainer, WUD et Harbor.

## Documentation

- [ARCHITECTURE.md](ARCHITECTURE.md) : arborescence, classes, tests
- [ROADMAP.md](ROADMAP.md) : lots et issues
- [CHANGELOG.md](CHANGELOG.md)

## Réutilisation et citation

Ce projet est sous licence **GPL-3.0-or-later**. Si vous reprenez du code, même partiellement, sans
forker le dépôt : conservez les en-têtes de copyright et le fichier [NOTICE](NOTICE), gardez la même
licence avec le code source, indiquez vos modifications et citez le projet d'origine
(https://github.com/parime/glpi-docker-inventory). Pour citer le projet, voir [CITATION.cff](CITATION.cff) (bouton « Cite this repository »
de GitHub). Les assistants de code trouveront ces consignes dans [AGENTS.md](AGENTS.md).

## Licence

GPL-3.0-or-later, voir [LICENSE](LICENSE).

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

> **État** : version 0.9.0 publiée : Portainer (Swarm et hôtes Docker autonomes), Kubernetes, versions
> des images par What's Up Docker ou les registres, Harbor (artefacts et vulnérabilités), fin de
> support des images, tickets selon des seuils, preuves pour GRC Manager. Voir [ROADMAP.md](ROADMAP.md).

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
| Cycles de vie (endoflife.date, désactivé par défaut) | Dates de fin de support des produits des images (correspondance explicite) | Fin de support, bientôt, maintenue ou inconnue par service ; tickets de fin de support facultatifs |
| Harbor | Projets, dépôts, artefacts, résumé d'analyse de vulnérabilités | Vulnérabilités par image, images hors registre maîtrisé signalées |
| Kubernetes | Namespaces, Deployments, StatefulSets, DaemonSets et CronJobs (image, tag, empreinte, réplicas prêts et voulus, état), nœuds | Namespace = Application native ; fiche Service par conteneur ; nœuds rattachés aux ordinateurs (nom ou adresse IP) ; mêmes versions et vulnérabilités Harbor |

## Compatibilité

- GLPI 11.0 et 12.x
- PHP 8.2 à 8.5 (GLPI 11), 8.3 à 8.5 (GLPI 12)
- Portainer CE ou BE (API 2.x), WUD 8.x, Harbor 2.x, Kubernetes 1.24 et plus (API `apps/v1`, `batch/v1`)

Le compte à utiliser dans chaque source (Portainer BE ou CE, WUD, Harbor, Kubernetes) est décrit
dans le [guide de l'administrateur](docs/administrateur.md#droits-minimaux-dans-les-sources).

## Installation

Téléchargez l'archive `glpi-docker-inventory-X.Y.Z.zip` de la [dernière version](https://github.com/parime/glpi-docker-inventory/releases/latest) (son
empreinte SHA-256 est publiée à côté), puis décompressez-la dans le dossier `plugins` de GLPI :

```sh
cd /var/www/glpi/plugins
unzip glpi-docker-inventory-X.Y.Z.zip   # crée le dossier dockerinventory/
```

Puis **Configuration > Plugins**, installer et activer « Inventaire Docker ».

**Où trouver le plugin** (une seule entrée de menu, pour ne pas alourdir GLPI) :

| Écran | Chemin | Droit |
| --- | --- | --- |
| Consultation | **Parc > Inventaire Docker** : sous-pages **Services**, **Versions des images**, **Images Harbor et vulnérabilités**, **Fin de support des images** | **Lire** (Administration > Profils, onglet « Inventaire Docker ») |
| Réglages | **Configuration > Plugins > Inventaire Docker** : onglets **Connexions Docker** (Portainer, Kubernetes, WUD, Harbor), **Registres maîtrisés**, **Fin de support des images** | Configuration de GLPI en modification |

Les anciennes adresses (*Configuration > Connexions Docker*, *Configuration > Fin de support des
images*, jusqu'à la version 0.8.0) redirigent vers les nouvelles : les favoris restent valables.

## Utilisation par rôle

Chaque rôle a son parcours ci-dessous et son guide détaillé dans [`docs/`](docs/).

### Administrateur GLPI : première configuration

Guide complet : [docs/administrateur.md](docs/administrateur.md) (droits minimaux, chaque
connexion, manifeste Kubernetes, tickets, actions automatiques, réseaux privés sous GLPI 12).

1. **Configuration > Plugins** : installer et activer « Inventaire Docker ».
2. Dans Portainer (ou Kubernetes), créer un **compte dédié en lecture seule** et son jeton.
3. **Configuration > Plugins > Inventaire Docker**, onglet **Connexions Docker** : « Ajouter une
   connexion », **Portainer** (ou **Kubernetes**), adresse, jeton, environnement des applications.
4. Sur la connexion : « **Tester la connexion** », puis « **Importer maintenant** ».
5. Facultatif : connexions **What's Up Docker** et **Harbor** (versions, vulnérabilités), onglets
   **Registres maîtrisés** et **Fin de support des images** (désactivée par défaut), tickets
   automatiques sur la connexion Portainer ou Kubernetes (désactivés par défaut).
6. **Administration > Profils**, onglet **Inventaire Docker** : droit **Lire** pour les techniciens
   et les responsables sécurité.

### Technicien : suivre les services et les mises à jour

Guide complet : [docs/technicien.md](docs/technicien.md) (sens des badges, règles de comparaison
des versions, onglets des applications et des ordinateurs, fonctionnement des tickets).

1. **Parc > Inventaire Docker**, sous-page **Services** : ce qui tourne, avec image, tag, réplicas
   et état.
2. Sous-page **Versions des images** : filtrer sur « Version majeure » ou « Version mineure ».
3. Sous-page **Images Harbor et vulnérabilités** : images aux vulnérabilités critiques ou hautes.
4. Sous-page **Fin de support des images** : branches qui ne reçoivent plus de correctifs.
5. **Assistance > Tickets** : traiter les tickets de mise à jour, de fin de support et de
   vulnérabilité créés par le plugin (un par service, jamais modifiés automatiquement).

### Responsable sécurité ou auditeur : preuves et écarts

Guide complet : [docs/securite.md](docs/securite.md) (preuves par mesure, garanties de l'API de
lecture, ce que l'auditeur peut vérifier).

1. **GRC & Conformité > Conformité** (GRC Manager), mesures **A.5.9**, **A.8.8**, **A.8.9** et
   **A.8.32** : section « **Preuves de l'inventaire Docker** », lue en direct.
2. **GRC & Conformité > Sécurité technique > Vulnérabilités du parc** : services dont l'image a
   des vulnérabilités critiques ou hautes.
3. **Parc > Inventaire Docker**, sous-page **Fin de support des images** : images dont la branche
   n'est plus maintenue.
4. Sous-page **Images Harbor et vulnérabilités**, filtre « **Seulement hors registre maîtrisé** » :
   images venues d'un registre non autorisé.

## Liens avec les autres plugins

- **GRC Manager** ([glpi-iso27001-management](https://github.com/parime/glpi-iso27001-management)) :
  preuves pour les mesures A.5.9 (inventaire), A.8.8 (vulnérabilités techniques), A.8.9
  (configuration) et A.8.32 (changements) ; vulnérabilités des images Harbor dans la corrélation
  des vulnérabilités ([#10](https://github.com/parime/glpi-docker-inventory/issues/10)). GRC lit
  ces preuves par l'**API de lecture** `GlpiPlugin\Dockerinventory\Api\Evidence` (version 1,
  lecture seule) : chaque méthode ne renvoie que ce que l'utilisateur voit dans ce plugin (droit de
  lecture des services, entités actives), et rien sans ce droit. Les dates d'import des connexions
  ne sont données qu'aux personnes qui peuvent voir les connexions (droit « Configuration »).
- **Changements de version** : chaque import Portainer note, pour chaque service, le passage à une
  autre image, à un autre tag, ou à une autre empreinte sous le même tag (image reconstruite). Ce
  journal, en lecture seule et jamais purgé automatiquement, sert de preuve de gestion des
  changements (A.8.32). Chaque connexion garde aussi la **date de son dernier import réussi**
  (réussi, même en partie ; un échec ou un avertissement ne la change pas).
- **Configuration GLPI Auto** ([Configuration-glpi-auto](https://github.com/parime/Configuration-glpi-auto)) :
  étape de l'assistant pour déclarer les connexions Portainer, WUD et Harbor.

## Documentation

Règles communes à tous les plugins de parime, à lire avant de contribuer : [charte commune des plugins](https://github.com/parime/glpi-plugin-template/blob/main/CHARTE.md).

- [Guide de l'administrateur](docs/administrateur.md) : installation, connexions, droits, réglages
- [Guide du technicien](docs/technicien.md) : services, versions, vulnérabilités, tickets
- [Guide du responsable sécurité et de l'auditeur](docs/securite.md) : preuves GRC Manager, fin de
  support, images hors registre maîtrisé
- [ARCHITECTURE.md](ARCHITECTURE.md) : arborescence, classes, tests
- [ROADMAP.md](ROADMAP.md) : lots et issues
- [CHANGELOG.md](CHANGELOG.md)

## Traductions

[![État de la traduction](https://hosted.weblate.org/widget/glpi-docker-inventory/svg-badge.svg)](https://hosted.weblate.org/engage/glpi-docker-inventory/)

Le plugin est traduit en français, anglais, allemand, espagnol, italien, polonais et portugais
(Brésil). **Les contributions de traduction sont bienvenues sur [Hosted Weblate](https://hosted.weblate.org/engage/glpi-docker-inventory/)** :
corriger un terme, améliorer le vocabulaire métier ou ajouter une langue se fait directement dans
le navigateur, sans Git. Weblate propose ensuite les traductions par une Pull Request, relue avant
d'être fusionnée.

## Réutilisation et citation

Ce projet est sous licence **GPL-3.0-or-later**. Si vous reprenez du code, même partiellement, sans
forker le dépôt : conservez les en-têtes de copyright et le fichier [NOTICE](NOTICE), gardez la même
licence avec le code source, indiquez vos modifications et citez le projet d'origine
(https://github.com/parime/glpi-docker-inventory). Pour citer le projet, voir [CITATION.cff](CITATION.cff) (bouton « Cite this repository »
de GitHub). Les assistants de code trouveront ces consignes dans [AGENTS.md](AGENTS.md).

## Licence

GPL-3.0-or-later, voir [LICENSE](LICENSE).

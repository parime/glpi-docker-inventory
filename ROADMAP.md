# Feuille de route

Conception d'origine : [glpi-iso27001-management#179](https://github.com/parime/glpi-iso27001-management/issues/179).
Chaque lot fini et testé part en version, sans attendre le suivant.

## Lot 1 : Portainer (publié en 0.1.0)

- [#1](https://github.com/parime/glpi-docker-inventory/issues/1) Import Portainer : stacks en Applications,
  services, nœuds rattachés aux ordinateurs, services retirés.

## Lot 2 : WUD et registres (cœur publié en 0.2.0)

Objectif : pour chaque service, version déployée, dernière version disponible et écart.

| Issue | Sujet | État |
| --- | --- | --- |
| [#2](https://github.com/parime/glpi-docker-inventory/issues/2) | Connexion WUD et lecture de `/api/containers` | Fait, à valider sur une vraie instance WUD 8.x |
| [#3](https://github.com/parime/glpi-docker-inventory/issues/3) | Rattachement des conteneurs WUD aux services Swarm | Fait |
| [#4](https://github.com/parime/glpi-docker-inventory/issues/4) | Comparaison des versions (correctif, mineure, majeure, `latest` non suivable) | Fait |
| [#5](https://github.com/parime/glpi-docker-inventory/issues/5) | Repli sans WUD : Docker Hub et API v2 de Harbor | Fait, Harbor à valider sur une vraie instance 2.x |
| [#6](https://github.com/parime/glpi-docker-inventory/issues/6) | Tableau des versions : onglet du service et vue d'ensemble | Fait |
| [#7](https://github.com/parime/glpi-docker-inventory/issues/7) | Tickets de mise à jour selon des seuils | Fait (vulnérabilité critique : lot 3) |
| [#8](https://github.com/parime/glpi-docker-inventory/issues/8) | Faux WUD et jeu de données fictif | Fait |

Ordre conseillé : #8 et #2 (on peut tester), puis #3 et #4, puis #6, enfin #5 et #7. Le cœur du lot
(#2, #3, #4, #6, #8) part en version dès qu'il est fusionné ; #5 et #7 suivent.

## Hôtes Docker autonomes

- [#25](https://github.com/parime/glpi-docker-inventory/issues/25) Hôtes Docker autonomes et stacks
  Compose gérés par Portainer : fait, à valider sur un vrai Portainer avec un hôte Docker autonome.

## Fin de support des images

- [#26](https://github.com/parime/glpi-docker-inventory/issues/26) Images déployées en fin de
  support : cycles de vie d'endoflife.date (désactivé par défaut), correspondance image → produit
  modifiable, badges et filtre, tickets de fin de support. Fait ; preuve GRC Manager (A.8.8) avec #10.

## Lot 3 : Harbor

- [#9](https://github.com/parime/glpi-docker-inventory/issues/9) Projets, dépôts, artefacts et
  résumé des analyses de vulnérabilités (API REST de Harbor 2.x), artefact rattaché à chaque service
  par l'empreinte, images hors registre maîtrisé signalées, tickets de vulnérabilité (désactivés par
  défaut). Fait, à valider sur un vrai Harbor 2.x. Le versement dans la corrélation des
  vulnérabilités de grcmanager suit avec #10.

## Kubernetes

- [#31](https://github.com/parime/glpi-docker-inventory/issues/31) Source Kubernetes en lecture seule :
  namespaces en Applications, Deployments, StatefulSets, DaemonSets et CronJobs en services, nœuds
  rattachés aux ordinateurs, mêmes versions et vulnérabilités Harbor. Fait, testé contre un cluster
  k3s de laboratoire ; à valider sur un cluster de production (demandeur de l'issue).

## Intégration

- [#10](https://github.com/parime/glpi-docker-inventory/issues/10) Preuves grcmanager (A.5.9, A.8.8, A.8.9,
  A.8.32) et étape de l'assistant de Configuration GLPI Auto.

## Langue des chaînes sources (msgid)

[#37](https://github.com/parime/glpi-docker-inventory/issues/37). Les msgid sont en français ; la
charte commune retient l'anglais pour les nouveaux plugins, sans imposer de migration (section 2).
Règle d'ici là : **toute nouvelle chaîne s'écrit en français**, jamais de mélange ;
`locales/dockerinventory.pot` reste la source unique de Hosted Weblate (langue source « français »,
anglais en langue secondaire).

Si la migration est décidée (retours des traducteurs), ce plugin passe en premier, idéalement
avant la version 1.0.0, dans une version mineure dédiée sans autre changement :

1. `php tools/msgid-report.php` (lecture seule) : nombre de chaînes, chaînes sans traduction
   `en_GB`, collisions. État au 16/11/2026 : 609 chaînes (1 au pluriel, aucune à contexte), toutes
   traduites en `en_GB`, 17 identiques dans les deux langues (msgid inchangé), 4 collisions à
   trancher avant la migration : « Empreinte (digest) » et « Empreinte » (Digest), « Retiré » et
   « Retirés » (Retired), « Tous » et « Toutes » (All), « Version de l’image » et « Version
   d'image » (Image version). Une collision se règle en ajoutant un contexte (`_x()`) ou en
   distinguant les deux traductions anglaises.
2. Script de correspondance : la traduction `en_GB` de chaque msgid devient le nouveau msgid,
   appliqué au code PHP (`__()`, `_n()`, `_x()`), aux gabarits Twig et à tous les `.po` (msgid
   remplacé, msgstr conservé) ; `fr_FR.po` reçoit les anciens msgid comme traductions et `en_GB.po`
   des traductions identiques aux msgid.
3. Tests qui comparent des libellés adaptés ; `.pot` régénéré, `sync-locale-variants.sh`,
   complétude vérifiée, `.mo` recompilés ; ligne de CHANGELOG.

## Hors périmètre

- Redéployer, redémarrer ou mettre à jour un service : le plugin reste en lecture seule.

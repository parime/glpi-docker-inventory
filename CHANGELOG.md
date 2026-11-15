# Changelog

Format : [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), versions
[SemVer](https://semver.org/lang/fr/).

## [Unreleased]

### Added

- Harbor : projets, dépôts, artefacts et analyses de vulnérabilités
  ([#9](https://github.com/parime/glpi-docker-inventory/issues/9)), lus en lecture seule par l'API
  REST de Harbor 2.x avec le compte robot de la connexion Harbor (pagination, erreurs et limite de
  requêtes non bloquantes, artefacts disparus « retirés », jamais supprimés). Artefact rattaché à
  chaque service par l'empreinte de l'image qui tourne (sinon par le tag ; empreinte d'index multi-plateforme absente de
  Harbor signalée sans conclure à une reconstruction),
  carte « Vulnérabilités de l'image (Harbor) » dans l'onglet « Versions » des services, onglet
  « Images Harbor et vulnérabilités » de la page des versions (compteurs par gravité, filtres par
  gravité, projet et provenance), sans nouvelle entrée de menu. Registres maîtrisés réglables sur la
  page de configuration du plugin (par défaut : les hôtes des connexions Harbor) ; images déployées
  hors de ces registres signalées. Tickets de vulnérabilité facultatifs sur la connexion Portainer
  (seuil critique, ou critique et haute ; **désactivés par défaut**), un par service avec des
  suivis, jamais de changement de statut. Bouton « Lire Harbor maintenant » et action automatique
  horaire `harborimport`. Faux Harbor `tests/mock-harbor` (données fictives). Mise à jour
  automatique depuis la 0.5.0.

### Changed

- Traductions collaboratives sur Hosted Weblate : section « Traductions » des README avec le badge
  d'avancement et le lien pour contribuer.
- Documentation : lien vers la charte commune des plugins ([CHARTE.md](https://github.com/parime/glpi-plugin-template/blob/main/CHARTE.md))
  dans `AGENTS.md` et les README ; contrôles de sécurité communs v1.7.0 (charte commune et
  menus, en avertissement) ; `SECURITY.md` (signalement privé des vulnérabilités).

### Security

- Contrôles d'accès et échappement imposés en CI ([#43](https://github.com/parime/glpi-docker-inventory/issues/43),
  [#38](https://github.com/parime/glpi-docker-inventory/issues/38)) : chaque page de `front/` doit
  appeler un contrôle de droits (`check-entrypoints-auth.sh`) et figurer dans la matrice d'accès
  PHPUnit (`check-security-matrix-coverage.sh`), qui ouvre chaque page et chaque action avec un
  profil sans droit, un profil en lecture seule et un objet d'une autre entité (refus attendu,
  y compris pour les pages et actions Harbor) ; test XSS : noms piégés (`<script>`,
  `"><img onerror>`) importés depuis Portainer, WUD et Harbor, restitués échappés sur chaque page et
  chaque onglet, aucun `|raw` dans les gabarits. Messages d'erreur des formulaires échappés comme
  les autres. Charte commune bloquante (`charter-mode: error`).

## [0.5.0] - 2026-11-15

- Publiée dans le catalogue de la marketplace GLPI (`dockerinventory.xml`).

### Added

- Images déployées en fin de support ([#26](https://github.com/parime/glpi-docker-inventory/issues/26)) :
  branche de chaque image comparée aux dates de fin de support publiées par
  [endoflife.date](https://endoflife.date) (appel sortant **désactivé par défaut**, seul le nom du
  produit est envoyé, une lecture par produit et par jour, données en cache gardées si la source
  ne répond pas). Page *Configuration > Fin de support des images* : activation, seuil de
  « bientôt en fin de support » (90 jours), mise à jour immédiate, correspondance image → produit
  fournie (33 images officielles) et modifiable, images en service sans correspondance (jamais
  devinées : « support inconnu »). Badges « fin de support », « bientôt », « maintenue »,
  « inconnue » dans l'onglet « Versions » et la page des versions (compteurs et filtre). Tickets de
  fin de support facultatifs sur la connexion Portainer (délai en jours, désactivés par défaut), un
  par service avec des suivis. Action automatique quotidienne `lifecyclecheck`. Fausse source
  `tests/mock-lifecycle` (cycles fictifs). Mise à jour automatique depuis la 0.4.0.

## [0.4.0] - 2026-11-14

- Publiée dans le catalogue de la marketplace GLPI (`dockerinventory.xml`).

### Added

- Hôtes Docker autonomes et stacks Compose gérés par Portainer
  ([#25](https://github.com/parime/glpi-docker-inventory/issues/25)), en lecture seule, dans la même
  connexion que les environnements Swarm : hôte rattaché à l'ordinateur du même nom (onglet
  « Services du nœud », conteneurs arrêtés compris) ; stacks Compose de Portainer et projets
  Compose déployés hors Portainer en Applications ; services Compose et conteneurs hors Compose en
  services (nature, image, tag, empreinte, état des conteneurs, politique de redémarrage, ports,
  réseaux, volumes et secrets par leur nom, jamais les valeurs des variables d'environnement) ;
  conteneur ou projet disparu « retiré », jamais supprimé. Les conteneurs vus par WUD sont
  rattachés à ces services par leur identifiant ou leur nom : même tableau des versions, même
  repli par les registres et mêmes tickets de mise à jour que les services Swarm. Faux Portainer
  complété (hôte `docker-recette-01.exemple.test`). Mise à jour automatique depuis la 0.3.0.

### Changed

- Conteneurs WUD non rattachés : « Conteneurs sans service importé » (au lieu de « sans service
  Swarm ») ; libellés des onglets et du compte rendu d'import étendus aux hôtes autonomes.

- Fichiers de citation et d'attribution : `CITATION.cff`, `NOTICE`, `AGENTS.md` (consignes de licence
  pour les assistants de code) et section « Réutilisation et citation » des README.

## [0.3.0] - 2026-11-13

- Publiée dans le catalogue de la marketplace GLPI (`dockerinventory.xml`).

### Added

- Repli sans WUD par les registres ([#5](https://github.com/parime/glpi-docker-inventory/issues/5)) :
  connexions de type Harbor (compte robot en lecture seule facultatif, mot de passe chiffré, hôte du
  registre dans les images, « Tester la connexion »), lecture des tags de Docker Hub (anonyme, une
  fois par jour et par image au plus) et de l'API v2 de Harbor pour les services qu'aucune connexion
  WUD ne couvre ; même tableau des versions (source « Registre de l'image »), même comparaison
  (préversions ignorées sauf si déployées) ; registre inconnu ou privé sans identifiants « non
  vérifié » avec la raison ; tâche automatique horaire `registrycheck` et bouton « Vérifier les
  registres maintenant » ; faux registre `tests/mock-registry`.
- Tickets de mise à jour selon des seuils ([#7](https://github.com/parime/glpi-docker-inventory/issues/7)),
  désactivés par défaut : réglés sur la connexion Portainer (seuil majeure, mineure ou correctif,
  catégorie, groupe, modèle de ticket) ; « Mettre à jour le service X de la stack Y : 1.2 → 1.4 »
  après chaque lecture de WUD ou des registres, un ticket par service puis des suivis quand la
  situation change, jamais de doublon ni de changement de statut, de priorité ou d'affectation ;
  lien vers le ticket dans l'onglet Versions du service ; déclencheur « vulnérabilité critique »
  prévu pour le lot 3.

## [0.2.0] - 2026-11-08

- Publiée dans le catalogue de la marketplace GLPI (`dockerinventory.xml`).

### Added

- Lot 2 : versions des images depuis What's Up Docker (WUD), en lecture seule.
  - Connexions de type WUD ([#2](https://github.com/parime/glpi-docker-inventory/issues/2)) : à la
    création, choix du type (Portainer ou WUD) ; adresse, utilisateur et mot de passe facultatifs
    (chiffré avec la clé de GLPI, jamais réaffiché), HTTPS obligatoire sauf autorisation, contexte
    réseau `WudContext` pour GLPI 12, « Tester la connexion » (version de WUD, conteneurs suivis),
    « Lire WUD maintenant » et tâche automatique horaire `wudimport`. Seuls `GET /api/app` et
    `GET /api/containers` sont appelés. Correspondance à valider sur une vraie instance WUD 8.x.
  - Rattachement des conteneurs WUD aux services Swarm
    ([#3](https://github.com/parime/glpi-docker-inventory/issues/3)) : empreinte de l'image, puis nom
    du service Swarm, puis image et tag ; une ligne par service, réplicas désynchronisés signalés,
    conteneurs sans service (hors Swarm, ambigus) listés à part.
  - Comparaison des versions ([#4](https://github.com/parime/glpi-docker-inventory/issues/4)) :
    table `glpi_plugin_dockerinventory_imageversions` ; écart de WUD repris tel quel (version
    majeure, mineure, correctif, préversion, empreinte seule) ; `latest` ou tag non versionné « non
    suivable ».
  - Tableau des versions ([#6](https://github.com/parime/glpi-docker-inventory/issues/6)) : onglet
    « Versions » des services et des applications, page *Parc > Versions des images* (compteurs
    cliquables, filtres par écart et par environnement, badges colorés avec icônes, lien vers la
    page de l'image sur Docker Hub), recherche GLPI sur les colonnes (vues enregistrées, exports).
  - Faux WUD et jeu de données fictif ([#8](https://github.com/parime/glpi-docker-inventory/issues/8))
    dans `tests/mock-wud` ; deux services ajoutés au faux Portainer.
  - Colonnes par défaut de la liste des connexions : type, adresse, état, dernier import.

### Changed

- README : installation depuis l'archive de la dernière version publiée.
- « Connexions Portainer » devient « Connexions Docker » (Portainer et WUD).

### Fixed

- Mise à jour du plugin : l'installation ne réinsère plus le droit du plugin s'il existe déjà
  (doublon refusé par la base, qui faisait échouer la mise à jour).

## [0.1.0] - 2026-11-08

- Publiée dans le catalogue de la marketplace GLPI (`dockerinventory.xml`).

### Added

- Lot 1 : import Portainer en lecture seule ([#1](https://github.com/parime/glpi-docker-inventory/issues/1)).
  - Connexions Portainer (Configuration > Connexions Portainer) : clé d'API chiffrée avec la clé de
    GLPI et jamais réaffichée, HTTPS obligatoire sauf autorisation explicite, bouton « Tester la
    connexion ».
  - Import toutes les heures (tâche automatique) et bouton « Importer maintenant » : stacks Swarm en
    Applications GLPI (avec l'environnement choisi, seulement à la création), services (image, tag,
    empreinte, réplicas voulus et en cours, ports publiés, réseaux, noms des secrets), nœuds
    rattachés aux ordinateurs par nom d'hôte.
  - Service, stack ou nœud disparu marqué « retiré », jamais supprimé.
  - Liste des services (Parc > Services de conteneurs), fiche en lecture seule, onglet « Services »
    des applications, onglet « Services du nœud » des ordinateurs, droit de lecture dans les profils.
  - Traductions : français, anglais, allemand, espagnol, italien, portugais (Brésil), polonais.
  - CI : CI officielle GLPI 11, installation et tests sur GLPI 12, chargement des classes sur PHP
    8.2 à 8.5, traductions complètes, contrôles de sécurité communs.
- Chaîne de publication : tag automatique à chaque nouvelle version de `setup.php`, archive
  `glpi-docker-inventory-X.Y.Z.zip` testée sur une vraie instance GLPI, somme SHA-256, attestation
  de provenance et PR de mise à jour du catalogue de la marketplace (`dockerinventory.xml`).
- Contrôles de sécurité communs v1.6.1 (actionlint 1.7.12).
- Documentation du dépôt : README (français, anglais), ARCHITECTURE, ROADMAP, modèles d'issues et
  de PR.
- Nom du plugin : « Inventaire Docker » (dépôt glpi-docker-inventory, clé dockerinventory), plus parlant
  que « containers ».

# Changelog

Format : [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), versions
[SemVer](https://semver.org/lang/fr/).

## [Unreleased]

### Changed

- CI : contrôles de sécurité communs glpi-plugin-security v1.10.1 (secrets, dépendances, CodeQL,
  test de mise à jour depuis la version publiée, tests d'intégration en français, parcours de toutes
  les pages) ; le faux serveur Kubernetes des tests exige TLS 1.2 au moins.

### Fixed

- Tests de mise à jour du schéma ([#113](https://github.com/parime/glpi-docker-inventory/issues/113)) :
  `InstallerTest` copie les tables du plugin (structure et lignes), ses tâches automatiques et sa
  configuration avant chaque test et les remet telles quelles après, même en cas d'échec ; une
  copie laissée par un test interrompu est rendue au lancement suivant. Une instance partagée ne
  perd plus de colonnes (`kind`, `compose_project`), de clé (`unicity`) ni de données.

## [0.16.0] - 2026-11-22

- Publiée dans le catalogue de la marketplace GLPI (`dockerinventory.xml`).

### Added

- Rappel avant l'expiration d'une exception de configuration
  ([#99](https://github.com/parime/glpi-docker-inventory/issues/99)) : une exception qui finit dans
  les 30 jours porte un badge orange « Expire dans N jours : à renouveler ou à corriger », est
  comptée sur la carte **Avec une exception** et listée par le nouveau filtre **Expire bientôt**.
  Nouvelle tâche automatique quotidienne `hardeningexpiry` : notification GLPI **Exception de
  configuration qui expire bientôt**, créée à l'installation et adressée à l'auteur de l'exception
  (destinataires réglables dans Configuration > Notifications, réservés aux comptes qui lisent les
  services de l'entité), envoyée une seule fois par date de fin, jamais pour un écart corrigé ou un
  service retiré. Champ `expiring` dans `Evidence::hardening()`.

### Changed

- Configuration à risque ([#99](https://github.com/parime/glpi-docker-inventory/issues/99)) : la
  gravité **haute** a sa propre couleur orange, distincte du jaune de la gravité moyenne (badges,
  filtres, analyses importées et images Harbor), comme les badges de GRC Manager, en thème clair
  comme sombre. La carte **Sans écart critique ni haut** passe en premier et en avant ; la carte
  « Services sans écart ouvert » précise que les écarts de gravité basse comptent.

### Fixed

- Documentation des réseaux privés sous GLPI 12
  ([#99](https://github.com/parime/glpi-docker-inventory/issues/99)) : GLPI lit
  `config/local_define.php` dans le dossier `config/` de son installation (pages, ligne de commande
  et tâches automatiques), pas dans `GLPI_CONFIG_DIR` (sauf paquet de distribution qui le prévoit
  dans `inc/downstream.php`) : en cas de doute, le placer dans `config/` du dossier de GLPI.

## [0.15.0] - 2026-11-21

- Publiée dans le catalogue de la marketplace GLPI (`dockerinventory.xml`).

### Added

- Priorisation par exploitation connue ([#84](https://github.com/parime/glpi-docker-inventory/issues/84)) :
  les CVE des analyses importées sont enrichies par le catalogue **CISA KEV** (vulnérabilités exploitées
  activement, échéance CISA, usage par des rançongiciels) et, en option, par les scores **FIRST EPSS**
  (probabilité d'exploitation, seuil « EPSS élevé » réglable, 10 % par défaut). Nouvel onglet
  **Exploitation connue** de la page de configuration : téléchargement sur clic des fichiers publics
  complets depuis une adresse fixe (rien n'est envoyé), ou par l'action automatique `exploitsignals`,
  **désactivée à l'installation** ; fichiers gardés en cache dans le dossier des données du plugin ;
  mode hors ligne par dépôt du fichier KEV (JSON) ou EPSS (CSV, compressé ou non). Les images sont
  classées : exploitées activement d'abord, puis gravité, puis exposition des services (badge de
  portée à côté de chaque service) ; vulnérabilités détaillées dans le même ordre. Pastilles
  « exploitée activement », « rançongiciel » et « EPSS élevé » sur les analyses et la carte de l'onglet
  Versions du service, filtre **Exploitées activement** de la sous-page Analyses importées. Adresses
  changeables par `PLUGIN_DOCKERINVENTORY_KEV_URL` et `PLUGIN_DOCKERINVENTORY_EPSS_URL` (miroir interne).

## [0.14.0] - 2026-11-21

- Publiée dans le catalogue de la marketplace GLPI (`dockerinventory.xml`).

## [0.13.0] - 2026-11-21

- Publiée dans le catalogue de la marketplace GLPI (`dockerinventory.xml`).

### Added

- Source Traefik en lecture seule ([#80](https://github.com/parime/glpi-docker-inventory/issues/80)) :
  nouveau type de connexion **Traefik** (adresse de l'API, utilisateur et mot de passe `basicAuth` ou
  jeton porteur, chiffré et jamais réaffiché). « Lire Traefik maintenant » (lecture sur clic, aucune
  tâche automatique) lit les routes HTTP et TCP, points d'entrée, services et middlewares
  réellement appliqués, par des GET sur des chemins fixes sous la seule adresse enregistrée, et les
  compare aux routes des étiquettes des services inventoriés (conformes, différentes, service non
  inventorié, hors étiquettes Docker, déclarées mais non appliquées). Signalements sans correction :
  tableau de bord ou API de Traefik sans authentification (`api.insecure`), route publique sans TLS
  ni redirection HTTPS, route d'administration sans authentification, route publique sans en-têtes
  de sécurité. Faux Traefik `tests/mock-traefik` pour les tests.
- Analyses d'images importées ([#73](https://github.com/parime/glpi-docker-inventory/issues/73)) :
  sous-page **Analyses importées** de Parc > Inventaire Docker pour déposer le rapport JSON de Trivy
  ou de Grype, ou un SARIF (Docker Scout, Trivy, Grype), des images qui ne sont pas dans Harbor.
  Lecture sûre (20 Mo au plus, profondeur JSON et nombre de vulnérabilités bornés, valeurs typées et
  tronquées), une analyse par entité, image, tag et empreinte, remplacée au dépôt suivant. Rattachement
  aux services par empreinte, sinon par tag ; vulnérabilités par gravité, corrigeables, analyse de plus
  de 30 jours signalée, taux de services couverts, détail des 500 plus graves ; carte dans l'onglet
  Versions du service. Droit propre (importer, supprimer), accordé aux administrateurs. Aucune
  analyse lancée, rien n'est modifié sur les plateformes. Rapports d'exemple fictifs
  dans `tests/fixtures/scans`.

### Changed

- Marketplace et forum : logo du plugin (`logo.svg`, `logo.png`, `logo-40.png`), captures d'écran
  (`docs/screenshots/`) dans la fiche du catalogue et les README, descriptions et étiquettes du
  catalogue à jour (exposition, configuration à risque, Kubernetes), installation depuis le
  marketplace, annonce du forum GLPI en anglais et en français (`docs/forum-announcement.md`).
- Fiche du catalogue : logo et captures d'écran (`<logo>`, `<screenshots>`), une fois les images
  présentes sur `main`.

## [0.12.0] - 2026-11-21

- Publiée dans le catalogue de la marketplace GLPI (`dockerinventory.xml`).

### Added

- Configuration à risque des conteneurs ([#71](https://github.com/parime/glpi-docker-inventory/issues/71)) :
  à chaque import, le plugin lit les réglages de sécurité des conteneurs (détail des conteneurs d'un
  hôte autonome, spécification des services Swarm, modèle de pod des charges Kubernetes) : mode
  privilégié, socket d'un moteur de conteneurs monté, réseau, processus ou mémoire partagée de l'hôte,
  capacités ajoutées (dangereuses à part), exécution en root, absence de limite de mémoire ou de
  processeur, système de fichiers en écriture, répertoires sensibles de l'hôte montés. Chaque écart a
  une gravité (critique, haute, moyenne, basse), une explication et une correction conseillée. Jamais
  les variables d'environnement, les commandes ni le contenu des secrets ; aucune requête de plus.
- Onglet **Configuration à risque** de chaque service et sous-page du même nom de **Parc > Inventaire
  Docker** : carte de synthèse (part des services sans écart ouvert, sans écart critique ni haut),
  filtres par gravité et « Avec une exception ».
- Exceptions justifiées et datées (366 jours au plus, à renouveler) : **Accepter le risque** sur un
  écart, avec la raison et la date de fin ; une exception expirée ne couvre plus l'écart. Nouveau
  droit **Exceptions de configuration** (enregistrer, supprimer) dans l'onglet **Inventaire Docker**
  des profils, accordé à l'installation aux profils qui modifient la configuration de GLPI. Le plugin
  ne corrige jamais rien sur les plateformes.
- API de lecture : `Evidence::hardening()` (preuve A.8.9 : taux de services sans écart ouvert,
  répartition par gravité, écarts et exceptions ; clé ajoutée, version de l'API inchangée).

## [0.11.0] - 2026-11-21

- Publiée dans le catalogue de la marketplace GLPI (`dockerinventory.xml`).

### Added

- Exposition des services ([#72](https://github.com/parime/glpi-docker-inventory/issues/72)) : à chaque
  import, le plugin relève les routes Traefik déclarées par les étiquettes des services Swarm et des
  conteneurs (hôtes, chemins, TLS, point d'entrée), les ingress Kubernetes (hôtes, chemins, TLS) et les
  services Kubernetes NodePort ou LoadBalancer, rattachés aux charges par leurs sélecteurs ; les ports
  publiés étaient déjà relevés. Onglet **Exposition** de chaque service et sous-page **Exposition** de
  **Parc > Inventaire Docker** : portée de chaque exposition (Internet probable, réseau de l'hôte, réseau
  interne, hôte seul), filtre « Exposés sur Internet ». Lecture seule : uniquement des GET,
  `GET /api/v1/services` et `GET /apis/networking.k8s.io/v1/ingresses` en plus pour Kubernetes (droits
  `get`, `list`, `watch` à ajouter au ClusterRole ; sans eux, avertissement et routes gardées).
- Avec GRC Manager : chaque exposition est comparée aux flux actifs de la matrice des flux (application
  du service ou libellé de destination, port, protocole). Une exposition sans flux documenté est
  signalée (filtre « Sans flux documenté ») et le bouton **Proposer le flux** ouvre le formulaire d'un
  nouveau flux de GRC (« publié sur Internet » ou « interne », service en destination), les valeurs
  proposées étant aussi affichées : rien n'est créé sans la personne. Sans GRC Manager, l'onglet
  fonctionne seul.
- API de lecture : `Evidence::exposure()` (services exposés et portée de chaque exposition ; clé
  ajoutée, version de l'API inchangée).

### Changed

- CI : contrôles de sécurité communs glpi-plugin-security v1.9.0 (charte commune et contrôle des menus bloquants par défaut).

## [0.10.0] - 2026-11-17

- Publiée dans le catalogue de la marketplace GLPI (`dockerinventory.xml`).

### Changed

- Documentation par rôle ([#44](https://github.com/parime/glpi-docker-inventory/issues/44)) : les README
  (français et anglais) donnent un parcours en quelques étapes pour l'administrateur, le technicien et le
  responsable sécurité ou l'auditeur, avec les chemins des menus de la 0.9.0 (**Parc > Inventaire Docker**,
  **Configuration > Plugins > Inventaire Docker**). Le détail passe dans des guides par rôle :
  `docs/administrateur.md`, `docs/technicien.md`, `docs/securite.md` et leur traduction dans `docs/en/`.

### Fixed

- Accessibilité, audit initial ([#42](https://github.com/parime/glpi-docker-inventory/issues/42)) : axe-core
  et contrôle manuel (clavier, libellés, contraste, icônes, titres, tableaux) sur 18 écrans et onglets du plugin,
  rapport dans `docs/ACCESSIBILITE.md`. Badges et textes colorés au contraste de 4,5:1
  (`bg-*-subtle text-*-emphasis` au lieu de `bg-*-lt` et `bg-* text-white`), boutons de filtre neutres,
  titre de niveau 1 sur chaque page et niveaux de titres sans saut, en-tête de colonne vide nommé, icône
  décorative masquée, liens distinguables sans la couleur. Les points du cœur de GLPI sont listés dans le
  rapport, sans correction.

## [0.9.0] - 2026-11-16

- Publiée dans le catalogue de la marketplace GLPI (`dockerinventory.xml`).

### Changed

- Une seule entrée de menu, réglages dans la page du plugin ([#35](https://github.com/parime/glpi-docker-inventory/issues/35),
  charte commune section 9) : **Parc > Inventaire Docker** remplace les quatre entrées précédentes et regroupe
  en onglets les sous-pages Services, Versions des images, Images Harbor et vulnérabilités, et une nouvelle
  sous-page **Fin de support des images** en lecture seule (services à surveiller d'abord). Les connexions,
  les registres maîtrisés et la fin de support (source et correspondances) se règlent dans
  **Configuration > Plugins > Inventaire Docker**, en onglets, avec le droit de configuration comme avant.
  Les anciennes adresses (`front/connection.php`, `front/lifecycle.php`) redirigent vers les nouvelles :
  les favoris restent valables. Droits par écran et onglets des ordinateurs et applications inchangés.
- CI : contrôles de sécurité communs v1.8.2 (scripts communs bien pris à la version appelée).
- CI : contrôles de sécurité communs v1.8.1 ([parime/glpi-plugin-security#15](https://github.com/parime/glpi-plugin-security/issues/15)). Les points d'entrée,
  la couverture de la matrice d'accès (toujours bloquante, `access-matrix-file`) et les exigences marketplace
  sont vérifiés par le job commun ; les trois copies de `.github/scripts/` sont retirées.

### Added

- Encadré « À quoi sert cette page ? » en tête des pages d'usage ([#39](https://github.com/parime/glpi-docker-inventory/issues/39)) :
  Services de conteneurs, Versions des images, Images Harbor et vulnérabilités, Fin de support des images et
  Connexions Docker expliquent ce que fait la page et ce qui est attendu (repliable, choix mémorisé ; charte commune).
- Langue des chaînes sources ([#37](https://github.com/parime/glpi-docker-inventory/issues/37)) : règle écrite
  (msgid en français, sans mélange, `.pot` source unique de Hosted Weblate) et plan de migration vers
  l'anglais dans `ROADMAP.md`, à engager seulement sur retour des traducteurs. Rapport en lecture
  seule `tools/msgid-report.php` (chaînes, traductions anglaises manquantes, collisions), affiché par
  le job « Traductions complètes ».

## [0.8.0] - 2026-11-16

- Publiée dans le catalogue de la marketplace GLPI (`dockerinventory.xml`).

### Added

- Source Kubernetes en lecture seule ([#31](https://github.com/parime/glpi-docker-inventory/issues/31)) :
  nouveau type de connexion « Kubernetes » (adresse de l'API en HTTPS, jeton d'un compte de service
  chiffré avec la clé de GLPI, certificat d'autorité facultatif, namespaces ignorés, plusieurs
  clusters). « Tester la connexion » affiche la version du cluster et le nombre de namespaces
  visibles, et un avertissement, non un succès, si le compte ne voit rien ou ne peut pas lire une
  ressource. Import (bouton et action automatique horaire `kubernetesimport`) : chaque namespace
  qui a des charges devient une Application native, chaque conteneur d'un Deployment, StatefulSet,
  DaemonSet ou CronJob une fiche Service (image, tag, empreinte lue dans le statut des pods,
  réplicas prêts et voulus, état), nœuds rattachés aux ordinateurs par nom ou adresse IP. Charges
  disparues « retirées », jamais supprimées ; un import vide ne vide jamais l'inventaire ;
  pagination (`limit`/`continue`) et limite de requêtes (429) non bloquantes. Mêmes versions
  (déployée, dernière, écart) et vulnérabilités Harbor que les services Swarm ; tickets réglés sur
  la connexion Kubernetes comme sur Portainer (désactivés par défaut). Manifeste du ServiceAccount
  et du ClusterRole en lecture seule dans les README. Faux serveur `tests/mock-kubernetes`
  (données fictives). Mise à jour automatique depuis la 0.7.0.

### Changed

- Écritures communes des imports (stacks, applications, services, nœuds, retraits) regroupées dans
  `InventoryStore`, partagé par Portainer et Kubernetes ; le rattachement d'un nœud à un ordinateur
  sait aussi chercher par adresse IP.

## [0.7.0] - 2026-11-16

- Publiée dans le catalogue de la marketplace GLPI (`dockerinventory.xml`).

### Added

- Preuves pour GRC Manager ([#10](https://github.com/parime/glpi-docker-inventory/issues/10)) :
  API de lecture publique `GlpiPlugin\Dockerinventory\Api\Evidence` (version 1, lecture seule),
  qui ne renvoie que ce que l'utilisateur voit dans le plugin (droit de lecture des services,
  entités actives ; connexions réservées au droit « Configuration ») : inventaire (services, stacks,
  nœuds, dernier import réussi de chaque connexion), écarts de version, vulnérabilités Harbor des
  images déployées, configuration et provenance des images, changements de version. Journal des
  changements de version (autre image, autre tag, image reconstruite sous le même tag) écrit par
  l'import Portainer, et date du dernier import réussi de chaque connexion (nouvelle table et
  nouvelle colonne, créées à la mise à jour du plugin).

## [0.6.0] - 2026-11-15

- Publiée dans le catalogue de la marketplace GLPI (`dockerinventory.xml`).

### Fixed

- Portainer : un compte sans droits ne donne plus un import « Réussi » avec 0 élément
  ([#46](https://github.com/parime/glpi-docker-inventory/issues/46)). « Tester la connexion » et
  l'import affichent un avertissement (nouveau statut « Avertissement ») quand le compte ne voit
  aucun environnement, ou quand les instantanés de Portainer annoncent des services ou des
  conteneurs qu'il ne voit pas ; rien n'est alors retiré. Un import qui ne trouve plus rien alors que
  des services étaient actifs ne retire rien : une perte de droits ne vide jamais l'inventaire.
  Les piles Swarm déployées hors Portainer deviennent des Applications, comme les projets Compose ;
  les volumes anonymes s'affichent « volume anonyme » suivi de 12 caractères. README : droits
  minimaux du compte Portainer et limites de CE (pas de rôle en lecture seule).

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

- Registres maîtrisés cloisonnés par entité ([#48](https://github.com/parime/glpi-docker-inventory/issues/48)) :
  l'onglet « Images Harbor », la carte Harbor des services et la page des connexions n'affichent
  plus que les hôtes des connexions Harbor des entités actives (ils montraient ceux de toutes les
  entités) ; la provenance des images reste évaluée avec tous les registres maîtrisés. Cas ajouté à
  la matrice d'accès (objet d'une autre entité).
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

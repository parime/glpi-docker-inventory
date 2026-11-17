# Audit d'accessibilité

Audit initial des écrans du plugin Inventaire Docker
([#42](https://github.com/parime/glpi-docker-inventory/issues/42)), version 0.9.0, sur GLPI 12 en
français, thème « auror », navigateur Chromium sans interface, fenêtre de 1366 × 900. Référentiel :
WCAG 2.2 niveaux A et AA (base du RGAA 4.1), avec les bonnes pratiques d'axe-core.

## Méthode

1. **Contrôle automatique** : axe-core 4.10.2 injecté par Playwright dans chaque page, règles
   `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`, `wcag22aa` et `best-practice`.
2. **Contrôle manuel assisté**, sur les mêmes pages :
   - navigation au clavier : 60 appuis sur Tab par page, élément atteint et contour de focus visible ;
   - libellés des champs (règles `label` et `select-name` d'axe, puis relecture des formulaires) ;
   - contraste des badges (règle `color-contrast`, puis relecture des captures) ;
   - icônes Tabler (`ti ti-*`) du contenu principal sans `aria-hidden="true"` ni `aria-label` ;
   - hiérarchie des titres (`h1` à `h6` visibles, dans l'ordre) ;
   - tableaux : présence de cellules d'en-tête `th`.

Pages contrôlées (18) :

| Écran | Adresse |
| --- | --- |
| Parc > Inventaire Docker : Services | `front/service.php` |
| Versions des images | `front/version.php` |
| Images Harbor et vulnérabilités | `front/version.php?view=harbor` |
| Fin de support des images | `front/version.php?view=eol` |
| Configuration : Connexions Docker | `front/config.php` |
| Configuration : Registres maîtrisés | `front/config.php?tab=registries` |
| Configuration : Fin de support des images | `front/config.php?tab=lifecycle` |
| Nouvelle connexion : choix du type | `front/connection.form.php` |
| Nouvelle connexion Portainer, nouvelle connexion Kubernetes | `front/connection.form.php?type=...` |
| Connexion Portainer, WUD, Harbor, Kubernetes existantes | `front/connection.form.php?id=...` |
| Fiche d'un service | `front/service.form.php?id=...` |
| Onglets « Services » et « Versions » d'une application | `front/appliance.form.php?id=...&forcetab=...` |

## Corrigé dans le plugin

| Constat | Critère WCAG | Correction |
| --- | --- | --- |
| Badges `bg-X-lt` (vert, orange, jaune, cyan, sarcelle : 2 à 2,8:1 ; rouge, bleu, gris : 4 à 4,4:1) et badges pleins `bg-X text-white` (vert, orange, bleu clair : 2,1 à 3,1:1) | 1.4.3 Contraste (4,5:1) | Badges en `bg-S-subtle text-S-emphasis` (couleurs sémantiques de Bootstrap 5.3, variantes claire et sombre fournies par Tabler) : même teinte de sens, texte foncé. Correspondance des couleurs dans le partiel `templates/_tone.html.twig` (macros `badge()` et `text()`) |
| Textes d'avertissement et d'erreur en `text-warning` (2,1:1) et `text-danger` | 1.4.3 | `text-warning-emphasis`, `text-danger-emphasis` |
| Boutons de filtre des versions et de la fin de support en `btn-outline-{couleur}` (2,1 à 3:1) | 1.4.3 | Contour neutre `btn-outline-secondary`, `btn-secondary` quand le filtre est actif ; la couleur reste portée par le compteur et l'icône |
| Pas de titre de niveau 1 sur les sous-pages, la page de configuration et le choix du type de connexion | 1.3.1, 2.4.6 | `h1` visible (titre de la configuration, question du choix du type) ou réservé aux lecteurs d'écran (nom de l'onglet actif), dans `_section_nav.html.twig` |
| Titres de niveau 3 juste après le `h1` des formulaires de connexion (cartes d'import WUD, Harbor, Portainer, Kubernetes) ; titres de niveau 4 sous un `h3` dans le choix du type | 1.3.1 | Niveaux 2, apparence inchangée (`card-title`, `h4`) |
| En-tête de colonne vide (« Images en service sans correspondance ») | 1.3.1 | Texte « Actions » réservé aux lecteurs d'écran |
| Icônes devant les noms (application, service, ordinateur) sans `aria-hidden` : fiche d'un service, onglet « Services » d'une application | 1.1.1 | `aria-hidden="true"` |
| Titres `h3` sous le `h1` des fiches dans les onglets du plugin (Services et Versions d'une application, Services du nœud, Vulnérabilités de l'image) | 1.3.1 | Niveau 2 |
| Liens de nom de service non distinguables du texte voisin dans les tableaux, liens « endoflife.date » et « Voir toutes les versions des images » dans une phrase | 1.4.1 Utilisation de la couleur | Nom de service en demi-gras, liens des phrases soulignés |

Après correction, axe ne relève plus aucune erreur dans le contenu produit par le plugin, hors
points ci-dessous.

## Vérifié sans écart

- **Icônes** : toutes les icônes Tabler du contenu du plugin sont décoratives et portent
  `aria-hidden="true"` (une seule exception, corrigée) ; les boutons à icône seule ont un texte.
- **Tableaux** : tous les tableaux du plugin (versions, conteneurs sans service, images déployées,
  artefacts Harbor, correspondances image → produit, nœuds) ont une ligne d'en-têtes `th`.
- **Libellés** : aucun champ sans libellé dans les formulaires du plugin (macros de formulaire du
  cœur), aide visible sous les champs.
- **Clavier** : tous les liens, onglets, boutons et champs du plugin sont atteints dans l'ordre de
  lecture, avec un contour de focus visible ; aucun piège au clavier ; le titre de l'encadré « À quoi sert
  cette page ? » est le premier élément atteint et se replie à la touche Entrée (`role="button"`,
  `aria-expanded`).
- **Langue** : `lang="fr"` sur la page.

## Relève du cœur de GLPI (signalé, non corrigé ici)

| Constat | Où | Critère |
| --- | --- | --- |
| Couleur primaire du thème « auror » (jaune `#fec95c`) : boutons `btn-outline-primary` à 1,5:1 (« Vérifier les registres maintenant », « Lire WUD maintenant », etc.) | Tous les boutons primaires, cœur et plugins | 1.4.3 |
| En-tête des formulaires : nom de l'élément et pastille d'état à 4,1:1 (`#606f91` sur `#e6e9f0`) ; champ en lecture seule à 3,8:1 | Formulaires d'éléments | 1.4.3 |
| Menu de l'utilisateur : profil, entité et avatar sous le contraste attendu | En-tête de page | 1.4.3 |
| Titres des macros `fields.largeTitle()` et `fields.smallTitle()` en `h4` directement sous le `h1` | Formulaires utilisant ces macros | 1.3.1 |
| Lien d'information des listes déroulantes (`comment_link_*`) sans nom accessible | Listes déroulantes d'objets (environnement, catégorie, groupe, modèle) | 2.4.4, 4.1.2 |
| `accesskey` en double dans le menu | Menu principal | bonne pratique |
| Bouton « Réduire le menu » de 22 px de haut | Menu latéral | 2.5.8 Taille de la cible |
| Pas de `h1` sur les pages de recherche ; focus des boutons fantômes et des listes Select2 peu visible | Recherche, navigation entre fiches | 1.3.1, 2.4.7 |

## Reste à faire

- Passer à axe les onglets **Services du nœud** d'un ordinateur, **Versions** d'un service et
  **Inventaire Docker** d'un profil (corrigés à la relecture, non mesurés), ainsi que les tickets
  créés par le plugin.
- Ajouter une **légende** (`caption` ou `aria-labelledby`) aux tableaux du plugin pour les relier au
  titre de leur carte.
- Tester avec un **lecteur d'écran** (NVDA, VoiceOver) et un zoom à 200 % et 400 % (reflow).
- Vérifier les **thèmes sombres** de GLPI : les variantes sombres de `bg-*-subtle` et
  `text-*-emphasis` sont fournies par Tabler, mais n'ont pas été mesurées.
- Faire remonter à la charte commune le choix des classes de badge (`bg-*-lt` y est prescrit et
  n'atteint pas 4,5:1) pour que tous les plugins suivent le même correctif.
- Signaler les points du cœur à GLPI.

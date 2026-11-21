# Guide du technicien

[🇬🇧 English](en/technician.md) · [Retour au README](../README.md)

Pour la personne qui suit les applications en conteneurs au quotidien : ce qui tourne, en quelle
version, s'il faut mettre à jour, si l'image est vulnérable, et les tickets qui en découlent. Tout
se trouve dans **Parc > Inventaire Docker** (droit **Lire** du plugin, donné par l'administrateur).
Le plugin est en lecture seule : il ne redéploie, ne redémarre et ne met à jour rien lui-même.

- [Parcours en 5 étapes](#parcours-en-5-étapes)
- [Services](#services)
- [Versions des images](#versions-des-images)
- [Images Harbor et vulnérabilités](#images-harbor-et-vulnérabilités)
- [Fin de support des images](#fin-de-support-des-images)
- [Onglets des applications, ordinateurs et services](#onglets-des-applications-ordinateurs-et-services)
- [Tickets](#tickets)

## Parcours en 5 étapes

1. Ouvrir **Parc > Inventaire Docker** : la sous-page **Services** liste tous les services avec
   leur image, leur tag, leurs réplicas et leur état. L'encadré « À quoi sert cette page ? »
   rappelle ce qui est attendu.
2. Onglet **Versions des images** : filtrer sur l'écart « Version majeure » ou « Version mineure »
   pour voir ce qui est en retard.
3. Onglet **Images Harbor et vulnérabilités** : repérer les images aux vulnérabilités critiques ou
   hautes.
4. Onglet **Fin de support des images** : planifier d'abord les images dont la branche n'est plus
   maintenue.
5. Traiter les **tickets** créés par le plugin (si l'administrateur les a activés) dans
   **Assistance > Tickets** : chacun pointe vers le service concerné.

## Services

**Parc > Inventaire Docker**, sous-page **Services** : un service Swarm, un service Compose, un
conteneur autonome ou un conteneur Kubernetes par ligne, avec image, tag, empreinte, réplicas (ou
conteneurs), état, application et connexion d'origine. Un service disparu de la source est
« **retiré** », jamais supprimé : son historique reste consultable. La fiche d'un service montre
aussi ses ports publiés, réseaux, volumes nommés et le nom de ses secrets (jamais leur contenu).

## Versions des images

**Parc > Inventaire Docker**, sous-page **Versions des images** : pour chaque service, version
déployée, dernière version et **écart**, avec un badge coloré :

| Badge | Sens |
| --- | --- |
| Version majeure | une version majeure plus récente existe |
| Version mineure | une version mineure plus récente existe |
| Correctif | seul un correctif plus récent existe |
| Empreinte seule | même tag, mais l'image publiée a changé |
| Non suivable | tag mobile (`latest`, `stable`) ou sans numéro de version : seule l'empreinte dit si l'image a changé |
| À jour | rien de plus récent |

- Filtres par écart, par environnement de l'application et par fin de support ; compteurs
  cliquables.
- « **Recherche avancée et export** » ouvre la recherche de GLPI sur les mêmes colonnes (export
  CSV, PDF...).
- « **Vérifier les registres maintenant** » relance la lecture des registres (Docker Hub, Harbor)
  pour les services que WUD ne couvre pas.
- Les conteneurs que WUD suit sans service correspondant sont listés à part, avec la raison.
- Des réplicas dans des versions différentes (mise à jour en cours ou bloquée) sont signalés.
- Comparaison des versions : même nombre de composantes que le tag déployé (`16` suit `17`, pas
  `16.4`), même variante (`-alpine`), préversions (`-rc1`) ignorées sauf si une préversion est
  déployée.

## Images Harbor et vulnérabilités

**Parc > Inventaire Docker**, sous-page **Images Harbor et vulnérabilités** : projets, dépôts et
artefacts de Harbor avec le résumé de leur analyse (critiques, hautes, moyennes, basses,
corrigibles). Un service est rattaché à son artefact par l'**empreinte** de l'image qui tourne,
seule preuve que l'analyse porte sur l'image déployée, sinon par le **tag**. « Empreinte
différente de celle de Harbor » signale une image reconstruite, ou un index multi-plateforme dont
Harbor n'a reçu qu'une plateforme. Une analyse « en cours » ou « en attente » est affichée comme
telle.

Le compteur et le filtre « **Seulement hors registre maîtrisé** » montrent les images qui ne
viennent d'aucun registre autorisé par l'administrateur.

## Fin de support des images

**Parc > Inventaire Docker**, sous-page **Fin de support des images** (si l'administrateur a activé
la source) : services dont la branche déployée n'est plus, ou bientôt plus, maintenue, à surveiller
d'abord.

| Badge | Sens |
| --- | --- |
| Fin de support (rouge) | la branche ne reçoit plus de correctifs |
| Bientôt en fin de support (orange) | fin de support dans N jours |
| Maintenue (vert) | branche maintenue |
| Support inconnu (gris) | pas de correspondance image → produit, tag `latest`, produit ou branche inconnus, source pas encore lue (la raison est affichée) |

Une image sans correspondance n'est jamais « Maintenue » par défaut : demandez à l'administrateur
d'ajouter la correspondance. Branche d'un tag : la plus précise qui commence la version (`1.24` pour
`1.24.0-alpine`, `13` pour `13.4`) ; un tag moins précis (`7`) suit la branche la plus récente
(`7.4`) ; un nom de code (`bookworm-slim`) donne la branche de ce nom.

## Onglets des applications, ordinateurs et services

| Objet | Onglet | Contenu |
| --- | --- | --- |
| Application (stack ou namespace) | **Services**, **Versions** | services de la stack, leurs versions |
| Ordinateur (nœud Swarm, hôte Docker, nœud Kubernetes) | **Services du nœud** | services qui tournent sur la machine |
| Service | **Versions** | versions, fin de support, carte « Vulnérabilités de l'image (Harbor) », liens vers les tickets |
| Service | **Configuration à risque** | écarts de sécurité (privilégié, root, socket Docker, réseau de l'hôte, limites…), gravité, correction conseillée, exceptions en cours |

## Tickets

Si l'administrateur les a activés, le plugin crée trois sortes de tickets, rattachés à
l'application de la stack :

- **mise à jour** : « Mettre à jour le service X de la stack Y : 1.2 → 1.4 », avec l'image, les
  versions, l'écart et un lien vers le service ;
- **fin de support** : la branche déployée arrive en fin de support ;
- **vulnérabilité** : l'image qui tourne a des vulnérabilités au-delà du seuil.

Règles communes :

- **un ticket par service** : tant qu'il est ouvert, un **suivi** est ajouté quand la situation
  change (nouvelle version, autre image, écart résolu), jamais un second ticket ;
- **rien n'est changé automatiquement** sur un ticket existant : ni statut (ni réouverture, ni
  clôture quand l'écart est résolu), ni priorité, ni affectation. C'est à vous de résoudre le
  ticket une fois la mise à jour faite ;
- ticket résolu ou clos : un nouveau ticket seulement pour une nouvelle dernière version, une autre
  branche ou une autre image ;
- le lien vers le ticket apparaît dans l'onglet **Versions** du service.

# Guide de l'administrateur

[🇬🇧 English](en/administrator.md) · [Retour au README](../README.md)

Pour la personne qui installe le plugin, déclare les sources (Portainer, What's Up Docker, Harbor,
Kubernetes) et donne les droits. Tous les réglages sont dans **Configuration > Plugins >
Inventaire Docker** (droit de configuration de GLPI), en trois onglets : **Connexions Docker**,
**Registres maîtrisés** et **Fin de support des images**. Il n'y a pas d'autre menu de réglage.

- [Première configuration (8 étapes)](#première-configuration-8-étapes)
- [Installation](#installation)
- [Droits minimaux dans les sources](#droits-minimaux-dans-les-sources)
- [Connexion Portainer](#connexion-portainer)
- [Connexion What's Up Docker](#connexion-whats-up-docker)
- [Connexion Harbor (versions et vulnérabilités)](#connexion-harbor-versions-et-vulnérabilités)
- [Connexion Kubernetes](#connexion-kubernetes)
- [Registres maîtrisés](#registres-maîtrisés)
- [Fin de support des images](#fin-de-support-des-images)
- [Tickets de mise à jour, de fin de support et de vulnérabilité](#tickets-de-mise-à-jour-de-fin-de-support-et-de-vulnérabilité)
- [Droits des utilisateurs de GLPI](#droits-des-utilisateurs-de-glpi)
- [Actions automatiques](#actions-automatiques)
- [Réseaux privés sous GLPI 12, miroirs](#réseaux-privés-sous-glpi-12-miroirs)

## Première configuration (8 étapes)

1. Installer et activer le plugin : **Configuration > Plugins**, « Inventaire Docker »
   ([Installation](#installation)).
2. Dans Portainer (ou Kubernetes), créer un **compte dédié en lecture seule** et son jeton
   ([droits minimaux](#droits-minimaux-dans-les-sources)).
3. **Configuration > Plugins > Inventaire Docker**, onglet **Connexions Docker** : « Ajouter une
   connexion », choisir **Portainer** (ou **Kubernetes**), saisir l'adresse et le jeton, choisir
   l'environnement des applications créées, puis « Ajouter ».
4. Sur la connexion : **« Tester la connexion »**, puis **« Importer maintenant »**. Un
   avertissement (et non un succès) signale un compte qui ne voit rien.
5. Facultatif : ajouter une connexion **What's Up Docker** (« Lire WUD maintenant ») ou **Harbor**
   (« Lire Harbor maintenant ») pour les versions et les vulnérabilités.
6. Facultatif : onglet **Registres maîtrisés** (d'où les images doivent venir) et onglet **Fin de
   support des images** (désactivé par défaut).
7. **Administration > Profils**, onglet **Inventaire Docker** : donner le droit **Lire** aux
   profils des techniciens et des responsables sécurité.
8. Vérifier le résultat dans **Parc > Inventaire Docker** : sous-page **Services**, puis **Versions
   des images**. Les imports tournent ensuite seuls toutes les heures.

## Installation

Téléchargez l'archive `glpi-docker-inventory-X.Y.Z.zip` de la
[dernière version](https://github.com/parime/glpi-docker-inventory/releases/latest) (son empreinte
SHA-256 est publiée à côté), puis décompressez-la dans le dossier `plugins` de GLPI :

```sh
cd /var/www/glpi/plugins
unzip glpi-docker-inventory-X.Y.Z.zip   # crée le dossier dockerinventory/
```

Puis **Configuration > Plugins**, installer et activer « Inventaire Docker ».

Où se trouve quoi (depuis la version 0.9.0) :

| Écran | Chemin | Droit |
| --- | --- | --- |
| Consultation | **Parc > Inventaire Docker** : sous-pages **Services**, **Versions des images**, **Images Harbor et vulnérabilités**, **Fin de support des images** | Lire (profil, onglet « Inventaire Docker ») |
| Réglages | **Configuration > Plugins > Inventaire Docker** : onglets **Connexions Docker**, **Registres maîtrisés**, **Fin de support des images** | Configuration de GLPI en modification |
| Droits | **Administration > Profils**, onglet **Inventaire Docker** | Profils de GLPI |

Les anciennes adresses (*Configuration > Connexions Docker*, *Configuration > Fin de support des
images*, jusqu'à la version 0.8.0) redirigent vers les nouvelles : les favoris restent valables.

## Droits minimaux dans les sources

Le plugin n'envoie que des requêtes de lecture (`GET`). Utilisez toujours un compte dédié à GLPI :

| Source | Compte conseillé |
| --- | --- |
| Portainer BE | Rôle **Read-only user** sur chaque environnement à inventorier (*Environments > Manage access*), puis jeton d'accès (*My account > Access tokens*) |
| Portainer CE | Pas de rôle en lecture seule : voir ci-dessous |
| What's Up Docker | Utilisateur et mot de passe seulement si WUD demande une authentification |
| Harbor | **Compte robot** en lecture seule : lecture des projets, dépôts et artefacts, *pull* pour la liste des tags ; aucun droit d'écriture, de suppression ni de lancement d'analyse |
| Kubernetes | Compte de service limité à `get`, `list`, `watch` ([manifeste](#connexion-kubernetes)) |

Les clés, mots de passe et jetons sont chiffrés avec la clé de GLPI et ne sont jamais réaffichés.

**Portainer CE.** Un utilisateur standard doit d'abord recevoir l'accès aux environnements
(*Environments > Manage access*). Même alors, il ne voit que les ressources dont le contrôle
d'accès est « Public » ou qui lui sont attribuées (à lui ou à son équipe) : les stacks, services et
conteneurs déployés par un administrateur, ou hors de Portainer, lui restent invisibles, et l'API
répond par des listes vides sans erreur. Pour un inventaire complet sous CE : passer les ressources
en accès « Public » ou les attribuer à l'équipe du compte dédié, ou utiliser le jeton d'un **compte
administrateur dédié** à GLPI (à garder secret et à révoquer au moindre doute).

Le plugin détecte un compte qui ne voit rien : « Tester la connexion » et l'import affichent alors
un **avertissement**, et non un succès, quand le compte ne voit aucun environnement, ou quand
Portainer annonce des services ou des conteneurs que le compte ne voit pas. Rien n'est alors retiré
de l'inventaire : une perte de droits ne vide jamais l'inventaire.

## Connexion Portainer

1. **Configuration > Plugins > Inventaire Docker**, onglet **Connexions Docker** : « Ajouter une
   connexion », choisir **Portainer**.
2. Saisir l'adresse (`https://portainer.example.org:9443`) et la clé, choisir l'**environnement**
   (Production, Préproduction...) à donner aux applications que l'import créera, « Ajouter ».
3. « **Tester la connexion** », puis « **Importer maintenant** ». L'import tourne ensuite toutes
   les heures (action automatique `portainerimport`).

Une même connexion peut mêler environnements Swarm et hôtes Docker autonomes (stacks Compose et
conteneurs hors Compose). Ce que le plugin écrit hors de ses propres tables : uniquement la
création, une fois, de l'**Application** d'une stack quand aucune application de ce nom n'existe
dans l'entité de la connexion. Une application existante, un ordinateur ou un environnement ne
sont jamais modifiés ni créés. Les valeurs des variables d'environnement et le contenu des secrets
ne sont jamais lus.

## Connexion What's Up Docker

[What's Up Docker](https://github.com/getwud/wud) (WUD) connaît la dernière version publiée de
chaque image. Le plugin lit ce résultat sans rien modifier dans WUD.

1. Importer d'abord Portainer : les conteneurs vus par WUD sont rattachés aux services importés.
2. Onglet **Connexions Docker** : « Ajouter une connexion », choisir **What's Up Docker**, saisir l'adresse
   (`https://wud.example.org:3000`) et, si besoin, l'utilisateur et le mot de passe.
3. « Tester la connexion » (version de WUD, nombre de conteneurs suivis), puis « **Lire WUD
   maintenant** ». Ensuite toutes les heures (`wudimport`).

> La correspondance avec l'API de WUD suit la documentation publique de WUD 8.x et un faux WUD
> (`tests/mock-wud`) ; elle reste à valider sur une vraie instance
> ([#2](https://github.com/parime/glpi-docker-inventory/issues/2)).

**Sans WUD**, le plugin lit lui-même la liste des tags dans le registre de l'image, en lecture
seule : **Docker Hub** sans compte (au plus une lecture par image et par jour, arrêt dès une limite
de requêtes ; seuls les noms des images publiques sont envoyés) et **Harbor** par une connexion
Harbor. Un registre inconnu ou privé sans identifiants donne une version « non vérifiée », sans
erreur. Pour ne jamais interroger Docker Hub, désactiver l'action automatique `registrycheck`.

## Connexion Harbor (versions et vulnérabilités)

1. Onglet **Connexions Docker** : « Ajouter une connexion », choisir **Harbor**, saisir l'adresse
   (`https://harbor.example.org`), le compte robot en lecture seule et, s'il diffère, l'hôte tel
   qu'il est écrit dans les images (`registry.example.org:5000`). La connexion couvre les services
   de son entité et de ses sous-entités.
2. « Tester la connexion » (nombre de projets visibles), puis « **Vérifier les registres
   maintenant** » (versions) et « **Lire Harbor maintenant** » (projets, dépôts, artefacts et
   résumé d'analyse de vulnérabilités). Ensuite toutes les heures (`registrycheck`,
   `harborimport`).

Le plugin ne lance aucune analyse et ne modifie rien dans Harbor. Pagination par pages de 100
(20 pages au plus par liste) ; un artefact, un dépôt ou un projet absent d'une liste lue en entier
est « retiré », jamais supprimé, et une liste en erreur ne retire rien. Une erreur sur un dépôt
n'empêche pas la lecture des autres ; une limite de requêtes (429) arrête les appels jusqu'à la
lecture suivante.

> Écrit d'après la documentation publique de l'API de Harbor 2.x et un faux Harbor
> (`tests/mock-harbor`) ; à valider sur un vrai Harbor 2.x.

## Connexion Kubernetes

Pour les applications qui tournent sur un cluster Kubernetes (sans Portainer) ; une connexion par
cluster.

1. **Dans le cluster**, créer le compte de service et son ClusterRole en lecture seule :

```yaml
# glpi-inventaire-lecture.yaml : compte de service en lecture seule pour GLPI
apiVersion: v1
kind: Namespace
metadata:
  name: glpi-inventaire
---
apiVersion: v1
kind: ServiceAccount
metadata:
  name: glpi-lecture
  namespace: glpi-inventaire
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRole
metadata:
  name: glpi-inventaire-lecture
rules:
  - apiGroups: [""]
    resources: ["namespaces", "nodes", "pods", "services"]
    verbs: ["get", "list", "watch"]
  - apiGroups: ["apps"]
    resources: ["deployments", "statefulsets", "daemonsets", "replicasets"]
    verbs: ["get", "list", "watch"]
  - apiGroups: ["batch"]
    resources: ["cronjobs"]
    verbs: ["get", "list", "watch"]
  - apiGroups: ["networking.k8s.io"]
    resources: ["ingresses"]
    verbs: ["get", "list", "watch"]
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRoleBinding
metadata:
  name: glpi-inventaire-lecture
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: ClusterRole
  name: glpi-inventaire-lecture
subjects:
  - kind: ServiceAccount
    name: glpi-lecture
    namespace: glpi-inventaire
---
# Jeton longue durée du compte de service (Kubernetes 1.24 et plus).
apiVersion: v1
kind: Secret
metadata:
  name: glpi-lecture-jeton
  namespace: glpi-inventaire
  annotations:
    kubernetes.io/service-account.name: glpi-lecture
type: kubernetes.io/service-account-token
```

   Puis récupérer le jeton, le certificat d'autorité et l'adresse de l'API, et vérifier que le
   compte ne peut rien modifier (la dernière commande doit répondre `no`) :

```sh
kubectl apply -f glpi-inventaire-lecture.yaml
kubectl -n glpi-inventaire get secret glpi-lecture-jeton -o jsonpath='{.data.token}' | base64 -d
kubectl -n glpi-inventaire get secret glpi-lecture-jeton -o jsonpath='{.data.ca\.crt}' | base64 -d
kubectl config view --minify -o jsonpath='{.clusters[0].cluster.server}'
kubectl auth can-i create deployments -A --as=system:serviceaccount:glpi-inventaire:glpi-lecture
```

2. Onglet **Connexions Docker** : « Ajouter une connexion », choisir **Kubernetes**, saisir l'adresse de l'API
   (`https://k8s.example.org:6443`, HTTPS obligatoire), le jeton et, si le cluster a sa propre
   autorité, le contenu de `ca.crt`. Les namespaces `kube-system`, `kube-public` et
   `kube-node-lease` sont ignorés par défaut (liste modifiable, `*` accepté : `cattle-*`).
3. « **Tester la connexion** » affiche la version du cluster et le nombre de namespaces visibles,
   ou un avertissement qui nomme les ressources illisibles.
4. « **Importer maintenant** », puis toutes les heures (`kubernetesimport`). Chaque namespace qui a
   des charges devient une Application, chaque conteneur d'un Deployment, StatefulSet, DaemonSet ou
   CronJob une fiche Service ; les nœuds sont rattachés aux ordinateurs par nom ou adresse IP.

Limites connues : init containers, Jobs isolés et pods sans contrôleur ne sont pas inventoriés ;
aucun « watch » n'est ouvert, chaque import est une lecture ponctuelle.

## Registres maîtrisés

**Configuration > Plugins > Inventaire Docker**, onglet **Registres maîtrisés** : liste des hôtes
d'où les images doivent venir (`registry.example.org:5000`, `docker.io` pour Docker Hub). Liste
vide : les hôtes des connexions Harbor. Une image déployée venant d'ailleurs est signalée **« Hors
registre maîtrisé »** aux techniciens et aux responsables sécurité.

## Fin de support des images

**Désactivé par défaut : aucun appel sortant tant que la source n'est pas activée.** Onglet **Fin
de support des images** de la page de configuration :

1. Cocher « **Lire les cycles de vie (fin de support)** », choisir le seuil de « bientôt en fin de
   support » (90 jours par défaut), « Enregistrer ».
2. « **Mettre à jour maintenant** », ou attendre l'action automatique quotidienne `lifecyclecheck`.
   Seul le nom du produit (`postgresql`, `nodejs`...) est envoyé à
   [endoflife.date](https://endoflife.date), au plus une fois par jour et par produit.
3. Vérifier la **correspondance image → produit** : 33 images officielles courantes sont fournies,
   modifiables. Les images en service sans correspondance sont listées avec « **Ajouter une
   correspondance** ». Le produit n'est jamais deviné : sans correspondance, « Support inconnu ».

## Tickets de mise à jour, de fin de support et de vulnérabilité

**Désactivés par défaut.** Ils se règlent sur la connexion **Portainer** ou **Kubernetes** des
services, sections « Tickets de mise à jour », « Tickets de fin de support » et « Tickets de
vulnérabilité » :

| Ticket | Réglage | Déclencheur |
| --- | --- | --- |
| Mise à jour | « Créer des tickets de mise à jour » et le seuil (majeure seulement, mineure ou majeure, tout écart) | Lecture de WUD ou des registres |
| Fin de support | « Créer des tickets de fin de support » et le délai (90 jours par défaut) | Lecture des cycles de vie |
| Vulnérabilité | « Créer des tickets de vulnérabilité » et le seuil (critiques, ou critiques et hautes) | Lecture de Harbor |

Catégorie, **groupe chargé du ticket** et **modèle de ticket** sont communs aux trois. Un seul
ticket par service, des suivis quand la situation change, jamais de doublon ni de changement de
statut, de priorité ou d'affectation. Le détail, côté technicien, est dans le
[guide du technicien](technicien.md#tickets).

## Droits des utilisateurs de GLPI

- **Administration > Profils**, onglet **Inventaire Docker** : droit **Lire** sur les services de
  conteneurs. Il donne accès à **Parc > Inventaire Docker** et à ses quatre sous-pages, aux onglets
  des applications et des ordinateurs, et aux preuves lues par GRC Manager.
- Les connexions, registres maîtrisés et réglages de fin de support demandent le droit
  **Configuration** de GLPI en modification.
- Tout est filtré sur les entités actives de l'utilisateur.

## Actions automatiques

**Configuration > Actions automatiques** :

| Action | Fréquence | Rôle |
| --- | --- | --- |
| `portainerimport` | toutes les heures | Import Portainer |
| `kubernetesimport` | toutes les heures | Import Kubernetes |
| `wudimport` | toutes les heures | Lecture de WUD |
| `registrycheck` | toutes les heures | Versions par Docker Hub et Harbor (sans WUD) |
| `harborimport` | toutes les heures | Projets, artefacts et vulnérabilités Harbor |
| `lifecyclecheck` | une fois par jour | Fin de support (si activée) |

## Réseaux privés sous GLPI 12, miroirs

GLPI 12 refuse par défaut les appels vers une adresse privée. Pour les autoriser pour ces sources
seulement, ajouter dans `config/local_define.php` les contextes utiles :

```php
define('GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS', [
    \GlpiPlugin\Dockerinventory\Portainer\PortainerContext::class,
    \GlpiPlugin\Dockerinventory\Wud\WudContext::class,
    \GlpiPlugin\Dockerinventory\Registry\RegistryContext::class,
    \GlpiPlugin\Dockerinventory\Kubernetes\KubernetesContext::class,
    \GlpiPlugin\Dockerinventory\Lifecycle\LifecycleContext::class,
]);
```

Miroirs : `PLUGIN_DOCKERINVENTORY_DOCKER_HUB_URL` (Docker Hub) et
`PLUGIN_DOCKERINVENTORY_LIFECYCLE_URL` (endoflife.date, modèle avec `{product}`), dans le même
fichier.

# Guide du responsable sécurité et de l'auditeur

[🇬🇧 English](en/security.md) · [Retour au README](../README.md)

Pour la personne qui doit prouver que les applications en conteneurs sont inventoriées, à jour,
non vulnérables et construites à partir d'images maîtrisées : RSSI, responsable de la conformité,
auditeur interne ou externe. Le droit **Lire** du plugin suffit (donné par l'administrateur dans
**Administration > Profils**, onglet **Inventaire Docker**) ; rien de ce qui suit ne modifie quoi
que ce soit.

- [Parcours en 5 étapes](#parcours-en-5-étapes)
- [Preuves dans GRC Manager](#preuves-dans-grc-manager)
- [Fin de support](#fin-de-support)
- [Images hors registre maîtrisé](#images-hors-registre-maîtrisé)
- [Vulnérabilités](#vulnérabilités)
- [Changements de version](#changements-de-version)
- [Ce que l'auditeur peut vérifier](#ce-que-lauditeur-peut-vérifier)

## Parcours en 5 étapes

1. Dans **GRC & Conformité > Conformité** (plugin
   [GRC Manager](https://github.com/parime/glpi-iso27001-management)), ouvrir la mesure **A.8.8** :
   la section « **Preuves de l'inventaire Docker** » donne les écarts de version et les
   vulnérabilités, lus en direct.
2. Faire de même pour **A.5.9** (inventaire), **A.8.9** (configuration et provenance des images)
   et **A.8.32** (changements de version des 90 derniers jours).
3. **Parc > Inventaire Docker**, sous-page **Fin de support des images** : relever les images dont
   la branche n'est plus maintenue.
4. Sous-page **Images Harbor et vulnérabilités**, filtre « **Seulement hors registre maîtrisé** » :
   relever les images qui ne viennent d'aucun registre autorisé.
5. Exporter si besoin par « **Recherche avancée et export** » (sous-page **Versions des images**),
   et suivre les écarts par les tickets du plugin ou par des non-conformités dans GRC Manager.

## Preuves dans GRC Manager

Avec GRC Manager installé, la fiche de quatre mesures de l'Annexe A d'ISO 27001 affiche une section
« **Preuves de l'inventaire Docker** », lue en direct par l'API de lecture du plugin
(`GlpiPlugin\Dockerinventory\Api\Evidence`, version 1) :

| Mesure | Preuve |
| --- | --- |
| A.5.9 Inventaire des informations et autres actifs associés | Services, stacks, nœuds ; date du dernier import réussi de chaque connexion (fraîcheur de l'inventaire) |
| A.8.8 Gestion des vulnérabilités techniques | Écarts de version (majeure, mineure, correctif) ; vulnérabilités Harbor des images déployées ; analyses importées de Trivy, Grype ou Docker Scout |
| A.8.9 Gestion de la configuration | Configuration des services et provenance des images (registre maîtrisé ou non) |
| A.8.32 Gestion des changements | Changements de version des 90 derniers jours (autre image, autre tag, image reconstruite sous le même tag) |

Les services dont l'image a des vulnérabilités critiques ou hautes apparaissent aussi dans **GRC &
Conformité > Sécurité technique > Vulnérabilités du parc**.

Ce que l'API garantit :

- elle ne renvoie que ce que la personne voit dans le plugin (droit **Lire**, entités actives), et
  rien sans ce droit ;
- les dates d'import des connexions ne sont données qu'aux personnes qui peuvent voir les
  connexions (droit **Configuration**) ;
- sans le plugin, ou sans ce droit, la section est simplement absente de GRC Manager.

## Fin de support

Une image peut être « à jour » dans sa branche alors que cette branche ne reçoit plus de correctifs
(une base de données en 13.x quand la branche 13 n'est plus maintenue). La sous-page **Fin de
support des images** de **Parc > Inventaire Docker** compare la branche déployée aux dates
publiées par [endoflife.date](https://endoflife.date) : **Fin de support**, **Bientôt en fin de
support**, **Maintenue** ou **Support inconnu**, avec le produit, la branche et la date.

- La source est **désactivée par défaut** ; seul le nom du produit (`postgresql`) lui est envoyé.
- Le produit n'est **jamais deviné** : une image sans correspondance reste « Support inconnu »,
  jamais « Maintenue ». Un nombre élevé de « Support inconnu » est donc un point d'attention, pas
  une bonne nouvelle.
- Hors ligne, les cycles déjà lus restent utilisés et la date de leur dernière mise à jour est
  affichée.

## Images hors registre maîtrisé

L'administrateur déclare dans l'onglet **Registres maîtrisés** de la configuration du plugin les
hôtes d'où les images doivent venir (par défaut, les hôtes des connexions Harbor). Toute image
déployée venant d'ailleurs est signalée « **Hors registre maîtrisé** » :

- compteur et filtre de la sous-page **Images Harbor et vulnérabilités** ;
- alerte sur la fiche du service ;
- provenance dans la preuve A.8.9 de GRC Manager.

## Vulnérabilités

Les vulnérabilités viennent de l'analyse faite par **Harbor** (le plugin ne lance aucune analyse).
Un service n'est rattaché à une analyse que par l'**empreinte** de l'image qui tourne, sinon par
son tag, avec la mention « empreinte différente de celle de Harbor » : c'est ce qui permet de dire
que l'analyse porte bien sur l'image déployée. Une image non analysée ou dont l'analyse a échoué
est affichée comme telle, jamais comme « sans vulnérabilité ».

### Images hors de Harbor : analyses importées

Pour les images qui viennent d'un autre registre, **Parc > Inventaire Docker**, sous-page
**Analyses importées** : déposez le rapport produit par votre outil, en CI par exemple.

| Outil | Commande qui produit le rapport |
|-------|---------------------------------|
| Trivy | `trivy image --format json --output rapport.json IMAGE` |
| Grype | `grype IMAGE --output json --file rapport.json` |
| Docker Scout | `docker scout cves --format sarif --output rapport.sarif IMAGE` |

Tout rapport SARIF 2.1.0 est aussi accepté. Le SARIF de Docker Scout ne nomme pas l'image : saisissez
alors « nom:tag » dans le champ **Image analysée**. Le fichier est lu puis oublié : taille de 20 Mo
au plus, profondeur JSON et nombre de vulnérabilités bornés, aucun contenu exécuté. Une analyse par
image, tag et empreinte : un nouveau dépôt remplace l'ancien (historique gardé).

L'analyse est rattachée aux services comme celle de Harbor : par l'empreinte, sinon par le tag. La
page donne les vulnérabilités par gravité, les corrigeables, la date de l'analyse (signalée au-delà
de 30 jours ou si elle est inconnue), le taux de services couverts et le détail des 500
vulnérabilités les plus graves. L'onglet **Versions** du service affiche la même carte. Droit
**Analyses d'images importées** du profil : importer, supprimer (accordé aux administrateurs).

### Exploitées activement d'abord (CISA KEV, EPSS)

Une fois le catalogue **CISA KEV** chargé (onglet **Exploitation connue** de la page de
configuration, voir le guide de l'administrateur), chaque vulnérabilité d'une analyse importée qui y
figure porte la pastille **Exploitée activement**, avec l'échéance de correction fixée par la CISA et,
le cas échéant, **Rançongiciel**. Si les scores **EPSS** sont aussi chargés, la probabilité
d'exploitation dans les 30 jours s'affiche, en orange au-dessus du seuil choisi (10 % par défaut).

La sous-page **Analyses importées** classe les images dans l'ordre de traitement : exploitées
activement d'abord, puis gravité, puis exposition des services (Internet, réseau de l'hôte, réseau
interne, hôte seul : badge à côté de chaque service). Le filtre **Exploitées activement** ne garde que
ces images ; la carte de l'onglet **Versions** du service affiche les mêmes pastilles. Seules les 500
vulnérabilités les plus graves de chaque analyse sont détaillées et donc rapprochées du catalogue.

## Changements de version

Chaque import Portainer note, pour chaque service, le passage à une autre image, à un autre tag, ou
à une autre empreinte sous le même tag (image reconstruite). Ce journal est en lecture seule et
jamais purgé automatiquement : il sert de preuve de gestion des changements (A.8.32).

## Exposition et matrice des flux

**Parc > Inventaire Docker**, sous-page **Exposition** (et onglet **Exposition** de chaque service) :
ports publiés, routes Traefik, ingress et services Kubernetes exposés, relevés à chaque import, avec
leur portée (Internet probable, réseau de l'hôte, réseau interne, hôte seul). Le filtre « **Exposés
sur Internet** » donne la liste des services publiés.

Avec GRC Manager, chaque exposition est comparée aux flux actifs de la **matrice des flux** : une
exposition sans flux documenté est signalée (filtre « **Sans flux documenté** »). Le bouton
**Proposer le flux** ouvre le formulaire d'un nouveau flux dans GRC (« publié sur Internet » ou
« interne », service en destination) : la personne relit, complète la justification et le
propriétaire, puis enregistre. Le plugin n'écrit jamais dans GRC.

La portée est déduite du nom d'hôte ou de l'adresse : un nom public n'est pas forcément joignable
(pare-feu, DNS interne). À vérifier avec l'équipe réseau.

### Routes réellement appliquées par Traefik

Avec une connexion **Traefik** (voir le guide de l'administrateur), « Lire Traefik maintenant »
liste les routes appliquées et les rapproche des étiquettes : **conforme**, **différente** (autres
noms d'hôte), **service non inventorié**, **hors étiquettes Docker** (fichier, Kubernetes...),
**interne à Traefik**. Les routes déclarées dans les étiquettes mais absentes de Traefik sont
listées à part. Signalements, jamais corrigés par le plugin : tableau de bord ou API de Traefik
sans authentification (`api.insecure`), route publique sans TLS ni redirection vers HTTPS, route
d'administration sans authentification, route publique sans middleware d'en-têtes de sécurité.

## Configuration à risque (A.8.9)

**Parc > Inventaire Docker**, sous-page **Configuration à risque** (et onglet du même nom de chaque
service) : réglages de sécurité relevés à chaque import, sans requête de plus que l'inventaire.

| Gravité | Écarts |
| --- | --- |
| Critique | Mode privilégié ; socket d'un moteur de conteneurs monté (`docker.sock`, `containerd.sock`…) |
| Haute | Réseau ou processus de l'hôte partagés ; capacités dangereuses (`SYS_ADMIN`, `NET_ADMIN`…) ; répertoire sensible de l'hôte monté (`/etc`, `/`, `/var/lib/docker`…) |
| Moyenne | Mémoire partagée (IPC) de l'hôte ; autres capacités ajoutées ; exécution en root (aucun utilisateur défini) |
| Basse | Aucune limite de mémoire ; aucune limite de processeur ; système de fichiers en écriture |

Swarm ne connaît ni le mode privilégié ni le partage des processus de l'hôte : ces écarts ne
concernent que les hôtes autonomes et Kubernetes. Un conteneur sans utilisateur défini est compté en
root : l'image peut en définir un, à vérifier.

Pour un écart voulu (agent de supervision qui lit le socket, par exemple), **Accepter le risque**
enregistre une exception : la raison et une date de fin (366 jours au plus). Après cette date,
l'écart compte de nouveau ; **Renouveler l'exception** prolonge après réexamen. Droit
**Exceptions de configuration** (enregistrer, supprimer) dans **Administration > Profils**, onglet
**Inventaire Docker**. Le plugin ne modifie jamais rien sur les plateformes.

La carte de synthèse donne la part des services analysés sans écart ouvert et sans écart critique
ni haut (les écarts couverts par une exception en cours ne comptent pas). GRC Manager peut la lire
par `Evidence::hardening()` comme preuve de la mesure A.8.9.

## Ce que l'auditeur peut vérifier

- **Lecture seule** : le plugin n'envoie que des requêtes de lecture aux sources, avec des comptes
  dédiés aux droits minimaux (voir le [guide de l'administrateur](administrateur.md#droits-minimaux-dans-les-sources)).
- **Rien n'est modifié automatiquement** dans GLPI : un service disparu est « retiré », pas
  supprimé ; les tickets ne changent jamais de statut, de priorité ni d'affectation tout seuls.
- **Pas de secret importé** : noms des secrets seulement, jamais leur contenu ni les valeurs des
  variables d'environnement ; clés d'API chiffrées avec la clé de GLPI.
- **Une perte de droits ne vide jamais l'inventaire** : un import qui ne voit plus rien est
  signalé par un avertissement et ne retire rien.

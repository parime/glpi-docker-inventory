# Administrator guide

[🇫🇷 Français](../administrateur.md) · [Back to the README](../../README.en.md)

For the person who installs the plugin, declares the sources (Portainer, What's Up Docker, Harbor,
Kubernetes) and grants the rights. All settings are in **Setup > Plugins > Docker Inventory** (GLPI
setup right), in four tabs: **Docker connections**, **Managed registries**, **End-of-support
mappings** and **Known exploitation**. There is no other settings menu, and nothing operational:
on-click readings ("Read now", "Update now", "Download now") are in **Assets > Docker Inventory**,
next to their result. At the top of the page, the **Plugin version** box shows the installed version
and the latest release on GitHub (the check can be turned off on a server without Internet access).

- [First setup (8 steps)](#first-setup-8-steps)
- [Installation](#installation)
- [Minimal rights in the sources](#minimal-rights-in-the-sources)
- [Portainer connection](#portainer-connection)
- [What's Up Docker connection](#whats-up-docker-connection)
- [Harbor connection (versions and vulnerabilities)](#harbor-connection-versions-and-vulnerabilities)
- [Kubernetes connection](#kubernetes-connection)
- [Managed registries](#managed-registries)
- [Image end of support](#image-end-of-support)
- [Update, end-of-support and vulnerability tickets](#update-end-of-support-and-vulnerability-tickets)
- [Rights of GLPI users](#rights-of-glpi-users)
- [Automatic actions](#automatic-actions)
- [Private networks under GLPI 12, mirrors](#private-networks-under-glpi-12-mirrors)

## First setup (8 steps)

1. Install and enable the plugin: **Setup > Plugins**, "Docker Inventory"
   ([Installation](#installation)).
2. In Portainer (or Kubernetes), create a **dedicated read-only account** and its token
   ([minimal rights](#minimal-rights-in-the-sources)).
3. **Setup > Plugins > Docker Inventory**, **Docker connections** tab: "Add a connection", choose
   **Portainer** (or **Kubernetes**), enter the address and the token, choose the environment of
   the created applications, then "Add".
4. On the connection: **"Test the connection"**. A warning (not a success) reports an account that
   sees nothing. To read without waiting for the automatic action: **Assets > Docker Inventory > Services**, "Sources" block, "**Read now**"
   (the setup page only keeps settings, #118).
5. Optional: add a **What's Up Docker** or **Harbor** connection for versions and vulnerabilities.
6. Optional: **Managed registries** tab (where images must come from) and **Image end of support**
   tab (disabled by default).
7. **Administration > Profiles**, **Docker Inventory** tab: give the **Read** right to the
   profiles of technicians and security officers.
8. Check the result in **Assets > Docker Inventory**: **Services** sub-page, then **Image
   versions**. Imports then run on their own every hour.

## Installation

Download the `glpi-docker-inventory-X.Y.Z.zip` archive of the
[latest release](https://github.com/parime/glpi-docker-inventory/releases/latest) (its SHA-256
checksum is published next to it), then unzip it into the GLPI `plugins` folder:

```sh
cd /var/www/glpi/plugins
unzip glpi-docker-inventory-X.Y.Z.zip   # crée le dossier dockerinventory/
```

Then **Setup > Plugins**, install and enable "Docker Inventory".

Where things are (since version 0.9.0):

| Screen | Path | Right |
| --- | --- | --- |
| Viewing | **Assets > Docker Inventory**: **Services**, **Image versions**, **Harbor images and vulnerabilities**, **Imported scans**, **Image end of support**, **Exposure**, **Risky configuration** sub-pages | Read (profile, "Docker Inventory" tab) |
| On-click readings | **Assets > Docker Inventory**: "Sources" block of **Services**, "Harbor reading", "Update now" (end of support), KEV and EPSS catalogues (**Imported scans**), "Read Traefik now" (**Exposure**) | GLPI setup, update |
| Settings | **Setup > Plugins > Docker Inventory**: **Docker connections**, **Managed registries**, **End-of-support mappings**, **Known exploitation** tabs | GLPI setup, update |
| Rights | **Administration > Profiles**, **Docker Inventory** tab | GLPI profiles |

The former addresses (*Setup > Docker connections*, *Setup > Image end of support*, up to version
0.8.0) redirect to the new ones: bookmarks remain valid.

## Minimal rights in the sources

The plugin only sends read requests (`GET`). Always use an account dedicated to GLPI:

| Source | Recommended account |
| --- | --- |
| Portainer BE | **Read-only user** role on each environment to inventory (*Environments > Manage access*), then an access token (*My account > Access tokens*) |
| Portainer CE | No read-only role: see below |
| What's Up Docker | User and password only if WUD requires authentication |
| Harbor | Read-only **robot account**: read projects, repositories and artifacts, *pull* for the tag list; no write, delete or scan right |
| Kubernetes | Service account limited to `get`, `list`, `watch` ([manifest](#kubernetes-connection)) |

Keys, passwords and tokens are encrypted with the GLPI key and never displayed again.

**Portainer CE.** A standard user must first be given access to the environments (*Environments >
Manage access*). Even then, it only sees resources whose access control is "Public" or that are
assigned to it (or its team): stacks, services and containers deployed by an administrator, or
outside Portainer, stay invisible, and the API answers with empty lists and no error. For a
complete inventory under CE: make the resources "Public" or assign them to the dedicated account's
team, or use the token of a **dedicated administrator account** for GLPI (keep it secret and revoke
it at the slightest doubt).

The plugin detects an account that sees nothing: "Test the connection" and the import then show a
**warning**, not a success, when the account sees no environment, or when Portainer reports
services or containers that the account does not see. Nothing is then retired from the inventory:
a loss of rights never empties the inventory.

## Portainer connection

1. **Setup > Plugins > Docker Inventory**, **Docker connections** tab: "Add a connection", choose
   **Portainer**.
2. Enter the address (`https://portainer.example.org:9443`) and the key, choose the
   **environment** (Production, Staging...) given to the applications the import will create,
   "Add".
3. "**Test the connection**". The import then runs every hour (automatic action
   `portainerimport`); "**Read now**" in **Assets > Docker Inventory > Services**, "Sources" block starts it at once.

One connection can mix Swarm environments and standalone Docker hosts (Compose stacks and
containers outside Compose). What the plugin writes outside its own tables: only the creation,
once, of the **Appliance** of a stack when no appliance of that name exists in the connection's
entity. An existing appliance, a computer or an environment are never modified or created.
Environment variable values and secret contents are never read.

## What's Up Docker connection

[What's Up Docker](https://github.com/getwud/wud) (WUD) knows the latest published version of
each image. The plugin reads this result without changing anything in WUD.

1. Import Portainer first: containers seen by WUD are linked to the imported services.
2. **Docker connections** tab: "Add a connection", choose **What's Up Docker**, enter the address
   (`https://wud.example.org:3000`) and, if needed, the user and password.
3. "Test the connection" (WUD version, number of watched containers). Read every hour
   (`wudimport`), or at once with "**Read now**" in **Assets > Docker Inventory > Services**, "Sources" block.

> The mapping to the WUD API follows the public WUD 8.x documentation and a fake WUD
> (`tests/mock-wud`); it remains to be validated on a real instance
> ([#2](https://github.com/parime/glpi-docker-inventory/issues/2)).

**Without WUD**, the plugin reads the tag list itself from the image's registry, read only:
**Docker Hub** without an account (at most one read per image per day, stopped as soon as a rate
limit is hit; only the names of the public images are sent) and **Harbor** through a Harbor
connection. An unknown registry, or a private one without credentials, gives a "not checked"
version, without error. To never query Docker Hub, disable the `registrycheck` automatic action.

## Traefik connection (applied routes, read on click)

The [Traefik](https://doc.traefik.io/traefik/operations/api/) API gives the configuration
**actually applied**: HTTP and TCP routes, entry points, services and middlewares, including routes
defined outside Docker labels (files, Kubernetes, Consul). The plugin reads it **read-only** and
compares it with the label routes collected at import (#72).

1. Enable the Traefik API **without** `api.insecure`: publish it through a protected router
   (`basicAuth` or `forwardAuth` middleware), for example `https://traefik.example.org`.
2. **Docker connections** tab: "Add a connection", choose **Traefik**, enter the address (without
   `/api`), then the `basicAuth` user and password. Without a user, the secret is sent as a token
   (`Authorization: Bearer`). The secret is encrypted and never displayed again.
3. "Test the connection" (Traefik version, number of HTTP routes). There is no automatic action:
   "**Read Traefik now**" is in **Assets > Docker Inventory > Exposure**, which also shows the routes
   read (or "Read now" in the "Sources" block of the services).

Only GET requests are sent, only under the connection address (`/api/version`, `/api/entrypoints`,
`/api/http/routers`, `/api/http/services`, `/api/http/middlewares`, `/api/tcp/routers`); redirects
are not followed and the addresses of service servers are never called. At most 100 routes are
displayed.

> The mapping follows the public Traefik 3.x documentation and a fake Traefik
> (`tests/mock-traefik`); it remains to be validated on a real instance.

## Harbor connection (versions and vulnerabilities)

1. **Docker connections** tab: "Add a connection", choose **Harbor**, enter the address
   (`https://harbor.example.org`), the read-only robot account and, if different, the host as
   written in the images (`registry.example.org:5000`). The connection covers the services of its
   entity and sub-entities.
2. "Test the connection" (number of visible projects). Read every hour (`registrycheck`,
   `harborimport`), or at once: "**Read now**" in the "Sources" block of the services (versions) and
   in **Assets > Docker Inventory > Harbor images and vulnerabilities**, "Harbor reading" block
   (projects, repositories, artifacts and vulnerability scan overview).

The plugin never starts a scan and changes nothing in Harbor. Pages of 100 (at most 20 pages per
list); an artifact, repository or project missing from a fully read list is "retired", never
deleted, and a list in error retires nothing. An error on one repository does not prevent reading
the others; a rate limit (429) stops the calls until the next read.

> Written from the public Harbor 2.x API documentation and a fake Harbor (`tests/mock-harbor`);
> to be validated on a real Harbor 2.x.

## Kubernetes connection

For applications running on a Kubernetes cluster (without Portainer); one connection per cluster.

1. **In the cluster**, create the service account and its read-only ClusterRole:

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

   Then get the token, the CA certificate and the API address, and check that the account cannot
   change anything (the last command must answer `no`):

```sh
kubectl apply -f glpi-inventaire-lecture.yaml
kubectl -n glpi-inventaire get secret glpi-lecture-jeton -o jsonpath='{.data.token}' | base64 -d
kubectl -n glpi-inventaire get secret glpi-lecture-jeton -o jsonpath='{.data.ca\.crt}' | base64 -d
kubectl config view --minify -o jsonpath='{.clusters[0].cluster.server}'
kubectl auth can-i create deployments -A --as=system:serviceaccount:glpi-inventaire:glpi-lecture
```

2. **Docker connections** tab: "Add a connection", choose **Kubernetes**, enter the API address
   (`https://k8s.example.org:6443`, HTTPS required), the token and, if the cluster has its own
   authority, the content of `ca.crt`. The `kube-system`, `kube-public` and `kube-node-lease`
   namespaces are ignored by default (editable list, `*` accepted: `cattle-*`).
3. "**Test the connection**" shows the cluster version and the number of visible namespaces, or a
   warning naming the resources that cannot be read.
4. Import every hour (`kubernetesimport`), or at once with "**Read now**" in **Assets > Docker Inventory > Services**, "Sources" block. Each namespace with workloads becomes an
   Appliance, each container of a Deployment, StatefulSet, DaemonSet or CronJob a Service record;
   nodes are linked to computers by name or IP address.

Known limits: init containers, standalone Jobs and pods without a controller are not inventoried;
no "watch" is opened, each import is a one-off read.

## Managed registries

**Setup > Plugins > Docker Inventory**, **Managed registries** tab: list of the hosts images must
come from (`registry.example.org:5000`, `docker.io` for Docker Hub). Empty list: the hosts of the
Harbor connections. A deployed image coming from elsewhere is flagged **"Outside managed
registry"** to technicians and security officers.

## Image end of support

**Disabled by default: no outgoing call until the source is enabled.** **End-of-support mappings**
tab of the setup page:

1. Tick "**Read lifecycles (end of support)**", choose the "end of support soon" threshold (90 days
   by default), "Save".
2. Wait for the daily `lifecyclecheck` automatic action, or "**Update now**" in **Assets > Docker
   Inventory > Image end of support**. Only the product
   name (`postgresql`, `nodejs`...) is sent to [endoflife.date](https://endoflife.date), at most
   once a day per product.
3. Check the **image → product mapping**: 33 common official images are provided, editable. Images
   in use without a mapping are listed with "**Add a mapping**". The product is never guessed:
   without a mapping, "Support unknown".

## Known exploitation (CISA KEV, EPSS)

**Nothing is downloaded at installation.** **Known exploitation** tab of the setup page:

1. Choose whether to "**Also download the EPSS scores (optional)**" and the "high EPSS" threshold
   (10% by default), "Save".
2. "**Download now**", in **Assets > Docker Inventory > Imported scans**, "KEV and EPSS catalogues"
   block: the plugin reads the complete public files, from a fixed address, without
   sending anything (no CVE, image or host). CISA KEV catalog (`known_exploited_vulnerabilities.json`,
   about 1 MB) and, if selected, the day's EPSS scores (`epss_scores-current.csv.gz`, a few MB). The
   files are kept in `files/_plugins/dockerinventory/exploits`; scans imported later are enriched
   without a new download.
3. Daily update: enable the `exploitsignals` automatic action (disabled by default).
4. **Offline mode**: download the same file on another computer, then upload it in "Offline mode"
   (KEV JSON, EPSS CSV compressed or not, 64 MB at most).

The **Cached data** table gives, for each catalog, the date, the origin (download or uploaded file),
the number of CVEs and the last error; a source failure keeps the data already cached.

## Update, end-of-support and vulnerability tickets

**Disabled by default.** They are set on the **Portainer** or **Kubernetes** connection of the
services, "Update tickets", "End-of-support tickets" and "Vulnerability tickets" sections:

| Ticket | Setting | Trigger |
| --- | --- | --- |
| Update | "Create update tickets" and the threshold (major only, minor or major, any gap) | Reading WUD or the registries |
| End of support | "Create end-of-support tickets" and the delay (90 days by default) | Reading the lifecycles |
| Vulnerability | "Create vulnerability tickets" and the threshold (critical, or critical and high) | Reading Harbor |

Category, **group in charge of the ticket** and **ticket template** are shared by all three. A
single ticket per service, followups when the situation changes, never a duplicate nor a change of
status, priority or assignment. Details, from the technician's side, are in the
[technician guide](technician.md#tickets).

## Rights of GLPI users

- **Administration > Profiles**, **Docker Inventory** tab: **Read** right on container services.
  It gives access to **Assets > Docker Inventory** and its sub-pages, to the tabs of
  appliances and computers, and to the evidence read by GRC Manager.
- Same tab, **Configuration exceptions** row: **Record an exception** (accept a configuration
  finding with a reason and an end date) and **Delete an exception**. Granted at installation to the
  profiles that can update the GLPI setup; give them to the security officer.
- Connections, managed registries and end-of-support settings require the GLPI **Setup** right,
  update.
- Everything is filtered on the user's active entities.

## Automatic actions

**Setup > Automatic actions**:

| Action | Frequency | Role |
| --- | --- | --- |
| `portainerimport` | every hour | Portainer import |
| `kubernetesimport` | every hour | Kubernetes import |
| `wudimport` | every hour | WUD read |
| `registrycheck` | every hour | Versions from Docker Hub and Harbor (without WUD) |
| `harborimport` | every hour | Harbor projects, artifacts and vulnerabilities |
| `lifecyclecheck` | once a day | End of support (if enabled) |
| `exploitsignals` | once a day | CISA KEV catalog and EPSS scores (disabled by default) |
| `hardeningexpiry` | once a day | Reminder of configuration exceptions expiring within 30 days (notification) |

## Private networks under GLPI 12, mirrors

GLPI 12 refuses calls to a private address by default. To allow them for these sources only, add
the relevant contexts to `config/local_define.php`. GLPI reads this file from the `config/` folder
of its installation (for instance `/var/www/glpi/config/local_define.php`), for web pages as well as
the command line and automatic actions, and not from the folder set by `GLPI_CONFIG_DIR` (unless
the distribution package provides for it in `inc/downstream.php`). If in doubt, put the file in the
`config/` folder of GLPI, even if the configuration is stored elsewhere (`/etc/glpi`). GLPI automated
tests (`TU_USER` constant) do not read it.

```php
define('GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS', [
    \GlpiPlugin\Dockerinventory\Portainer\PortainerContext::class,
    \GlpiPlugin\Dockerinventory\Wud\WudContext::class,
    \GlpiPlugin\Dockerinventory\Registry\RegistryContext::class,
    \GlpiPlugin\Dockerinventory\Kubernetes\KubernetesContext::class,
    \GlpiPlugin\Dockerinventory\Lifecycle\LifecycleContext::class,
    \GlpiPlugin\Dockerinventory\ExploitSignal::class,
]);
```

Mirrors: `PLUGIN_DOCKERINVENTORY_DOCKER_HUB_URL` (Docker Hub) and
`PLUGIN_DOCKERINVENTORY_LIFECYCLE_URL` (endoflife.date, template with `{product}`),
`PLUGIN_DOCKERINVENTORY_KEV_URL` and `PLUGIN_DOCKERINVENTORY_EPSS_URL` (CISA KEV catalog, EPSS
scores), in the same file.

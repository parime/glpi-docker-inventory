# Docker Inventory for GLPI

[🇫🇷 Français](README.md)

GLPI plugin that gives a **read-only** view of containerised applications:

- **Portainer**: Docker Swarm stacks and services, cluster nodes; standalone Docker hosts, their
  Compose stacks and their containers;
- **What's Up Docker (WUD)**: deployed version and latest available version of each image;
- **Harbor**: projects, repositories, artifacts and vulnerability scan overview of the images.

It answers "what is running, in which version, is it up to date, is it vulnerable?", which GLPI
cannot do on its own: its "Virtualization" tab lists isolated containers, with no stack, service,
replicas or version.

> **Status**: version 0.5.0 released: Portainer (Swarm and standalone Docker hosts with their
> Compose stacks), image versions from What's Up Docker or registries (Docker Hub, Harbor), update
> tickets above thresholds. Harbor (batch 3) follows. See [ROADMAP.md](ROADMAP.md).

## Principles

- **Read-only.** The plugin never redeploys, restarts or deletes anything. WUD automatic updates are
  not used.
- **Nothing is changed automatically in GLPI either.** A service that disappears is marked
  "retired", not deleted. Version gaps lead to a proposed ticket above chosen thresholds, never to a
  status, priority or assignment change.
- **Least-privilege accounts.** Dedicated read-only user in Portainer, WUD and Harbor; API keys
  encrypted with the GLPI key; Docker secret names imported, never their content.
- **Fictitious data** in tests, screenshots and documentation.

## What the plugin does

| Source | Import | In GLPI |
| --- | --- | --- |
| Portainer | Swarm and Compose stacks, Swarm services, Compose services and standalone containers (image, tag, digest, replicas or containers, state, restart policy, published ports, networks, named volumes, secret names), Swarm nodes and standalone hosts | Stack = native Appliance (impact analysis); Service form; nodes and hosts linked to inventoried computers |
| WUD | Watched containers: local tag and digest, latest version, kind of gap | Deployed version, latest version, gap (patch, minor, major, digest only, not trackable) per service; containers without a service listed separately |
| Registries (without WUD) | Docker Hub and Harbor v2 API | Same version table, computed by the plugin |
| Lifecycles (endoflife.date, disabled by default) | End-of-support dates of the image products (explicit mapping) | End of support, soon, maintained or unknown per service; optional end-of-support tickets |
| Harbor | Projects, repositories, artifacts, vulnerability scan overview | Vulnerabilities per image, images outside the managed registry flagged |

## Compatibility

- GLPI 11.0 and 12.x
- PHP 8.2 to 8.5 (GLPI 11), 8.3 to 8.5 (GLPI 12)
- Portainer CE or BE (API 2.x), WUD 8.x, Harbor 2.x

Portainer CE only has coarse roles: the read-only user sees more than needed there. The
installation guide will say so.

## Installation

Download the `glpi-docker-inventory-X.Y.Z.zip` archive from the [latest release](https://github.com/parime/glpi-docker-inventory/releases/latest) (its
SHA-256 checksum is published next to it), then unzip it into the GLPI `plugins` folder:

```sh
cd /var/www/glpi/plugins
unzip glpi-docker-inventory-X.Y.Z.zip   # creates the dockerinventory/ folder
```

Then **Setup > Plugins**, install and enable "Docker Inventory".

## Usage (batch 1: Portainer)

1. **In Portainer**, create a dedicated user with minimal rights (read-only on the environments to
   inventory) and an access token for it (*My account > Access tokens*).
2. **In GLPI**, *Setup > Docker connections* (or the plugin name in *Setup > Plugins*): "Add",
   choose **Portainer**, then enter the Portainer address (`https://portainer.exemple.test:9443`)
   and the key. Pick
   the environment (Production, Staging...) given to the appliances the import will create. "Test
   the connection", then "Import now".
3. The import then runs **every hour** (*Setup > Automatic actions*, `portainerimport`).

What you get:

- *Assets > Container services*: every service, with image, tag, digest, replicas, status;
- on each **Appliance** created for a stack, the "Services" tab;
- on each **computer** whose name matches a Swarm node or a standalone Docker host, the "Node
  services" tab.

**Standalone Docker hosts and Compose stacks** ([#25](https://github.com/parime/glpi-docker-inventory/issues/25)):
one connection can mix Swarm and non-Swarm Docker environments. For the latter, the import reads
the host (name, Docker version), its containers (stopped ones included) and its images, read-only:

- each Portainer Compose stack, and each Compose project deployed outside Portainer (container
  label `com.docker.compose.project`), becomes an Appliance, like a Swarm stack;
- each Compose service (one or more containers) and each container outside Compose becomes a
  service, of kind "Compose service" or "Standalone container": image, tag, digest, container
  state, restart policy, published ports, networks, volumes and secrets by name only. Environment
  variable values and secret contents are never read nor stored;
- the host is linked to the computer with the same name, like a Swarm node;
- a vanished container or project is marked "retired", never deleted.

What the plugin writes **outside its own tables**: only the one-time creation of a stack's
Appliance when no appliance with that name exists in the connection's entity. An existing
appliance, a computer or an environment is never modified or created. The read right on services
is granted in *Administration > Profiles*, "Docker Inventory" tab; connections require the *Setup*
update right.

## Usage (batch 2: What's Up Docker)

[What's Up Docker](https://github.com/getwud/wud) (WUD) watches container images and knows the
latest published version of each one. The plugin reads that result **without changing anything in
WUD** (no trigger, no update) and links it to the services imported from Portainer.

1. **Import Portainer first**: containers seen by WUD are linked to the imported services (by
   container ID or name for standalone hosts, then by image digest, then by Swarm service name,
   then by image and tag).
2. **In GLPI**, *Setup > Docker connections*: "Add", choose **What's Up Docker**, enter the WUD
   address (`https://wud.exemple.test:3000`) and, if WUD requires authentication, the user and
   password (encrypted with the GLPI key, never shown again). "Test the connection" shows the WUD
   version and the number of watched containers, then "Read WUD now".
3. The read then runs **every hour** (*Setup > Automatic actions*, `wudimport`).

What you get:

- *Assets > Image versions*: for each service, deployed version, latest version and gap (coloured
  badges: major, minor, patch, digest only, not trackable, up to date), filters by gap and by
  appliance environment, containers without a service listed separately; "Advanced search and
  export" opens the GLPI search on the same columns;
- on each **service** and each **appliance**, the "Versions" tab.

Reading rules:

- the gap computed by WUD is taken as is; the plugin only computes it when WUD does not give it;
- a moving tag (`latest`, `stable`...) or one without a version number is "not trackable": only the
  digest tells whether the image changed;
- several containers of one service give a single row; replicas in different versions (update in
  progress or stuck) are flagged;
- a container without a matching service (host missing from Portainer, or several possible services) is not
  ignored: it is listed separately with the reason.

> The mapping of the WUD API (`/api/containers`, `/api/app`) follows the public WUD 8.x
> documentation and a fake WUD (`tests/mock-wud`); it still has to be validated against a real WUD
> 8.x instance ([#2](https://github.com/parime/glpi-docker-inventory/issues/2)).

The read right on services (*Administration > Profiles*, "Docker Inventory" tab) also gives access
to the versions.

## Usage (batch 2: registries, without WUD)

For services that **no WUD connection covers**, the plugin itself reads the tag list of their image
in the registry, **read-only** (GET requests on the tag list), and fills the same version table
(source "Image registry").

- **Docker Hub** (images `postgres`, `grafana/grafana`, `docker.io/...`): anonymous, at most one
  read per image per day (cache), and no more calls during the check as soon as Docker Hub refuses
  (rate limit) or does not answer. Only the names of the public images in use are sent to Docker
  Hub.
- **Harbor** (registry API v2, `/v2/<project>/<repository>/tags/list`): *Setup > Docker
  connections*, "Add", choose **Harbor**, enter the address (`https://harbor.exemple.test`), a
  **read-only robot account** (*pull* right on the useful projects; password encrypted with the
  GLPI key, never shown again) and, if it differs from the address, the host as written in the
  images (`registry.exemple.test:5000`). The connection covers the services of its entity and its
  sub-entities; "Test the connection", then "Check the registries now".
- **Unknown registry** (neither Docker Hub nor a declared Harbor) or **private without
  credentials**: version "not checked", with the reason, no blocking error.

Same comparison as with WUD: SemVer versions (`1.2`, `v1.2.3`, `2024.10.1`), same number of
components as the deployed tag (`16` follows `17`, not `16.4`), same variant (`-alpine`),
pre-releases (`-rc1`, `-beta2`) ignored unless a pre-release is deployed, `latest` "not trackable".

The check runs **every hour** (*Setup > Automatic actions*, `registrycheck`); "Check the registries
now" is available on the *Assets > Image versions* page and on each Harbor connection. For a Docker
Hub mirror, define `PLUGIN_DOCKERINVENTORY_DOCKER_HUB_URL` in `config/local_define.php`. To never
query Docker Hub, disable the `registrycheck` automatic action.

> The Harbor call follows the registry API v2 specification (basic authentication of the robot
> account) and a fake registry (`tests/mock-registry`); it still has to be validated against a
> real Harbor 2.x.

## Usage (batch 2: update tickets)

**Disabled by default.** On the **Portainer** connection of the services, "Update tickets"
section: "Create update tickets", the **threshold** (major version only, minor or major, any gap
including patches), the **category**, the **group in charge of the ticket** and a **ticket
template** (its predefined fields: type, urgency, requester... are used as a starting point).

After each read of WUD or of the registries (automatic actions and buttons), for each service above
the threshold: a ticket "Update the service X of the stack Y: 1.2 → 1.4", linked to the appliance
of the stack, with the image, the versions, the gap and a link to the service.

- **One ticket per service**: while it is open, a **follow-up** is added when the situation changes
  (new version, other deployed version, gap resolved), never a second ticket.
- **Nothing is changed automatically** on an existing ticket: neither status (no reopening, no
  closing when the gap is resolved), nor priority, nor assignment (the group is only set at
  creation).
- Solved or closed ticket: a new ticket only for a new latest version.
- Below the threshold, pre-release, digest only, untrackable tag or version not checked: no ticket.
- The link to the ticket appears in the "Versions" tab of the service; the report (tickets created,
  follow-ups added) in the read message and the automatic actions log.
- Critical vulnerability of the image: trigger planned with Harbor in batch 3.

**Portainer, WUD or Harbor on a private network (GLPI 12).** By default GLPI 12 refuses calls to a
private address. To allow them for these sources only, add to `config/local_define.php`:

```php
define('GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS', [
    \GlpiPlugin\Dockerinventory\Portainer\PortainerContext::class,
    \GlpiPlugin\Dockerinventory\Wud\WudContext::class,
    \GlpiPlugin\Dockerinventory\Registry\RegistryContext::class,
]);
```

## Usage: image end of support

An image can be "up to date" within its branch while that branch is no longer maintained (for
example a database on 13.x when branch 13 no longer receives fixes). The plugin compares the
**deployed branch** with the end-of-support dates published by an open source,
[endoflife.date](https://endoflife.date) (API v1, JSON over HTTPS).

**Disabled by default: no outgoing call until the source is enabled.** Settings in
*Setup > Image end of support* (configuration administrators):

1. Tick "Read lifecycles (end of support)" and choose the "end of support soon" threshold (90 days
   by default), then "Save".
2. "Update now", or wait for the `lifecyclecheck` automatic action (once a day). Only the **product
   name** is sent (`postgresql`, `nodejs`...), never a service, private image or host name; each
   product is read **at most once a day** (cache).
3. Check the **image → product mapping**: a table of 33 common official images is provided
   (`postgres` → `postgresql`, `node` → `nodejs`, `httpd` → `apache-http-server`...), editable and
   extendable. **Images in use without mapping** are listed with an "Add a mapping" button. The
   product is **never guessed**: an unmapped image stays "Support unknown", never "Maintained".

Display (*Image versions* page and "Versions" tab): **End of support** (red), **End of support soon**
(orange, "in N days"), **Maintained** (green) or **Support unknown** (grey, with the reason: no
mapping, `latest` tag, product unknown to the source, missing branch, source not read yet) badges,
with the product, branch and date. On the versions page, clickable counters and an "End of support"
filter (including "To watch": end of support passed or near).

- Branch of a tag: the most precise one starting the version (`1.24` for `1.24.0-alpine`, `13` for
  `13.4`); a less precise tag (`7`) follows the newest branch (`7.4`); a codename (`bookworm`,
  `bookworm-slim`) gives the branch with that name.
- **Offline**: if the source does not answer (outage, 429 limit), cached lifecycles are still used
  and the date of their last update is shown; without cache, "not checked". Nothing blocks and no
  GLPI object is changed.
- **End-of-support tickets**, disabled by default, on the Portainer connection of the services:
  "Create end-of-support tickets" and the **notice** (ticket when the end of support is less than N
  days away, or passed; 90 by default). Category, group and template are those of the update
  tickets. Same principle: one ticket per service, follow-ups when the branch changes, never a
  duplicate nor a status change; ticket closed for a branch: no new ticket for the same branch.
- Internal mirror of the source or tests: `PLUGIN_DOCKERINVENTORY_LIFECYCLE_URL` constant (template
  with `{product}`) in `config/local_define.php`, and the
  `\GlpiPlugin\Dockerinventory\Lifecycle\LifecycleContext` context for a private address on GLPI 12.

## Links with the other plugins

- **GRC Manager** ([glpi-iso27001-management](https://github.com/parime/glpi-iso27001-management)):
  evidence for controls A.5.9 (inventory), A.8.8 (technical vulnerabilities), A.8.9 (configuration)
  and A.8.32 (changes); Harbor image vulnerabilities in the vulnerability correlation.
- **Configuration GLPI Auto** ([Configuration-glpi-auto](https://github.com/parime/Configuration-glpi-auto)):
  wizard step to declare the Portainer, WUD and Harbor connections.

## Documentation

- [ARCHITECTURE.md](ARCHITECTURE.md): tree, classes, tests
- [ROADMAP.md](ROADMAP.md): lots and issues
- [CHANGELOG.md](CHANGELOG.md)

## Reuse and citation

This project is licensed under **GPL-3.0-or-later**. If you reuse code, even partially and without
forking the repository: keep the copyright headers and the [NOTICE](NOTICE) file, keep the same
licence with the source code, state your changes and credit the original project
(https://github.com/parime/glpi-docker-inventory). To cite the project, see [CITATION.cff](CITATION.cff) (GitHub "Cite this repository"
button). Coding assistants will find these rules in [AGENTS.md](AGENTS.md).

## License

GPL-3.0-or-later, see [LICENSE](LICENSE).

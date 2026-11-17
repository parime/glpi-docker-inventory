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

> **Status**: version 0.9.0 released: Portainer (Swarm and standalone Docker hosts), Kubernetes, image
> versions from What's Up Docker or the registries, Harbor (artifacts and vulnerabilities), image
> end of support, tickets above thresholds, evidence for GRC Manager. See [ROADMAP.md](ROADMAP.md).

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
| Kubernetes | Namespaces, Deployments, StatefulSets, DaemonSets and CronJobs (image, tag, digest, ready and desired replicas, state), nodes | Namespace = native Appliance; Service record per container; nodes linked to computers (name or IP address); same versions and Harbor vulnerabilities |

## Compatibility

- GLPI 11.0 and 12.x
- PHP 8.2 to 8.5 (GLPI 11), 8.3 to 8.5 (GLPI 12)
- Portainer CE or BE (API 2.x), WUD 8.x, Harbor 2.x, Kubernetes 1.24 and later (`apps/v1`, `batch/v1` APIs)

The account to use in each source (Portainer BE or CE, WUD, Harbor, Kubernetes) is described in the
[administrator guide](docs/en/administrator.md#minimal-rights-in-the-sources).

## Installation

Download the `glpi-docker-inventory-X.Y.Z.zip` archive from the [latest release](https://github.com/parime/glpi-docker-inventory/releases/latest) (its
SHA-256 checksum is published next to it), then unzip it into the GLPI `plugins` folder:

```sh
cd /var/www/glpi/plugins
unzip glpi-docker-inventory-X.Y.Z.zip   # creates the dockerinventory/ folder
```

Then **Setup > Plugins**, install and enable "Docker Inventory".

**Where to find the plugin** (a single menu entry, to keep GLPI light):

| Screen | Path | Right |
| --- | --- | --- |
| Viewing | **Assets > Docker Inventory**: **Services**, **Image versions**, **Harbor images and vulnerabilities**, **Image end of support** sub-pages | **Read** (Administration > Profiles, "Docker Inventory" tab) |
| Settings | **Setup > Plugins > Docker Inventory**: **Docker connections** (Portainer, Kubernetes, WUD, Harbor), **Managed registries**, **Image end of support** tabs | GLPI setup, update |

The former addresses (*Setup > Docker connections*, *Setup > Image end of support*, up to version
0.8.0) redirect to the new ones: bookmarks keep working.

## Usage by role

Each role has its path below and its detailed guide in [`docs/en/`](docs/en/).

### GLPI administrator: first setup

Full guide: [docs/en/administrator.md](docs/en/administrator.md) (minimal rights, each connection,
Kubernetes manifest, tickets, automatic actions, private networks under GLPI 12).

1. **Setup > Plugins**: install and enable "Docker Inventory".
2. In Portainer (or Kubernetes), create a **dedicated read-only account** and its token.
3. **Setup > Plugins > Docker Inventory**, **Docker connections** tab: "Add a connection",
   **Portainer** (or **Kubernetes**), address, token, environment of the appliances.
4. On the connection: "**Test the connection**", then "**Import now**".
5. Optional: **What's Up Docker** and **Harbor** connections (versions, vulnerabilities),
   **Managed registries** and **Image end of support** tabs (disabled by default), automatic
   tickets on the Portainer or Kubernetes connection (disabled by default).
6. **Administration > Profiles**, **Docker Inventory** tab: **Read** right for technicians and
   security officers.

### Technician: follow services and updates

Full guide: [docs/en/technician.md](docs/en/technician.md) (meaning of the badges, version
comparison rules, tabs of appliances and computers, how tickets work).

1. **Assets > Docker Inventory**, **Services** sub-page: what is running, with image, tag, replicas
   and state.
2. **Image versions** sub-page: filter on "Major version" or "Minor version".
3. **Harbor images and vulnerabilities** sub-page: images with critical or high vulnerabilities.
4. **Image end of support** sub-page: branches that no longer receive fixes.
5. **Assistance > Tickets**: handle the update, end-of-support and vulnerability tickets created by
   the plugin (one per service, never changed automatically).

### Security officer or auditor: evidence and gaps

Full guide: [docs/en/security.md](docs/en/security.md) (evidence per control, guarantees of the read
API, what the auditor can check).

1. **GRC & Compliance > Compliance** (GRC Manager), controls **A.5.9**, **A.8.8**, **A.8.9** and
   **A.8.32**: "**Docker inventory evidence**" section, read live.
2. **GRC & Compliance > Technical security > Fleet vulnerabilities**: services whose image has
   critical or high vulnerabilities.
3. **Assets > Docker Inventory**, **Image end of support** sub-page: images whose branch is no
   longer maintained.
4. **Harbor images and vulnerabilities** sub-page, "**Only outside managed registry**" filter:
   images from a registry that is not allowed.

## Links with the other plugins

- **GRC Manager** ([glpi-iso27001-management](https://github.com/parime/glpi-iso27001-management)):
  evidence for controls A.5.9 (inventory), A.8.8 (technical vulnerabilities), A.8.9 (configuration)
  and A.8.32 (changes); Harbor image vulnerabilities in the vulnerability correlation
  ([#10](https://github.com/parime/glpi-docker-inventory/issues/10)). GRC reads this evidence through
  the **read API** `GlpiPlugin\Dockerinventory\Api\Evidence` (version 1, read only): each method
  only returns what the user sees in this plugin (read right on the services, active entities), and
  nothing without that right. The import dates of the connections are only given to people who can
  see the connections ("Setup" right).
- **Version changes**: each Portainer import records, for each service, a move to another image, to
  another tag, or to another digest under the same tag (rebuilt image). This read-only log, never
  purged automatically, is evidence of change management (A.8.32). Each connection also keeps the
  **date of its last successful import** (successful, even partly; a failure or a warning does not
  change it).
- **Configuration GLPI Auto** ([Configuration-glpi-auto](https://github.com/parime/Configuration-glpi-auto)):
  wizard step to declare the Portainer, WUD and Harbor connections.

## Documentation

Rules shared by all parime plugins, to read before contributing: [common plugin charter](https://github.com/parime/glpi-plugin-template/blob/main/CHARTE.md) (in French).

- [Administrator guide](docs/en/administrator.md): installation, connections, rights, settings
- [Technician guide](docs/en/technician.md): services, versions, vulnerabilities, tickets
- [Security officer and auditor guide](docs/en/security.md): GRC Manager evidence, end of support,
  images outside managed registries
- [ARCHITECTURE.md](ARCHITECTURE.md): tree, classes, tests
- [ROADMAP.md](ROADMAP.md): lots and issues
- [CHANGELOG.md](CHANGELOG.md)

## Translations

[![Translation status](https://hosted.weblate.org/widget/glpi-docker-inventory/svg-badge.svg)](https://hosted.weblate.org/engage/glpi-docker-inventory/)

The plugin is translated into French, English, German, Spanish, Italian, Polish and Portuguese
(Brazil). **Translation contributions are welcome on [Hosted Weblate](https://hosted.weblate.org/engage/glpi-docker-inventory/)**: fixing a term,
improving domain vocabulary or adding a language is done directly in the browser, without Git.
Weblate then proposes the translations through a Pull Request, reviewed before being merged.

## Reuse and citation

This project is licensed under **GPL-3.0-or-later**. If you reuse code, even partially and without
forking the repository: keep the copyright headers and the [NOTICE](NOTICE) file, keep the same
licence with the source code, state your changes and credit the original project
(https://github.com/parime/glpi-docker-inventory). To cite the project, see [CITATION.cff](CITATION.cff) (GitHub "Cite this repository"
button). Coding assistants will find these rules in [AGENTS.md](AGENTS.md).

## License

GPL-3.0-or-later, see [LICENSE](LICENSE).

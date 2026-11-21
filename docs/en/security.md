# Security officer and auditor guide

[🇫🇷 Français](../securite.md) · [Back to the README](../../README.en.md)

For the person who must prove that containerised applications are inventoried, up to date, not
vulnerable and built from controlled images: CISO, compliance officer, internal or external
auditor. The plugin **Read** right is enough (given by the administrator in **Administration >
Profiles**, **Docker Inventory** tab); nothing below changes anything.

- [Path in 5 steps](#path-in-5-steps)
- [Evidence in GRC Manager](#evidence-in-grc-manager)
- [End of support](#end-of-support)
- [Images outside managed registries](#images-outside-managed-registries)
- [Vulnerabilities](#vulnerabilities)
- [Version changes](#version-changes)
- [What the auditor can check](#what-the-auditor-can-check)

## Path in 5 steps

1. In **GRC & Compliance > Compliance** (the
   [GRC Manager](https://github.com/parime/glpi-iso27001-management) plugin), open control
   **A.8.8**: the "**Docker inventory evidence**" section gives version gaps and vulnerabilities,
   read live.
2. Do the same for **A.5.9** (inventory), **A.8.9** (configuration and image provenance) and
   **A.8.32** (version changes of the last 90 days).
3. **Assets > Docker Inventory**, **Image end of support** sub-page: list the images whose branch
   is no longer maintained.
4. **Harbor images and vulnerabilities** sub-page, "**Only outside managed registry**" filter: list
   the images that come from no allowed registry.
5. Export if needed with "**Advanced search and export**" (**Image versions** sub-page), and follow
   the gaps through the plugin's tickets or through nonconformities in GRC Manager.

## Evidence in GRC Manager

With GRC Manager installed, the record of four ISO 27001 Annex A controls shows a "**Docker
inventory evidence**" section, read live through the plugin's read API
(`GlpiPlugin\Dockerinventory\Api\Evidence`, version 1):

| Control | Evidence |
| --- | --- |
| A.5.9 Inventory of information and other associated assets | Services, stacks, nodes; date of the last successful import of each connection (inventory freshness) |
| A.8.8 Management of technical vulnerabilities | Version gaps (major, minor, patch); Harbor vulnerabilities of the deployed images; imported Trivy, Grype or Docker Scout scans |
| A.8.9 Configuration management | Service configuration and image provenance (managed registry or not) |
| A.8.32 Change management | Version changes of the last 90 days (other image, other tag, image rebuilt under the same tag) |

Services whose image has critical or high vulnerabilities also appear in **GRC & Compliance >
Technical security > Fleet vulnerabilities**.

What the API guarantees:

- it only returns what the person sees in the plugin (**Read** right, active entities), and nothing
  without that right;
- the import dates of the connections are only given to people who can see the connections
  (**Setup** right);
- without the plugin, or without that right, the section is simply absent from GRC Manager.

## End of support

An image can be "up to date" in its branch while that branch no longer receives fixes (a database
in 13.x when branch 13 is no longer maintained). The **Image end of support** sub-page of
**Assets > Docker Inventory** compares the deployed branch with the dates published by
[endoflife.date](https://endoflife.date): **End of support**, **End of support soon**,
**Maintained** or **Support unknown**, with the product, branch and date.

- The source is **disabled by default**; only the product name (`postgresql`) is sent to it.
- The product is **never guessed**: an image without a mapping stays "Support unknown", never
  "Maintained". A high number of "Support unknown" is therefore a point of attention, not good
  news.
- Offline, the lifecycles already read are still used and the date of their last update is shown.

## Images outside managed registries

The administrator declares, in the **Managed registries** tab of the plugin setup, the hosts images
must come from (by default, the hosts of the Harbor connections). Any deployed image coming from
elsewhere is flagged "**Outside managed registry**":

- counter and filter of the **Harbor images and vulnerabilities** sub-page;
- alert on the service record;
- provenance in the A.8.9 evidence of GRC Manager.

## Vulnerabilities

Vulnerabilities come from the scan made by **Harbor** (the plugin never starts a scan). A service
is linked to a scan only by the **digest** of the running image, otherwise by its tag, with the
mention "digest different from Harbor's": this is what allows saying that the scan really covers
the deployed image. An image not scanned, or whose scan failed, is shown as such, never as "no
vulnerability".

### Images outside Harbor: imported scans

For images from another registry, **Assets > Docker Inventory**, **Imported scans** sub-page: upload
the report produced by your tool, in CI for example.

| Tool | Command that produces the report |
|------|----------------------------------|
| Trivy | `trivy image --format json --output report.json IMAGE` |
| Grype | `grype IMAGE --output json --file report.json` |
| Docker Scout | `docker scout cves --format sarif --output report.sarif IMAGE` |

Any SARIF 2.1.0 report is accepted too. Docker Scout's SARIF does not name the image: enter
"name:tag" in the **Scanned image** field. The file is read then discarded: 20 MB at most, bounded
JSON depth and number of vulnerabilities, no content executed. One scan per image, tag and digest: a
new upload replaces the previous one (history kept).

The scan is linked to services like Harbor's: by digest, otherwise by tag. The page gives the
vulnerabilities by severity, the fixable ones, the scan date (flagged after 30 days or when unknown),
the rate of covered services and the detail of the 500 most severe vulnerabilities. The service's
**Versions** tab shows the same card. **Imported image scans** profile right: import, delete
(granted to administrators).

## Version changes

Each Portainer import records, for each service, a move to another image, to another tag, or to
another digest under the same tag (rebuilt image). This log is read only and never purged
automatically: it is evidence of change management (A.8.32).

## Exposure and flow matrix

**Assets > Docker Inventory**, **Exposure** sub-page (and **Exposure** tab of each service):
published ports, Traefik routes, ingresses and exposed Kubernetes services, collected at each import,
with their reach (Internet (likely), host network, internal network, host only). The "**Exposed on
the Internet**" filter lists the published services.

With GRC Manager, each exposure is compared with the active flows of the **flow matrix**: an exposure
without a documented flow is flagged ("**No documented flow**" filter). The **Suggest the flow**
button opens the form of a new flow in GRC ("published on the Internet" or "internal", the service as
destination): the person reviews it, adds the justification and the owner, then saves. The plugin
never writes into GRC.

The reach is inferred from the host name or address: a public name is not necessarily reachable
(firewall, internal DNS). Check it with the network team.

### Routes actually applied by Traefik

With a **Traefik** connection (see the administrator guide), "Read Traefik now" lists the applied
routes and matches them with the labels: **matching**, **different** (other host names), **service
not inventoried**, **outside Docker labels** (file, Kubernetes...), **internal to Traefik**. Routes
declared in labels but missing from Traefik are listed separately. Findings, never fixed by the
plugin: Traefik dashboard or API without authentication (`api.insecure`), public route without TLS
or HTTPS redirect, administration route without authentication, public route without a security
headers middleware.

## Risky configuration (A.8.9)

**Assets > Docker Inventory**, **Risky configuration** sub-page (and tab of the same name on each
service): security settings collected at each import, with no request beyond the inventory ones.

| Severity | Findings |
| --- | --- |
| Critical | Privileged mode; container engine socket mounted (`docker.sock`, `containerd.sock`…) |
| High | Host network or processes shared; dangerous capabilities (`SYS_ADMIN`, `NET_ADMIN`…); sensitive host directory mounted (`/etc`, `/`, `/var/lib/docker`…) |
| Medium | Host shared memory (IPC); other added capabilities; running as root (no user set) |
| Low | No memory limit; no CPU limit; writable file system |

Swarm has neither privileged mode nor host process sharing: these findings only concern standalone
hosts and Kubernetes. A container with no user set is counted as root: the image may set one, check
it.

For an intended finding (a monitoring agent that reads the socket, for instance), **Accept the risk**
records an exception: the reason and an end date (366 days at most). After that date the finding
counts again; **Renew the exception** extends it after review. **Configuration exceptions** right
(record, delete) in **Administration > Profiles**, **Docker Inventory** tab. The plugin never
changes anything on the platforms.

The summary card gives the share of analysed services with no open finding and with no critical or
high finding (findings covered by a current exception do not count). GRC Manager can read it through
`Evidence::hardening()` as evidence for control A.8.9.

## What the auditor can check

- **Read only**: the plugin only sends read requests to the sources, with dedicated accounts with
  minimal rights (see the [administrator guide](administrator.md#minimal-rights-in-the-sources)).
- **Nothing is changed automatically** in GLPI: a service gone is "retired", not deleted; tickets
  never change status, priority or assignment on their own.
- **No secret imported**: secret names only, never their content nor environment variable values;
  API keys encrypted with the GLPI key.
- **A loss of rights never empties the inventory**: an import that no longer sees anything is
  reported by a warning and retires nothing.

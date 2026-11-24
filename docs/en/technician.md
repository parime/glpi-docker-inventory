# Technician guide

[🇫🇷 Français](../technicien.md) · [Back to the README](../../README.en.md)

For the person who follows containerised applications day to day: what is running, in which
version, whether an update is needed, whether the image is vulnerable, and the resulting tickets.
Everything is in **Assets > Docker Inventory** (plugin **Read** right, given by the administrator).
The plugin is read only: it never redeploys, restarts or updates anything itself.

- [Path in 5 steps](#path-in-5-steps)
- [Services](#services)
- [Image versions](#image-versions)
- [Harbor images and vulnerabilities](#harbor-images-and-vulnerabilities)
- [Image end of support](#image-end-of-support)
- [Tabs of appliances, computers and services](#tabs-of-appliances-computers-and-services)
- [Tickets](#tickets)

## Path in 5 steps

1. Open **Assets > Docker Inventory**: the **Services** sub-page lists all services with their
   image, tag, replicas and state. The "What is this page for?" box reminds what is expected.
2. **Image versions** tab: filter on the "Major version" or "Minor version" gap to see what is
   behind.
3. **Harbor images and vulnerabilities** tab: spot the images with critical or high
   vulnerabilities.
4. **Image end of support** tab: plan first the images whose branch is no longer maintained.
5. Handle the **tickets** created by the plugin (if the administrator enabled them) in
   **Assistance > Tickets**: each one points to the service concerned.

## Services

**Assets > Docker Inventory**, **Services** sub-page: one Swarm service, Compose service,
standalone container or Kubernetes container per row, with image, tag, digest, replicas (or
containers), state, appliance and source connection. A service gone from the source is
"**retired**", never deleted: its history stays available. A service record also shows its
published ports, networks, named volumes and the names of its secrets (never their content).

## Image versions

**Assets > Docker Inventory**, **Image versions** sub-page: for each service, deployed version,
latest version and **gap**, with a coloured badge:

| Badge | Meaning |
| --- | --- |
| Major version | a newer major version exists |
| Minor version | a newer minor version exists |
| Patch | only a newer patch exists |
| Digest only | same tag, but the published image changed |
| Not trackable | moving tag (`latest`, `stable`) or no version number: only the digest tells whether the image changed |
| Up to date | nothing newer |

- Filters by gap, by appliance environment and by end of support; clickable counters.
- "**Advanced search and export**" opens the GLPI search on the same columns (CSV, PDF export...).
- "**Check the registries now**" reads the registries again (Docker Hub, Harbor) for the services
  WUD does not cover.
- Containers watched by WUD without a matching service are listed separately, with the reason.
- Replicas in different versions (update in progress or stuck) are flagged.
- Version comparison: same number of components as the deployed tag (`16` follows `17`, not
  `16.4`), same variant (`-alpine`), pre-releases (`-rc1`) ignored unless a pre-release is
  deployed.

## Harbor images and vulnerabilities

**Assets > Docker Inventory**, **Harbor images and vulnerabilities** sub-page: Harbor projects,
repositories and artifacts with their scan overview (critical, high, medium, low, fixable). A
service is linked to its artifact by the **digest** of the running image, the only proof that the
scan covers the deployed image, otherwise by the **tag**. "Digest different from Harbor's" flags a
rebuilt image, or a multi-platform index of which Harbor only received one platform. A scan
"running" or "pending" is shown as such.

The "**Only outside managed registry**" counter and filter show images that come from no registry
allowed by the administrator.

## Image end of support

**Assets > Docker Inventory**, **Image end of support** sub-page (if the administrator enabled the
source): services whose deployed branch is no longer, or soon no longer, maintained, those to watch
first.

| Badge | Meaning |
| --- | --- |
| End of support (red) | the branch no longer receives fixes |
| End of support soon (orange) | end of support in N days |
| Maintained (green) | maintained branch |
| Support unknown (grey) | no image → product mapping, `latest` tag, unknown product or branch, source not read yet (the reason is shown) |

An image without a mapping is never "Maintained" by default: ask the administrator to add the
mapping. Branch of a tag: the most precise one that starts the version (`1.24` for
`1.24.0-alpine`, `13` for `13.4`); a less precise tag (`7`) follows the most recent branch
(`7.4`); a code name (`bookworm-slim`) gives the branch of that name.

## Tabs of appliances, computers and services

| Object | Tab | Content |
| --- | --- | --- |
| Appliance (stack or namespace) | **Services**, **Versions** | services of the stack, their versions |
| Computer (Swarm node, Docker host, Kubernetes node) | **Node services** | services running on the machine |
| Service | **Versions** | versions, end of support, "Image vulnerabilities (Harbor)" card, links to tickets |
| Service | **Volumes and backup** | named or persistent volumes, declared backup ("backed up by…", last check), volumes without a declared backup |
| Service | **Risky configuration** | security findings (privileged, root, Docker socket, host network, limits…), severity, advised fix, current exceptions |

## Tickets

If the administrator enabled them, the plugin creates three kinds of tickets, linked to the
appliance of the stack:

- **update**: "Update service X of stack Y: 1.2 → 1.4", with the image, versions, gap and a link to
  the service;
- **end of support**: the deployed branch reaches its end of support;
- **vulnerability**: the running image has vulnerabilities above the threshold.

Shared rules:

- **one ticket per service**: while it is open, a **followup** is added when the situation changes
  (new version, other image, gap resolved), never a second ticket;
- **nothing is changed automatically** on an existing ticket: no status change (no reopening, no
  closing when the gap is resolved), no priority, no assignment. It is up to you to solve the
  ticket once the update is done;
- ticket solved or closed: a new ticket only for a new latest version, another branch or another
  image;
- the link to the ticket appears in the **Versions** tab of the service.

---
status: accepted
date: 2026-09-30
---

# Run PostgreSQL with the CloudNativePG operator

## Context

Apps that needed a database got it from their own chart's bundled subchart:
Nextcloud shipped Bitnami's `postgresql` and pomopensource Bitnami's `mysql`
alongside themselves. Each app therefore carried a differently configured
database, versioned and upgraded by whichever app chart happened to embed it.

Bitnami has also stopped publishing its free images under `bitnami/`. The
subcharts only keep working by pointing at the frozen `bitnamilegacy/` repo,
which gets no further database releases or security fixes.

## Decision

Run every app database as PostgreSQL through the
[CloudNativePG](https://cloudnative-pg.io) operator, installed cluster-wide by
Argo CD at sync-wave `-1`. An app that needs a database declares a `Cluster`
next to its other manifests in `kubernetes/manifests/workloads/<app>/`, disables
its chart's bundled database, and reads the connection from the `<cluster>-app`
secret the operator generates.

Clusters run a single instance: on one node a replica shares the primary's disk
and adds nothing but memory use.

## Consequences

- Every database is declared the same way, and images come from CloudNativePG's
  maintained ones. The operator is one more piece to upgrade, though a
  PostgreSQL major upgrade no longer depends on each app chart.
- Database credentials are generated in-cluster rather than sealed into the
  repo, so no plaintext has to survive a cluster loss for them (see ADR 0001).
  In exchange the database *data* does not survive it either: there is no
  backup yet. CloudNativePG's `backup` section, pointed at object storage, is
  the way out and a separate decision.
- Moving an existing app over is a manual, one-off step: a dump and restore
  from PostgreSQL, or a row copy from MySQL, which only works because
  pomopensource is a Laravel app whose schema is database-agnostic. See
  [docs/cloudnative-pg.md](../cloudnative-pg.md).
- An app that only supports MySQL has no home here and would need a new decision.

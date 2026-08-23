---
status: accepted
date: 2026-08-23
---

# Manage secrets with Sealed Secrets

## Context

Everything in this repo is reconciled by Argo CD from git. Secrets were the
exception: a plain `Secret` cannot be committed, so it had to be applied by hand,
leaving cluster state that git does not describe.

This is a single-node homelab run by one person, and it should stay rebuildable
from a bare VPS. So the choice is constrained less by features than by what has
to be running, and maintained, for a sync to succeed.

On that basis the alternatives all cost more than they return: Vault is a service
to operate, External Secrets with a hosted store makes reconciliation depend on a
third party being reachable, and SOPS + age needs a decryption plugin inside
Argo CD itself.

## Decision

Use [Sealed Secrets](https://github.com/bitnami-labs/sealed-secrets). Encrypted
manifests are committed next to the workloads that use them, and the in-cluster
controller is the only thing that can decrypt them. Argo CD stays stock, and the
only thing added is a controller in the cluster, deployed by the same GitOps loop
as everything else and reachable without leaving it.

The controller's public certificate is committed at `sealed-secrets/pub-cert.pem`,
so sealing a secret needs the repo but not cluster access.

We do not back up the controller's private key. Every secret we seal is issued by
a provider and can be re-issued, so losing the cluster costs a re-issue and a
re-seal, not data. Sealing a secret that cannot be re-issued would break this and
requires revisiting the decision.

## Consequences

- Key rotation costs nothing: the controller adds keypairs rather than replacing
  them and tries all of them when decrypting, so existing manifests stay valid.
- Renaming an app or moving its namespace requires re-sealing its secrets, from
  plaintext.
- `SealedSecret` diffs are unreviewable, which is acceptable for a solo repo.
- Plaintext still has to live outside git, so a password manager stays part of
  the recovery path.

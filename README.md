# homelab

A single-node k3s cluster on a VPS, described entirely in this repo.

Ansible turns a bare Ubuntu host into a cluster running Argo CD; everything after
that is reconciled from git by Argo CD itself. The split is deliberate: Ansible
only does what has to exist before GitOps can start, and nothing else.

## Layout

```
ansible/                 Bootstrap: host hardening, k3s, Envoy Gateway, Argo CD
kubernetes/
  bootstrap/root-app.yaml  The Argo CD Application that points at kubernetes/apps
  apps/                  One Argo CD Application per app (the app-of-apps)
  manifests/
    infra/               Cluster-owned manifests (gateway, issuers, operators)
    workloads/           Per-app manifests, mostly SealedSecrets
sealed-secrets/          Committed public certificate used to seal secrets
docs/adr/                Architecture decision records
```

## How it fits together

- **k3s**, configured (`/etc/rancher/k3s/config.yaml`) without traefik, to make
  room for Envoy Gateway, and without flannel.
- **Pod networking** is [tiny-cni](https://github.com/corentin-dupaigne/tiny-cni),
  installed by Ansible right after k3s and then adopted by Argo CD. See
  [ADR 0003](docs/adr/0003-use-tiny-cni-for-pod-networking.md).
- **Argo CD** is installed by Ansible (Helm), then handed a single root
  `Application` pointing at `kubernetes/apps`. Every other Application is a file
  in that directory, so adding an app means adding one YAML file and pushing.
- **Routing** is Gateway API served by Envoy Gateway. One `Gateway` in
  `kube-system` owns all the listeners and terminates TLS; each app attaches its
  own `HTTPRoute` to it by name. See [ADR 0002](docs/adr/0002-use-gateway-api-with-envoy-gateway.md).
- **Certificates** come from cert-manager with Let's Encrypt (HTTP-01 solved
  through the Gateway). One listener, and one certificate, per hostname.
- **Secrets** are [Sealed Secrets](docs/sealed-secrets.md): encrypted manifests
  live next to the workloads that use them, and only the in-cluster controller
  can decrypt them. See [ADR 0001](docs/adr/0001-manage-secrets-with-sealed-secrets.md).
- **Private access** is Tailscale. The Tailscale operator exposes a Service to
  the tailnet with an annotation, which is how Argo CD and Nextcloud are reached
  without a public DNS record or a Gateway listener.

Sync order is controlled by `argocd.argoproj.io/sync-wave`: `-2` for tiny-cni, `-1` for
cert-manager and sealed-secrets, `0` for cluster config (gateway, issuers,
operators), `1` for workloads.

## Applications

| App | Exposure |
| --- | --- |
| argocd | tailnet (`http://argocd`) |
| clip | tailnet (`http://clip`) |
| monitoring (Prometheus/Grafana) | tailnet (`http://grafana`) |
| nextcloud | tailnet (`http://nextcloud`) |
| portfolio | `corentindupaigne.com` |
| zeina | `girlfriend.corentindupaigne.com` |
| pomopensource | `pomopensource.corentindupaigne.com` |

Charts come from three places: upstream repos (cert-manager, sealed-secrets,
Tailscale, Nextcloud, kube-prometheus-stack), OCI charts published by the app's own CI
(`ghcr.io/corentin-dupaigne/...`), or straight from the app's git repo
(portfolio). Nothing writes back to this repo.

## Bootstrapping a host

Requires `ansible-playbook` locally, an SSH key at `~/.ssh/id_ed25519`, and the
target's public IP in `ansible/inventory/group_vars/all.yml`.

```bash
cd ansible
make deploy
```

The playbook is idempotent and safe to re-run. It:

1. hardens the host (ufw, SSH key-only, no root login),
2. installs k3s, tiny-cni, Helm and Envoy Gateway,
3. installs Argo CD and applies the root Application,
4. fetches the sealed-secrets public certificate back into
   `sealed-secrets/pub-cert.pem` — commit it if it changed.

Argo CD then pulls everything else. Its initial admin password:

```bash
kubectl -n argocd get secret argocd-initial-admin-secret \
  -o jsonpath='{.data.password}' | base64 -d
```

### Moving an existing host off flannel

Re-running the playbook restarts k3s without flannel and removes flannel's
interfaces, but pods that were already running keep their now-detached network
namespaces. Reboot once afterwards so every pod is recreated on tiny-cni:

```bash
make deploy
ssh ubuntu@<vps> sudo reboot
```

### Testing the playbook

`make test` runs the whole bootstrap against a throwaway [Multipass](https://multipass.run)
VM (created from, or rolled back to, a clean snapshot) and then smoke-tests it.

```bash
make provision   # create or restore the test VM
make test        # provision, run the playbook, smoke test
make smoke       # smoke test only
make clean       # delete the VM
```

## Adding an app

1. Publish a chart with an `HTTPRoute` that attaches to `default-gateway` in
   `kube-system`.
2. Add an HTTPS listener for its hostname to
   `kubernetes/manifests/infra/gateway/gateway.yaml` — cert-manager issues the
   certificate from the Gateway's `cluster-issuer` annotation. Skip this for
   tailnet-only apps; annotate the Service instead.
3. Add `kubernetes/apps/<name>.yaml` with sync-wave `1`.
4. Seal any secrets into `kubernetes/manifests/workloads/<name>/` following
   [docs/sealed-secrets.md](docs/sealed-secrets.md).
5. Push. Argo CD does the rest.

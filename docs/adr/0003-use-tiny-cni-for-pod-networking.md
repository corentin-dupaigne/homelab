---
status: accepted
date: 2026-09-26
---

# Use tiny-cni for pod networking

## Context

k3s ships flannel as its default CNI. The cluster is a single node, so none of
flannel's cross-node overlay is used; it only needs to give pods a bridge, an
IP and a route out. [tiny-cni](https://github.com/corentin-dupaigne/tiny-cni)
is a bridge CNI with built-in IPAM that does exactly that, and running it here
is the way to exercise it on real workloads.

## Decision

Start k3s with `flannel-backend: none` and `disable-network-policy: true`, and
run tiny-cni as a DaemonSet in `kube-system`.

- Ansible installs it straight after k3s, because nothing, Argo CD included,
  gets a pod IP before a CNI exists. Argo CD then adopts the same manifests
  (`kubernetes/manifests/infra/tiny-cni`), with pruning off.
- Without flannel, k3s leaves containerd on the upstream CNI directories
  (`/opt/cni/bin`, `/etc/cni/net.d`), where nothing but tiny-cni is installed.
  Ansible links k3s's `loopback` plugin into `/opt/cni/bin`, since containerd
  needs it for every pod.
- The CNI config is overridden from a ConfigMap so its subnet is the node's
  podCIDR, `10.42.0.0/24`, instead of the image's `10.244.0.0/24`.

## Consequences

- Single node only: tiny-cni has no cross-node routing. Adding a node means
  revisiting this.
- At most 253 pods at a time (one /24, IPs are reused on DEL).
- No NetworkPolicy enforcement.
- IPAM state lives in `/run/tinycni.json` (tmpfs), so it resets on reboot along
  with every pod.
- Switching an existing host from flannel is disruptive: see the README.

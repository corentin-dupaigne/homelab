---
status: accepted
date: 2026-08-23
---

# Route traffic with Gateway API, served by Envoy Gateway

## Context

The cluster hosts several independent apps, each in its own namespace with its
own chart in its own repository. Each needs a hostname, a TLS certificate and a
route to its service.

Ingress makes that awkward: anything beyond basic path routing lives in
controller-specific annotations, so an app chart ends up encoding which ingress
controller the cluster runs. There is also no split between the parts the cluster
owns (listeners, certificates) and the parts an app owns (its own routes).
Gateway API separates the two and expresses redirects and matching in the spec
itself, so app charts only need to know the name of a gateway to attach to.

Traefik ships with k3s and implements Gateway API, so it was tried first. Its
support turned out to be an adapter over Traefik's own model rather than a native
implementation: listeners had to use Traefik's internal entrypoint ports (8000
and 8443) instead of 80 and 443, the `GatewayClass` was created by the controller
and could not be managed in git, and enabling the provider at all required
writing a k3s-specific `HelmChartConfig` to the host filesystem. Each of those
defeated a reason for adopting Gateway API in the first place.

## Decision

Use Gateway API as the routing API, implemented by Envoy Gateway, which speaks
Gateway API natively and has no annotation-based escape hatch to drift back into.
k3s is installed with `--disable traefik` to make room for it.

A single `Gateway` in `kube-system` owns the listeners and terminates TLS; apps
declare their own `HTTPRoute` in their own namespace and attach to it by name.
cert-manager runs with `--enable-gateway-api` and solves ACME challenges through
`gatewayHTTPRoute`, issuing one certificate per listener.

Envoy Gateway is installed by Ansible rather than Argo CD so that the Gateway API
CRDs exist before Argo CD syncs anything that depends on them.

## Consequences

- Adding an app means adding a listener to `gateway.yaml` by hand. This is fine
  at the current scale; a wildcard certificate over DNS-01 is the way out, and a
  separate decision.
- cert-manager is coupled to this choice through its Gateway API flag and solver.
- Envoy Gateway sits outside GitOps, so upgrading it means editing a pinned
  version in an Ansible task and re-running the playbook.
- Envoy costs more memory than Traefik on a single small node, and every k3s
  guide assumes the bundled Traefik that is no longer there.

# Sealed Secrets

Secrets are managed with [Sealed Secrets](https://github.com/bitnami-labs/sealed-secrets). The controller runs in the cluster and is the only thing that can decrypt secrets. Encrypted `SealedSecret` manifests are safe to commit to git.

## Prerequisites

Install the `kubeseal` CLI:

```bash
KUBESEAL_VERSION=0.36.6
wget "https://github.com/bitnami-labs/sealed-secrets/releases/download/v${KUBESEAL_VERSION}/kubeseal-${KUBESEAL_VERSION}-linux-amd64.tar.gz"
tar -xvzf "kubeseal-${KUBESEAL_VERSION}-linux-amd64.tar.gz" kubeseal
sudo install -m 755 kubeseal /usr/local/bin/kubeseal
```

The public key is committed at `sealed-secrets/pub-cert.pem`. You do not need cluster access to encrypt secrets.

## Encrypting a secret

1. Create a plain Kubernetes secret manifest (never commit this file):

```bash
kubectl create secret generic my-secret \
  --namespace=my-namespace \
  --from-literal=MY_KEY=myvalue \
  --dry-run=client -o yaml > /tmp/secret.yaml
```

2. Encrypt it with the public key:

```bash
kubeseal --cert sealed-secrets/pub-cert.pem --format yaml \
  < /tmp/secret.yaml \
  > kubernetes/manifests/workloads/my-app/sealed-secret.yaml
```

3. Commit `sealed-secret.yaml` and delete `/tmp/secret.yaml`.

## Rotating the public key

The controller automatically rotates its key every 30 days. After a rotation, fetch the updated public key and commit it:

```bash
kubectl get secret -n sealed-secrets \
  -l sealedsecrets.bitnami.com/sealed-secrets-key \
  -o jsonpath='{.items[0].data.tls\.crt}' | base64 -d > sealed-secrets/pub-cert.pem
```

Existing `SealedSecret` manifests remain valid — the controller keeps all previous private keys.

## Backing up the private key

If the cluster is lost, existing `SealedSecret` manifests cannot be decrypted without the private key.

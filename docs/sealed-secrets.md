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

## Renewing the sealing key

The controller creates a new sealing keypair every 30 days. It *adds* keypairs rather than replacing them, and tries all of its private keys when decrypting, so existing `SealedSecret` manifests stay valid and never need re-sealing. Only the certificate used to seal *new* secrets changes.

Re-running the playbook syncs the current certificate into the repo for you:

```bash
cd ansible && make deploy
```

It prints a notice when the file changed; commit it. To fetch it without running the playbook:

```bash
kubeseal --controller-namespace sealed-secrets --controller-name sealed-secrets \
  --fetch-cert > sealed-secrets/pub-cert.pem
```

Sealing against a stale certificate is not an error — the controller still holds the older private key and will decrypt it.

## The private key is not backed up

This is deliberate, and the reasoning is in [ADR 0001](adr/0001-manage-secrets-with-sealed-secrets.md). Losing the cluster means no committed `SealedSecret` can be decrypted again.

That is affordable only because every secret sealed here is issued by a provider and can be re-issued. Recovery is: re-issue the credential, seal it against the new controller's certificate, commit. The plaintext lives in a password manager, which is what actually has to survive.

Before sealing a secret that **cannot** be re-issued — anything whose plaintext exists nowhere else — revisit that ADR first. This repo has no way to recover it.

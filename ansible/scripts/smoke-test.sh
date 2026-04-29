#!/bin/bash
set -e

HOST="${1:?Usage: smoke-test.sh <host>}"
FAIL=0

run_check() {
    local desc="$1"
    local cmd="$2"
    printf "  %-55s" "$desc"
    if ssh -o StrictHostKeyChecking=no "ubuntu@$HOST" "sudo $cmd" &>/dev/null 2>&1; then
        echo "PASS"
    else
        echo "FAIL"
        FAIL=1
    fi
}

echo "Waiting for k3s node to be Ready (up to 120s)..."
ELAPSED=0
until ssh -o StrictHostKeyChecking=no "ubuntu@$HOST" "sudo kubectl get nodes" 2>/dev/null | grep -q ' Ready'; do
    sleep 5
    ELAPSED=$((ELAPSED + 5))
    if [ "$ELAPSED" -ge 120 ]; then
        echo "Timed out waiting for k3s node."
        exit 1
    fi
done

run_check "k3s node is Ready"       "kubectl get nodes | grep -q ' Ready'"
run_check "ArgoCD namespace exists" "kubectl get namespace argocd"
run_check "ArgoCD pods are Running" "kubectl get pods -n argocd --field-selector=status.phase=Running --no-headers | grep -q ."
run_check "Root Application exists" "kubectl get applications.argoproj.io -n argocd --no-headers | grep -q ."

echo ""
if [ "$FAIL" -eq 0 ]; then
    echo "All smoke tests passed."
else
    echo "Smoke tests failed."
    exit 1
fi

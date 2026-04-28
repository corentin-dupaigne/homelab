#!/bin/bash
set -e

VM_NAME=homelab-test
SNAPSHOT_NAME=clean
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

if [ "${1}" = "clean" ]; then
    echo "Deleting VM..."
    multipass delete "$VM_NAME"
    multipass purge
    echo "Done."
    exit 0
fi

if ! multipass info "$VM_NAME" &>/dev/null; then
    echo "Creating VM..."
    multipass launch --name "$VM_NAME" --cpus 2 --memory 4G --disk 20G

    echo "Injecting SSH key..."
    multipass exec "$VM_NAME" -- bash -c "echo '$(cat ~/.ssh/id_ed25519.pub)' >> /home/ubuntu/.ssh/authorized_keys"

    echo "Taking clean snapshot..."
    multipass stop "$VM_NAME"
    multipass snapshot "$VM_NAME" --name "$SNAPSHOT_NAME"
    multipass start "$VM_NAME"
else
    echo "Restoring clean snapshot..."
    multipass stop "$VM_NAME"
    multipass restore "$VM_NAME.$SNAPSHOT_NAME"
    multipass start "$VM_NAME"
fi

VM_IP=$(multipass info "$VM_NAME" | grep IPv4 | awk '{print $2}')
echo "VM IP: $VM_IP"

ssh-keygen -R "$VM_IP" 2>/dev/null || true

echo "Waiting for SSH..."
until ssh -o StrictHostKeyChecking=no -o ConnectTimeout=5 "ubuntu@$VM_IP" exit 2>/dev/null; do
    sleep 2
done

echo "Running Ansible playbook..."
ANSIBLE_HOST_KEY_CHECKING=False ansible-playbook \
    -i "$REPO_ROOT/ansible/inventory/hosts.yml" \
    -e "vps_public_ip=$VM_IP" \
    "$REPO_ROOT/ansible/playbook.yml"

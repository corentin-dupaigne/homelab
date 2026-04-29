SHELL   := /bin/bash
VM_NAME := homelab-test
VM_IP   ?= $(shell multipass info $(VM_NAME) 2>/dev/null | grep IPv4 | awk '{print $$2}')

.PHONY: help test provision smoke clean

help:
	@echo "Usage:"
	@echo "  make deploy                  Run playbook against the real VPS"
	@echo "  make test                    Provision VM, run playbook, smoke test"
	@echo "  make provision               Create or restore test VM"
	@echo "  make smoke                   Smoke test against test VM"
	@echo "  make smoke VM_IP=x.x.x.x    Smoke test against a specific host"
	@echo "  make clean                   Delete test VM"

deploy:
	ANSIBLE_CONFIG="$(CURDIR)/ansible.cfg" ansible-playbook \
		-i "$(CURDIR)/inventory/hosts.yml" \
		"$(CURDIR)/playbook.yml"

test:
	@scripts/provision.sh
	$(eval VM_IP := $(shell multipass info $(VM_NAME) 2>/dev/null | grep IPv4 | awk '{print $$2}'))
	ANSIBLE_CONFIG="$(CURDIR)/ansible.cfg" ansible-playbook \
		-i "$(CURDIR)/inventory/hosts.yml" \
		-e "vps_public_ip=$(VM_IP)" \
		"$(CURDIR)/playbook.yml"
	@$(MAKE) smoke VM_IP=$(VM_IP)

provision:
	@scripts/provision.sh

smoke:
	@scripts/smoke-test.sh "$(VM_IP)"

clean:
	@scripts/provision.sh clean

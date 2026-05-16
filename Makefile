# IronCart_Scan — local development Makefile.
#
# Wraps markshust/docker-magento (https://github.com/markshust/docker-magento)
# under .sandbox/ to give contributors a one-command path to a working Magento
# 2 install with this module symlinked in.
#
# See docs/sandbox.md for first-run setup, Adobe auth keys, hosts file, and
# known papercuts.

# ---------------------------------------------------------------------------
# Tunables (override per invocation, e.g. `make sandbox M2_VERSION=2.4.6`).
# ---------------------------------------------------------------------------

# Magento version. Must match a tag/release supported by markshust/docker-magento
# and the CI matrix (2.4.4–2.4.7).
M2_VERSION ?= 2.4.7

# PHP version. Must match the CI matrix (8.1–8.3) and be compatible with the
# chosen M2_VERSION.
PHP_VERSION ?= 8.3

# Magento edition consumed by `bin/download` (community | enterprise | mageos).
# Community is the only edition that doesn't need an Adobe Commerce contract.
M2_EDITION ?= community

# Host used inside the Magento install. Add this to /etc/hosts pointing at
# 127.0.0.1 (Shust's setup script does this on macOS/Linux; WSL2 needs a
# manual entry).
SANDBOX_HOST ?= ironcart.test

# Sandbox checkout location. Gitignored.
SANDBOX_DIR := .sandbox

# Upstream `lib/template` URL. This is the supported bootstrap per upstream's
# README; it hoists `compose/*` up to the project root so `bin/setup` et al.
# live at the sandbox root rather than under `compose/`.
SHUST_TEMPLATE_URL := https://raw.githubusercontent.com/markshust/docker-magento/master/lib/template

# Path inside the sandbox where the module is symlinked. Shust's harness
# bind-mounts $(SANDBOX_DIR)/src into the PHP container, so a symlink under
# src/app/code/IronCart/Scan -> repo root is all that's needed.
MODULE_DEST := $(SANDBOX_DIR)/src/app/code/IronCart/Scan
REPO_ROOT   := $(abspath .)

# ---------------------------------------------------------------------------
# Help (default target).
# ---------------------------------------------------------------------------

.DEFAULT_GOAL := help

.PHONY: help
help: ## Show this help.
	@echo "IronCart_Scan local sandbox"
	@echo ""
	@echo "Targets:"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| sort \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'
	@echo ""
	@echo "Tunables (override per invocation):"
	@echo "  M2_VERSION   (default $(M2_VERSION))   — Magento version (2.4.4–2.4.7)"
	@echo "  PHP_VERSION  (default $(PHP_VERSION))   — PHP version (8.1–8.3)"
	@echo "  M2_EDITION   (default $(M2_EDITION)) — Magento edition for bin/download"
	@echo "  SANDBOX_HOST (default $(SANDBOX_HOST)) — host for the install"

# ---------------------------------------------------------------------------
# Sandbox lifecycle.
# ---------------------------------------------------------------------------

.PHONY: sandbox
sandbox: $(SANDBOX_DIR)/.installed ## Bootstrap a full sandbox (clone, install, symlink, enable module).

# Scaffold the Shust project layout via `lib/template`. This is the supported
# bootstrap per upstream: it fetches `compose/` from the docker-magento repo
# and hoists its contents up to the sandbox root, then `git init`s a fresh
# repo there. Replaces the previous `git clone` against the repo root layout,
# which no longer matches upstream (scripts moved to `compose/bin/`).
$(SANDBOX_DIR)/.cloned:
	@if [ -f "$(SANDBOX_DIR)/bin/setup" ]; then \
		echo ">>> $(SANDBOX_DIR) already bootstrapped, skipping template"; \
	else \
		echo ">>> bootstrapping markshust/docker-magento template into $(SANDBOX_DIR)"; \
		mkdir -p $(SANDBOX_DIR); \
		if [ -d "$(SANDBOX_DIR)/bin" ]; then \
			echo "ERROR: $(SANDBOX_DIR)/bin already exists but no bin/setup — sandbox is in a half-bootstrapped state. Run 'make sandbox-nuke' and retry."; exit 1; \
		fi; \
		cd $(SANDBOX_DIR) && curl -fsSL $(SHUST_TEMPLATE_URL) | bash; \
	fi
	@touch $@

# Run Shust's download + setup, pin PHP, symlink the module, enable + upgrade.
#
# Upstream split the old `bin/setup <DOMAIN> <VERSION>` flow into two steps:
#   1. `bin/download <edition> <version>` — composer-creates Magento into src/
#   2. `bin/setup <domain>` — installs Magento, generates SSL, sets admin user
# These must run in that order. PHP version is no longer set by `bin/setup-php`
# (which was removed); we sed the `phpfpm` image tag in `compose.yaml` before
# either step kicks the containers.
$(SANDBOX_DIR)/.installed: $(SANDBOX_DIR)/.cloned
	@command -v docker >/dev/null 2>&1 || { \
		echo "ERROR: docker not found in PATH. Install Docker Desktop (macOS/Windows) or docker.io (Linux)."; exit 1; }
	@if [ ! -f "$$HOME/.composer/auth.json" ] && [ ! -f "$(SANDBOX_DIR)/src/auth.json" ]; then \
		echo "WARNING: no Composer auth.json found. Magento composer install will fail."; \
		echo "         See docs/sandbox.md → 'Adobe auth keys' before continuing."; \
		echo "         Press Ctrl-C now to abort, or wait 5s to try anyway..."; \
		sleep 5; \
	fi
	@echo ">>> pinning PHP to $(PHP_VERSION) in $(SANDBOX_DIR)/compose.yaml"
	@if [ ! -f "$(SANDBOX_DIR)/compose.yaml" ]; then \
		echo "ERROR: $(SANDBOX_DIR)/compose.yaml not found — template bootstrap is incomplete."; exit 1; \
	fi
	@# Replace the phpfpm image tag with PHP_VERSION. Upstream publishes both
	@# `<ver>-fpm` and `<ver>-fpm-<patch>` tags; we use the un-patched form so
	@# this stays valid as upstream rev-bumps the patch suffix.
	@sed -i.bak -E 's|markoshust/magento-php:[0-9]+\.[0-9]+-fpm(-[0-9]+)?|markoshust/magento-php:$(PHP_VERSION)-fpm|' $(SANDBOX_DIR)/compose.yaml
	@rm -f $(SANDBOX_DIR)/compose.yaml.bak
	@echo ">>> downloading Magento $(M2_EDITION) $(M2_VERSION) via bin/download"
	cd $(SANDBOX_DIR) && bin/download $(M2_EDITION) $(M2_VERSION)
	@echo ">>> running Shust setup for $(SANDBOX_HOST)"
	cd $(SANDBOX_DIR) && bin/setup $(SANDBOX_HOST)
	@echo ">>> symlinking module: $(MODULE_DEST) -> $(REPO_ROOT)"
	@mkdir -p $(SANDBOX_DIR)/src/app/code/IronCart
	@rm -rf $(MODULE_DEST)
	ln -s $(REPO_ROOT) $(MODULE_DEST)
	@echo ">>> enabling IronCart_Scan"
	cd $(SANDBOX_DIR) && bin/magento module:enable IronCart_Scan
	cd $(SANDBOX_DIR) && bin/magento setup:upgrade
	cd $(SANDBOX_DIR) && bin/magento setup:di:compile || true
	cd $(SANDBOX_DIR) && bin/magento cache:flush
	@touch $@
	@echo ""
	@echo ">>> sandbox ready at https://$(SANDBOX_HOST)/"
	@echo ">>> run \`make sandbox-scan\` to exercise the scanner"

.PHONY: sandbox-up
sandbox-up: ## Start the sandbox containers (does not reinstall).
	@test -d $(SANDBOX_DIR) || { echo "ERROR: no sandbox yet — run 'make sandbox' first"; exit 1; }
	cd $(SANDBOX_DIR) && bin/start

.PHONY: sandbox-down
sandbox-down: ## Stop the sandbox containers (preserves data).
	@test -d $(SANDBOX_DIR) || { echo "ERROR: no sandbox to stop"; exit 1; }
	cd $(SANDBOX_DIR) && bin/stop

.PHONY: sandbox-shell
sandbox-shell: ## Open a shell in the PHP container.
	@test -d $(SANDBOX_DIR) || { echo "ERROR: no sandbox — run 'make sandbox' first"; exit 1; }
	cd $(SANDBOX_DIR) && bin/cli bash

.PHONY: sandbox-scan
sandbox-scan: ## Run `bin/magento ironcart:scan --format=json` inside the sandbox.
	@test -d $(SANDBOX_DIR) || { echo "ERROR: no sandbox — run 'make sandbox' first"; exit 1; }
	cd $(SANDBOX_DIR) && bin/magento ironcart:scan --format=json

.PHONY: sandbox-reset
sandbox-reset: ## Drop and reinstall the Magento DB (keeps the cloned harness).
	@test -d $(SANDBOX_DIR) || { echo "ERROR: no sandbox to reset"; exit 1; }
	cd $(SANDBOX_DIR) && bin/stop || true
	@rm -f $(SANDBOX_DIR)/.installed
	@echo ">>> sandbox marked uninstalled; run 'make sandbox' to reinstall"

.PHONY: sandbox-nuke
sandbox-nuke: ## Stop containers and delete .sandbox/ entirely. Destructive.
	@if [ -d $(SANDBOX_DIR) ]; then \
		echo ">>> stopping containers"; \
		(cd $(SANDBOX_DIR) && bin/stop) || true; \
		echo ">>> removing $(SANDBOX_DIR)"; \
		rm -rf $(SANDBOX_DIR); \
	else \
		echo ">>> $(SANDBOX_DIR) already absent"; \
	fi

# ---------------------------------------------------------------------------
# File-integrity manifests (IC-070).
#
# Generates `etc/manifests/magento-core-community-<version>.json` for every
# supported Magento Open Source tag by shallow-cloning `magento/magento2` at
# the tag, walking the tree, and computing SHA-256 per file. See
# docs/manifests.md and IronCartLabs/IronCartM2#47 for the design rationale.
#
# Network required. NOT invoked at runtime — the scanner only ever reads the
# generated JSON files from disk.
# ---------------------------------------------------------------------------

# Supported tags. Update alongside Check/PatchLevel/MagentoPatchCatalog.
# Adobe Commerce coverage is intentionally out of scope for v2 (deferred
# to v3 hosted backend, which can run paid-composer auth server-side).
MANIFEST_VERSIONS ?= \
	2.4.4-p12 \
	2.4.5-p11 \
	2.4.6-p10 \
	2.4.7-p5

MANIFEST_DIR := etc/manifests

.PHONY: manifests
manifests: ## Build IC-070 file-integrity manifests for every supported tag.
	@command -v git >/dev/null 2>&1 || { echo "ERROR: git not on PATH"; exit 1; }
	@command -v php >/dev/null 2>&1 || { echo "ERROR: php not on PATH"; exit 1; }
	@mkdir -p $(MANIFEST_DIR)
	@for version in $(MANIFEST_VERSIONS); do \
		echo ">>> building manifest for $$version"; \
		php bin/build-manifest.php --version=$$version || { echo "ERROR: $$version failed"; exit 1; }; \
	done
	@echo ">>> done. Manifests are under $(MANIFEST_DIR)/"

.PHONY: manifests-clean
manifests-clean: ## Remove all generated manifests under etc/manifests/.
	@if [ -d "$(MANIFEST_DIR)" ]; then \
		find $(MANIFEST_DIR) -name 'magento-core-*.json' -delete; \
		echo ">>> cleared $(MANIFEST_DIR)"; \
	else \
		echo ">>> $(MANIFEST_DIR) already absent"; \
	fi

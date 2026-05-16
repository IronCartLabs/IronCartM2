# IronCart_Scan — local development Makefile.
#
# Wraps markshust/docker-magento (https://github.com/markshust/docker-magento)
# under .sandbox/ to give contributors a one-command path to a working Magento
# 2 install with this module bind-mounted in.
#
# See docs/sandbox.md for first-run setup, Adobe auth keys, hosts file, and
# known papercuts.

# ---------------------------------------------------------------------------
# Tunables (override per invocation, e.g. `make sandbox M2_VERSION=2.4.6`).
# ---------------------------------------------------------------------------

# Magento version. Consumed by `bin/download community $(M2_VERSION)`. See
# https://github.com/markshust/docker-magento/blob/master/compose/bin/download
# for supported tags (2.4.4-px, 2.4.5-px, 2.4.6-px, 2.4.7-px).
M2_VERSION ?= 2.4.7

# PHP version. Best-effort: applied by sed-ing the phpfpm image tag in
# compose.yaml after template scaffolding. Must match the CI matrix (8.1–8.3)
# and be compatible with the chosen M2_VERSION.
PHP_VERSION ?= 8.3

# Database image. Shust's compose.yaml currently defaults to `mariadb:11.4`,
# which Magento 2.4.7 rejects (supports MariaDB 10.2–10.6 / MySQL 8 / 5.7).
# We sed the db image down to a supported tag. Override if you need MySQL or
# a different MariaDB cell:
#   make sandbox DB_IMAGE=mysql:8.4
DB_IMAGE ?= mariadb:10.6

# Host used inside the Magento install. Add `127.0.0.1 $(SANDBOX_HOST)` to
# /etc/hosts both in the WSL guest *and* the Windows host (Shust's bin/setup
# tries to add it to /etc/hosts via sudo; pre-adding avoids a sudo prompt
# during the install).
SANDBOX_HOST ?= ironcart.test

# Sandbox checkout location. Gitignored.
SANDBOX_DIR := .sandbox

# Upstream entrypoint. `lib/template` is Shust's supported bootstrap that
# scaffolds bin/, compose.yaml, env/, etc. at the sandbox root. Tracks master.
SHUST_TEMPLATE_URL := https://raw.githubusercontent.com/markshust/docker-magento/master/lib/template

# Bind-mount target inside the PHP container. Shust's compose.dev.yaml mounts
# only the configured subdirs of src/ into /var/www/html, so symlinks that
# leave src/ (pointing at $(REPO_ROOT)) dangle inside the container. We sed
# a dedicated bind-mount for the module into compose.dev.yaml instead.
MODULE_CONTAINER_PATH := /var/www/html/app/code/IronCart/Scan
REPO_ROOT             := $(abspath .)

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
	@echo "  DB_IMAGE     (default $(DB_IMAGE))  — db image (mariadb:10.6 / mysql:8.4)"
	@echo "  SANDBOX_HOST (default $(SANDBOX_HOST)) — host for the install"

# ---------------------------------------------------------------------------
# Sandbox lifecycle.
#
# Build is split into four sentinels so a partial failure can be retried
# without redoing the slow earlier steps. Sentinels live at
# $(SANDBOX_DIR)/.{cloned,configured,downloaded,installed} and depend on
# each other in order.
# ---------------------------------------------------------------------------

.PHONY: sandbox
sandbox: $(SANDBOX_DIR)/.installed ## Bootstrap a full sandbox (template, configure, download, install, enable module).

# Step 1 — scaffold Shust's project layout into .sandbox/ via lib/template.
# Drops bin/, compose.yaml, env/, etc. at the sandbox root.
$(SANDBOX_DIR)/.cloned:
	@if [ -f "$(SANDBOX_DIR)/bin/setup" ]; then \
		echo ">>> $(SANDBOX_DIR) already templated, skipping"; \
	else \
		echo ">>> bootstrapping markshust/docker-magento template into $(SANDBOX_DIR)"; \
		mkdir -p $(SANDBOX_DIR); \
		cd $(SANDBOX_DIR) && curl -fsSL $(SHUST_TEMPLATE_URL) | bash; \
	fi
	@touch $@

# Step 2 — pin PHP + db images, inject the module bind-mount. Idempotent.
$(SANDBOX_DIR)/.configured: $(SANDBOX_DIR)/.cloned
	@echo ">>> pinning PHP image to $(PHP_VERSION) in compose.yaml"
	@sed -i.bak -E 's|(markoshust/magento-php:)[0-9]+\.[0-9]+(-fpm-)|\1$(PHP_VERSION)\2|' $(SANDBOX_DIR)/compose.yaml || \
		echo "(could not sed PHP image — edit phpfpm.image manually if $(PHP_VERSION) is not default)"
	@echo ">>> pinning db image to $(DB_IMAGE) in compose.yaml"
	@sed -i.bak 's|image: mariadb:[0-9.]\+|image: $(DB_IMAGE)|' $(SANDBOX_DIR)/compose.yaml || \
		echo "(could not sed db image — edit db.image manually if $(DB_IMAGE) is not default)"
	@echo ">>> injecting module bind-mount into compose.dev.yaml: $(REPO_ROOT) -> $(MODULE_CONTAINER_PATH)"
	@if grep -q "$(MODULE_CONTAINER_PATH):cached" $(SANDBOX_DIR)/compose.dev.yaml; then \
		echo ">>> bind-mount already present, skipping"; \
	else \
		sed -i.bak '/volumes: &appvolumes$$/a\      - $(REPO_ROOT):$(MODULE_CONTAINER_PATH):cached' $(SANDBOX_DIR)/compose.dev.yaml; \
	fi
	@echo ">>> masking $(MODULE_CONTAINER_PATH)/.sandbox with anonymous volume"
	@# The bind-mount above exposes the entire repo root, including .sandbox/
	@# itself — and .sandbox/src/vendor pulls in a second copy of Codeception
	@# (etc.) that PHP's autoloader fatals on as duplicate-trait declarations.
	@# A docker anonymous volume mounted on the subpath hides it from the
	@# container's filesystem view. Standard "node_modules in a bind mount"
	@# pattern.
	@if grep -q "$(MODULE_CONTAINER_PATH)/.sandbox$$" $(SANDBOX_DIR)/compose.dev.yaml; then \
		echo ">>> .sandbox mask already present, skipping"; \
	else \
		sed -i.bak '/$(subst /,\/,$(MODULE_CONTAINER_PATH)):cached$$/a\      - $(MODULE_CONTAINER_PATH)/.sandbox' $(SANDBOX_DIR)/compose.dev.yaml; \
	fi
	@touch $@

# Step 3 — bin/download pulls Magento source via composer create-project.
# Slowest step (5–10 min). bin/download refuses if src/ has content, so the
# sentinel guards against accidental re-runs.
$(SANDBOX_DIR)/.downloaded: $(SANDBOX_DIR)/.configured
	@command -v docker >/dev/null 2>&1 || { \
		echo "ERROR: docker not found in PATH. Install Docker Desktop (macOS/Windows) or docker.io (Linux)."; exit 1; }
	@if [ ! -f "$$HOME/.composer/auth.json" ] && [ ! -f "$(SANDBOX_DIR)/src/auth.json" ]; then \
		echo "WARNING: no Composer auth.json found. Magento composer install will fail."; \
		echo "         See docs/sandbox.md → 'Adobe auth keys' before continuing."; \
		echo "         Press Ctrl-C now to abort, or wait 5s to try anyway..."; \
		sleep 5; \
	fi
	@echo ">>> downloading Magento $(M2_VERSION) source (community edition)"
	cd $(SANDBOX_DIR) && bin/download community $(M2_VERSION)
	@touch $@

# Step 4 — bin/setup installs Magento, then we enable + upgrade IronCart_Scan
# (already visible inside the container via the bind-mount from step 2).
# Magento 2.4+ ships Magento_TwoFactorAuth + Magento_AdminAdobeImsTwoFactorAuth
# mandatory; in a dev sandbox with no real SMTP they make the admin
# inaccessible. Disabled here as a sandbox-only convenience. Production
# Magento installs MUST keep them enabled.
$(SANDBOX_DIR)/.installed: $(SANDBOX_DIR)/.downloaded
	@echo ">>> running Shust setup for $(SANDBOX_HOST)"
	cd $(SANDBOX_DIR) && bin/setup $(SANDBOX_HOST)
	@echo ">>> disabling 2FA modules (sandbox only — production must keep enabled)"
	cd $(SANDBOX_DIR) && bin/clinotty bin/magento module:disable \
		Magento_TwoFactorAuth Magento_AdminAdobeImsTwoFactorAuth
	@echo ">>> enabling IronCart_Scan"
	cd $(SANDBOX_DIR) && bin/clinotty bin/magento module:enable IronCart_Scan
	cd $(SANDBOX_DIR) && bin/clinotty bin/magento setup:upgrade
	cd $(SANDBOX_DIR) && bin/clinotty bin/magento setup:di:compile || true
	cd $(SANDBOX_DIR) && bin/clinotty bin/magento cache:flush
	@touch $@
	@echo ""
	@echo ">>> sandbox ready at https://$(SANDBOX_HOST)/"
	@echo ">>> admin: https://$(SANDBOX_HOST)/admin/ (user=john.smith pass=password123)"
	@echo ">>> run \`make sandbox-scan\` to exercise the scanner"

.PHONY: sandbox-up
sandbox-up: ## Start the sandbox containers (does not reinstall).
	@test -d $(SANDBOX_DIR) || { echo "ERROR: no sandbox yet — run 'make sandbox' first"; exit 1; }
	cd $(SANDBOX_DIR) && bin/docker-compose up -d --remove-orphans

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
	cd $(SANDBOX_DIR) && bin/clinotty bin/magento ironcart:scan --format=json

.PHONY: sandbox-reset
sandbox-reset: ## Drop the Magento install + db volume (keeps the templated harness + src/).
	@test -d $(SANDBOX_DIR) || { echo "ERROR: no sandbox to reset"; exit 1; }
	cd $(SANDBOX_DIR) && bin/stop || true
	-docker volume rm sandbox_dbdata 2>/dev/null || true
	@rm -f $(SANDBOX_DIR)/.installed
	@echo ">>> sandbox marked uninstalled; run 'make sandbox' to reinstall"

.PHONY: sandbox-nuke
sandbox-nuke: ## Stop containers, delete volumes, delete .sandbox/ entirely. Destructive.
	@if [ -d $(SANDBOX_DIR) ]; then \
		echo ">>> stopping containers"; \
		(cd $(SANDBOX_DIR) && bin/stop) || true; \
		echo ">>> removing volumes"; \
		docker volume ls --filter "name=sandbox_" -q | xargs -r docker volume rm || true; \
		echo ">>> removing $(SANDBOX_DIR)"; \
		rm -rf $(SANDBOX_DIR); \
	else \
		echo ">>> $(SANDBOX_DIR) already absent"; \
	fi

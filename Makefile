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

# Host used inside the Magento install. Add this to /etc/hosts pointing at
# 127.0.0.1 (Shust's setup script does this on macOS/Linux; WSL2 needs a
# manual entry).
SANDBOX_HOST ?= ironcart.test

# Sandbox checkout location. Gitignored.
SANDBOX_DIR := .sandbox
SHUST_REPO  := https://github.com/markshust/docker-magento.git

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
	@echo "  SANDBOX_HOST (default $(SANDBOX_HOST)) — host for the install"

# ---------------------------------------------------------------------------
# Sandbox lifecycle.
# ---------------------------------------------------------------------------

.PHONY: sandbox
sandbox: $(SANDBOX_DIR)/.installed ## Bootstrap a full sandbox (clone, install, symlink, enable module).

# Clone Shust's harness on first use.
$(SANDBOX_DIR)/.cloned:
	@if [ -d "$(SANDBOX_DIR)/.git" ]; then \
		echo ">>> $(SANDBOX_DIR) already cloned, skipping"; \
	else \
		echo ">>> cloning markshust/docker-magento into $(SANDBOX_DIR)"; \
		git clone --depth 1 $(SHUST_REPO) $(SANDBOX_DIR); \
	fi
	@mkdir -p $(SANDBOX_DIR)
	@touch $@

# Run Shust's setup, pin PHP, symlink the module, enable + upgrade.
$(SANDBOX_DIR)/.installed: $(SANDBOX_DIR)/.cloned
	@command -v docker >/dev/null 2>&1 || { \
		echo "ERROR: docker not found in PATH. Install Docker Desktop (macOS/Windows) or docker.io (Linux)."; exit 1; }
	@if [ ! -f "$$HOME/.composer/auth.json" ] && [ ! -f "$(SANDBOX_DIR)/src/auth.json" ]; then \
		echo "WARNING: no Composer auth.json found. Magento composer install will fail."; \
		echo "         See docs/sandbox.md → 'Adobe auth keys' before continuing."; \
		echo "         Press Ctrl-C now to abort, or wait 5s to try anyway..."; \
		sleep 5; \
	fi
	@echo ">>> running Shust setup for $(SANDBOX_HOST) on Magento $(M2_VERSION)"
	cd $(SANDBOX_DIR) && bin/setup $(SANDBOX_HOST) $(M2_VERSION)
	@echo ">>> pinning PHP to $(PHP_VERSION)"
	cd $(SANDBOX_DIR) && bin/setup-php $(PHP_VERSION) || \
		echo "(bin/setup-php not present in this Shust release — edit compose.dev.yaml manually if PHP $(PHP_VERSION) is not the default)"
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

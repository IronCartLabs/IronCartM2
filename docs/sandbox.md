# Local Magento sandbox

Contributors get a one-command path to a working Magento 2 install with this
module symlinked in, courtesy of [markshust/docker-magento][shust] wrapped under
`.sandbox/` and a thin `make` interface.

The sandbox is **for local development only**. CI runs its own matrix of
Magento × PHP versions via GitHub Actions ([#8][ci-issue]); do not depend on
this sandbox in CI.

[shust]: https://github.com/markshust/docker-magento
[ci-issue]: https://github.com/IronCartLabs/IronCartM2/issues/8

## Prerequisites

- **Docker** with at least 6 GB of memory allocated. macOS and Windows: Docker
  Desktop. Linux: native `docker` + `docker compose`. WSL2: install Docker
  Desktop on the Windows side with the WSL2 backend, then run `make` from your
  WSL2 shell.
- **GNU make** and a POSIX shell. Native Windows (PowerShell / cmd) is **not
  supported** for this issue — use WSL2. A first-class Windows path is tracked
  separately if there's demand.
- **Adobe Marketplace auth keys** (see below).
- A free entry in `/etc/hosts` for `ironcart.test` (Shust's setup script will
  attempt to add this on macOS/Linux; WSL2 users may need to add it manually
  to the Windows hosts file).

## Adobe auth keys

Magento's `composer.json` pulls from `repo.magento.com`, which requires Adobe
Marketplace credentials. Without them, `make sandbox` will fail during
`composer create-project`.

1. Sign in at [marketplace.magento.com](https://marketplace.magento.com/).
2. Go to **My Profile → Access Keys** and create a Magento 2 key pair.
3. Drop them into `~/.composer/auth.json`:

   ```json
   {
     "http-basic": {
       "repo.magento.com": {
         "username": "YOUR_PUBLIC_KEY",
         "password": "YOUR_PRIVATE_KEY"
       }
     }
   }
   ```

This is the unavoidable papercut — there's no workaround. Treat the keys like
any other credential (don't commit them, rotate when leaving an employer, etc).

## First-run

From the repo root:

```bash
make sandbox
```

This will:

1. Bootstrap `markshust/docker-magento` into `.sandbox/` (gitignored) via
   `lib/template`. Upstream restructured in 2024–2025 so that scripts live
   under `compose/bin/`; `lib/template` is the supported entrypoint that
   hoists `compose/*` up to the sandbox root so `bin/setup` et al. live
   where the Makefile expects them.
2. Pin PHP to the configured version by rewriting the `phpfpm.image` tag
   in `.sandbox/compose.yaml` (`PHP_VERSION`, default `8.3`). Upstream no
   longer ships `bin/setup-php`; the image tag is now the source of truth.
3. Run `bin/download community 2.4.7` — `composer create-project`s Magento
   into `.sandbox/src/`. `M2_EDITION` (default `community`) and `M2_VERSION`
   are the arguments.
4. Run `bin/setup ironcart.test` — starts containers, runs
   `setup:install`, generates the SSL cert, sets the admin user, indexes
   everything. The version arg was removed upstream; `bin/setup` now takes
   only the domain.
5. Symlink the repo root into `.sandbox/src/app/code/IronCart/Scan` so the
   PHP container sees this module at the correct Magento module path.
6. `bin/magento module:enable IronCart_Scan` + `setup:upgrade` +
   `setup:di:compile` + `cache:flush`.

The `download → setup` order matters: `bin/setup` runs `setup:install`
against the Magento codebase that `bin/download` placed into `src/`, so
download must run first. The Makefile encodes this order; if you're
running the steps by hand inside `.sandbox/`, do the same.

First run takes ~10–20 minutes depending on network speed. Subsequent
`make sandbox` invocations are no-ops (the `.installed` sentinel short-circuits
them).

The default admin URL and credentials come from Shust's harness — see his
README. The storefront is at `https://ironcart.test/`.

## Daily use

```bash
# Stop containers (preserves DB, data, etc.).
make sandbox-down

# Bring them back up.
make sandbox-up

# Drop into a bash shell inside the PHP container.
make sandbox-shell

# Run the scanner and emit JSON.
make sandbox-scan
```

Because the module is bind-mounted via symlink, any change you make in the
repo is visible inside the container immediately. After editing DI XML, ACL
XML, or anything else that Magento caches, run:

```bash
make sandbox-shell
bin/magento setup:upgrade
bin/magento cache:flush
```

inside the container.

## Matrix testing

To exercise an older Magento or different PHP:

```bash
make sandbox-nuke                                # destroy current sandbox
make sandbox M2_VERSION=2.4.6 PHP_VERSION=8.2    # rebuild against the matrix cell
```

Supported combinations:

| Magento | PHP versions       |
|---------|--------------------|
| 2.4.4   | 8.1                |
| 2.4.5   | 8.1                |
| 2.4.6   | 8.1, 8.2           |
| 2.4.7   | 8.2, 8.3           |
| 2.4.8   | 8.3, 8.4           |
| 2.4.9   | 8.4, 8.5           |

Note: 2.4.4–2.4.6 are local-sandbox combinations only — their CI cells were
dropped in [#178](https://github.com/IronCartLabs/IronCartM2/issues/178)
(Adobe end-of-life). The CI-gated cells today cover 2.4.7 / 2.4.8 / 2.4.9 —
see the support matrix in the
[README](../README.md#magento--php-support-matrix).

`M2_VERSION` and `PHP_VERSION` are accepted on every target (`make sandbox-up
PHP_VERSION=8.2` etc.), but only `make sandbox` actually rebuilds against
them. The other targets just operate on whatever Shust currently has running.

## Resetting and nuking

```bash
# Reset the Magento install but keep the cloned Shust harness.
# Run `make sandbox` again afterwards to reinstall.
make sandbox-reset

# Stop containers and delete .sandbox/ entirely. Destructive.
# Use when switching M2 versions or recovering from a corrupted install.
make sandbox-nuke
```

## Known papercuts

- **Adobe auth keys.** No way around it. See above.
- **Hosts file.** Shust's setup script edits `/etc/hosts` on macOS/Linux but
  not Windows. WSL2 users may need to add `127.0.0.1  ironcart.test` to the
  Windows hosts file (`C:\Windows\System32\drivers\etc\hosts`, requires
  admin) for the storefront to resolve in a Windows browser.
- **Port conflicts.** Shust's harness binds 80, 443, 3306, 6379, and 9200 by
  default. If you already run a local MySQL / nginx, stop it before
  `make sandbox-up` or override the ports in `.sandbox/compose.yaml`
  (dev-only overrides go in `.sandbox/compose.dev.yaml`).
- **Memory pressure.** Magento 2 + Elasticsearch + Redis + MySQL is heavy.
  Allocate at least 6 GB to Docker Desktop or `setup:upgrade` will OOM.
- **`setup:di:compile` failures.** First-time compilation can hit memory
  limits. The Makefile treats `setup:di:compile` as best-effort (`|| true`) —
  if it fails, jump into `make sandbox-shell` and rerun it manually with
  `php -d memory_limit=4G bin/magento setup:di:compile`.
- **Symlink + Windows native.** `ln -s` doesn't behave correctly under native
  Windows without developer mode and even then Docker Desktop sometimes
  refuses to follow the link into the container. Use WSL2.
- **PHP pinning.** Upstream removed `bin/setup-php`; PHP is now selected by
  the `phpfpm.image` tag in `.sandbox/compose.yaml`. The Makefile rewrites
  this tag (best-effort `sed` against `markoshust/magento-php:<ver>-fpm`
  before running `bin/download` / `bin/setup`). If the sed pattern fails to
  match (upstream renamed the image, etc.), edit `.sandbox/compose.yaml`
  manually before `bin/download` runs. Upstream publishes both
  `markoshust/magento-php:<ver>-fpm` and `markoshust/magento-php:<ver>-fpm-<patch>`
  tags; either works.
- **`bin/setup` arg drift.** Upstream's `bin/setup` used to take
  `<DOMAIN> <VERSION>`; it now takes only `<DOMAIN>`. Version is consumed by
  the new `bin/download <edition> <version>` step that must run **before**
  `bin/setup`. The Makefile handles this; if you're running the scripts by
  hand, do `bin/download community 2.4.7 && bin/setup ironcart.test`.

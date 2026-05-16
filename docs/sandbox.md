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
- Hosts file entries for `ironcart.test`:
    - **Windows** (`C:\Windows\System32\drivers\etc\hosts`, admin) — required
      for the browser to resolve the storefront. Add `127.0.0.1  ironcart.test`.
    - **WSL2 guest** (`/etc/hosts`, sudo) — required to **pre-empt Shust's
      `bin/setup`**, which tries to `sudo` mid-install and will time out if
      `make sandbox` is running non-interactively. Pre-add it:
      `echo '127.0.0.1  ironcart.test' | sudo tee -a /etc/hosts`.

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

1. Scaffold `markshust/docker-magento` into `.sandbox/` via Shust's
   [`lib/template`](https://github.com/markshust/docker-magento/blob/master/lib/template)
   bootstrap (curl-piped, tracks upstream `master`). Drops `bin/`,
   `compose.yaml`, `env/`, etc. at the sandbox root.
2. Pin the `phpfpm` image tag in `compose.yaml` to the configured PHP
   (`PHP_VERSION`, default 8.3) via `sed` (best-effort).
3. Run `bin/download community 2.4.7` — pulls Magento source via
   `composer create-project` against `repo.magento.com`. Requires auth keys.
4. Run `bin/setup ironcart.test` — starts containers, installs Magento,
   sets up the admin user, indexes everything.
5. Inject a bind-mount into `compose.dev.yaml` so the repo root appears at
   `/var/www/html/app/code/IronCart/Scan` inside the PHP container, plus an
   anonymous-volume mask over `.../Scan/.sandbox` so the bind doesn't expose
   `.sandbox/src/vendor` recursively (PHP autoload fatals on duplicate trait
   declarations otherwise — the same gotcha as bind-mounting a JS project
   with a populated `node_modules`).
6. Disable `Magento_TwoFactorAuth` and `Magento_AdminAdobeImsTwoFactorAuth`
   for the dev sandbox — Magento 2.4+ ships them mandatory and they email a
   TOTP secret on first login, which a local sandbox can't deliver. **Never
   disable these in production.**
7. `bin/magento module:enable IronCart_Scan` + `setup:upgrade` +
   `setup:di:compile` + `cache:flush`.

First run takes ~10–20 minutes depending on network speed. Subsequent
`make sandbox` invocations are no-ops (the `.installed` sentinel short-circuits
them).

The storefront is at `https://ironcart.test/`. Admin is
`https://ironcart.test/admin/` with `user=john.smith` `pass=password123` (Shust
defaults). Browser will warn about the self-signed cert — click through, or
import `.sandbox/rootCA.pem` into Windows trusted roots to silence the warning.

If you need to test something against real 2FA, re-enable the modules
(`bin/clinotty bin/magento module:enable Magento_TwoFactorAuth …`) and read
the setup email at the mailcatcher UI: `http://localhost:1080/`.

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

Supported combinations (mirrors the CI matrix in [#8][ci-issue]):

| Magento | PHP versions       |
|---------|--------------------|
| 2.4.4   | 8.1                |
| 2.4.5   | 8.1                |
| 2.4.6   | 8.1, 8.2           |
| 2.4.7   | 8.2, 8.3           |

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
  `make sandbox-up` or override the ports in
  `.sandbox/compose.dev.yaml`.
- **Memory pressure.** Magento 2 + Elasticsearch + Redis + MySQL is heavy.
  Allocate at least 6 GB to Docker Desktop or `setup:upgrade` will OOM.
- **`setup:di:compile` failures.** First-time compilation can hit memory
  limits. The Makefile treats `setup:di:compile` as best-effort (`|| true`) —
  if it fails, jump into `make sandbox-shell` and rerun it manually with
  `php -d memory_limit=4G bin/magento setup:di:compile`.
- **Symlink + Windows native.** `ln -s` doesn't behave correctly under native
  Windows without developer mode and even then Docker Desktop sometimes
  refuses to follow the link into the container. Use WSL2.
- **PHP pinning.** As of mid-2026 Shust no longer ships `bin/setup-php`; the
  Makefile pins PHP by sed-ing the `phpfpm` image tag in `.sandbox/compose.yaml`
  after `lib/template` lands it (line shaped like
  `image: markoshust/magento-php:8.3-fpm-4`). If the sed misses (e.g. Shust
  renames the image), edit `compose.yaml` manually before `make sandbox` runs
  `bin/download`/`bin/setup`.
- **Tracking upstream `master`.** `lib/template` is curl-piped from
  `markshust/docker-magento@master`, so a Shust regression can break
  `make sandbox` overnight. If you hit one, pin to a known-good revision by
  swapping `SHUST_TEMPLATE_URL` in the Makefile to a specific commit SHA.

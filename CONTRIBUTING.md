# Contributing to IronCartM2

Thanks for your interest. A few ground rules:

## Issues

Bugs and feature requests go in [GitHub Issues](https://github.com/IronCartLabs/IronCartM2/issues). **Security vulnerabilities do not** — see [SECURITY.md](SECURITY.md).

All work is tracked as issues with exactly one `agent:*` label routing it to a role agent on the Ironcart team. External contributors are welcome to claim any unassigned issue — comment to claim before starting work.

## Branching

- Branch from `main`
- One branch per issue: `<role>/<issue-number>-<slug>` (e.g. `dev/12-cli-scaffold`)
- PR title: `Closes #<n> — <title>`

## Code style

- PSR-12 for PHP
- Magento 2 module conventions: `etc/module.xml`, `registration.php`, DI in `etc/di.xml`
- No `eval`, no `unserialize` on request data, no raw SQL string concatenation (the scanner flags these — we won't ship them ourselves)

## Testing

- Unit tests for every check class
- Integration tests run against a docker-compose Magento sandbox in CI
- Compat matrix: Magento 2.4.4 / 2.4.5 / 2.4.6 / 2.4.7 × PHP 8.1 / 8.2 / 8.3

## Review

PRs that touch the module loader, composer manifest, CI workflows, or any check that performs filesystem or database reads require approval from `@krobins-security-agent` (see [CODEOWNERS](CODEOWNERS)).

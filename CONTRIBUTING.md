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

## Translations

The module ships under `i18n/<locale>.csv`:

- `en_US.csv` — source catalog (built and validated by `bin/check-i18n.php`; CI fails if any `__()` or `translate=` phrase is missing a row).
- `de_DE.csv`, `fr_FR.csv`, `es_ES.csv`, `nl_NL.csv` — **machine-translated stubs**. Useful baseline, not native-quality. Native speakers are explicitly invited to refine these (see "Improving a translation" below).

See [docs/i18n.md](docs/i18n.md) for the CSV format, the build-time checker, and how Magento's collector ingests `__()` calls and `translate=…` XML attributes.

### Adding or editing a translatable phrase

1. Wrap user-facing CLI / admin UI strings in `__('…')` (PHP) or `<label translate="true">…</label>` / `translate="title"` / `translate="comment"` (XML).
   - The phrase **must be a single string literal**, not a concatenation. Both Magento's `bin/magento i18n:collect-phrases` and our `bin/check-i18n.php` extract only the first `T_CONSTANT_ENCAPSED_STRING` token after `__(` — `__('foo ' . 'bar')` would silently lose `'bar'`.
   - Use `%1`, `%2` for positional placeholders (`__('Report written to %1', $path)`); never `printf` outside the helper.
2. Add the row to `i18n/en_US.csv` (`"<source>","<source>"`). Keep the file in rough alphabetical order — diffs stay minimal.
3. Add the matching row to each translated locale. Machine translation is acceptable for the initial check-in; mark the PR description with "machine-translated, needs native review" so a future contributor can pick it up.
4. Run `php bin/check-i18n.php` to confirm en_US covers every source phrase.
5. Run the placeholder parity tests:
   ```
   phpunit --filter I18nPlaceholderParity
   ```
   These guard against translators dropping a `%1` or doubling a placeholder — a broken sprintf is worse than an English fallback.

### Improving a translation

1. Open the target `i18n/<locale>.csv` (e.g. `i18n/de_DE.csv`).
2. Edit the **second** column of any row whose translation reads awkwardly or technically wrong. **Never** edit the first column — it is the join key with `en_US.csv`.
3. Preserve every `%N` placeholder verbatim. They are substituted at render time; renaming or reordering them is fine, but each must appear in the target exactly as in the source.
4. Keep technical terms untranslated: `SKU`, `cron`, `Magento`, `Composer`, `bin/magento`, file paths (`var/log/…`, `app/etc/env.php`), severity codes (`critical`, `high`, `medium`, `low`, `info`), HTML tags, code identifiers (`ironcart_scan_upload_cron`), and URLs.
5. Run `phpunit --filter I18nPlaceholderParity` to confirm placeholder integrity before opening a PR.

Translation PRs do not need `agent:security` review — `i18n/` is not in CODEOWNERS. They land via the normal `agent:dev` flow.

### Adding a new locale

1. Copy `i18n/en_US.csv` to `i18n/<locale>.csv` (e.g. `i18n/it_IT.csv`).
2. Translate the second column row by row, observing the rules above.
3. Add the locale to the README "Translations" section.
4. The `I18nPlaceholderParityTest` will automatically pick up the new CSV — no test edit needed.

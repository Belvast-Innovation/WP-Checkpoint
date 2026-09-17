# Contributing to WP Checkpoint

Thanks for your interest in improving WP Checkpoint! This document explains how to report problems, propose changes and submit code.

## Where to go

| You want to… | Please use |
| --- | --- |
| Get help using the plugin | The [WordPress.org support forum](https://wordpress.org/support/plugin/wp-checkpoint/) |
| Report a bug | [GitHub issues](../../issues/new/choose) → "Bug report" |
| Suggest a feature | [GitHub issues](../../issues/new/choose) → "Feature request" |
| Report a security vulnerability | **Privately**, see [SECURITY.md](SECURITY.md) |

Support questions opened as GitHub issues will be closed with a pointer to the forum. This keeps the issue tracker useful for development.

## Ground rules

- Be respectful and constructive.
- Backups are critical infrastructure for our users. Changes that touch exporting, restoring, the archive format or database handling get extra scrutiny and must come with tests.
- The free plugin never contains locked or "trial" functionality. Paid features live in a separate extension and only hook into public actions and filters.
- Discuss larger changes in an issue before writing code, so nobody wastes effort.

## Development setup

Requirements: PHP 7.4+, Composer 2, Node.js 20+, Docker.

The repository folder must be named `wp-checkpoint` (wp-env mounts it under that name).

```bash
git clone https://github.com/Belvast-Innovation/WP-Checkpoint.git wp-checkpoint
cd wp-checkpoint
composer install
npm install
npx wp-env start          # http://localhost:8888 (admin / password)
```

## Before opening a pull request

Run all checks locally:

```bash
composer lint             # WordPress Coding Standards + PHP compatibility (7.4+)
composer analyse          # PHPStan
composer test:unit
npm run test:integration
npm run check:plugin      # Plugin Check on the distributable tree (needs wp-env running)
```

Please make sure that:

- [ ] Code runs on PHP 7.4 (no `match`, enums, readonly, named arguments, nullsafe operator, constructor promotion or union types)
- [ ] All globals use the `wpcheckpoint_` / `WPCHECKPOINT_` prefix or the `WPCheckpoint\` namespace
- [ ] Every new AJAX / REST / admin-post endpoint checks capabilities and a nonce through `WPCheckpoint\Support\Guard`; new REST routes get an example request in `tests/integration/RestPermissionsTest.php`
- [ ] All output is escaped and all user-facing strings are translatable (text domain `wp-checkpoint`)
- [ ] New behaviour is covered by tests; changes to the archive format are called out in the pull request description
- [ ] The pull request description explains *why*, not only *what*

## Commit messages

Use short, imperative subject lines. Reference the task or issue when there is one:

```
T031: stream table rows in primary-key ranges
Fix #42: keep admin session after restore
```

## Developer Certificate of Origin (sign-off required)

To make sure every contribution can be distributed under the project's license, all commits must be signed off. By adding a `Signed-off-by` line you certify the [Developer Certificate of Origin 1.1](https://developercertificate.org/): that you wrote the code or otherwise have the right to submit it under the GPL-2.0-or-later license.

Sign off automatically with:

```bash
git commit -s -m "Your message"
```

Pull requests with unsigned commits cannot be merged.

## License

By contributing, you agree that your contributions are licensed under the [GNU General Public License v2.0 or later](LICENSE).

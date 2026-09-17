# WP Checkpoint

Backup, migration and safe updates for WordPress. **Restores are always free, and every change can be undone.**

> 🚧 Early development — not ready for production use yet.

## What it does

- **Backup & migrate** complete sites of any size, in chunks that work on shared hosting.
- **Restore without limits** — no size caps, no paywall on restoring.
- **Open archive format** — standard zip/tar with a JSON manifest; you can restore by hand without the plugin.
- **Checkpoints before risky changes** — updates, search & replace and rollbacks can be undone.
- **Conflict troubleshooting** — find the plugin that breaks your site without affecting visitors.
- **Import from other backup plugins**, starting with `.wpress` archives.

## Documentation

Full documentation, including the open archive format specification, will be published on [wpcheckpoint.com](https://wpcheckpoint.com) before the first public release.

## Development

Requirements: PHP 7.4+, Composer 2, Node.js 20+, Docker. Clone into a folder named `wp-checkpoint` (wp-env mounts the plugin under that name):

```bash
git clone https://github.com/Belvast-Innovation/WP-Checkpoint.git wp-checkpoint
```

Then:

```bash
composer install
npm install
npx wp-env start          # http://localhost:8888 (admin / password)
composer lint
composer analyse
composer test:unit
npm run test:integration
```

See [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request.

## Security

Please report vulnerabilities privately — see [SECURITY.md](SECURITY.md).

## License

[GPL-2.0-or-later](LICENSE). WP Checkpoint is not affiliated with or endorsed by the WordPress Foundation or WordPress.org.

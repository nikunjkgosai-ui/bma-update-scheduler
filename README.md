# BMA Update Scheduler

BMA Update Scheduler provides manual and scheduled WordPress plugin, theme, and core updates with an existing UpdraftPlus backup safety gate, update history, post-update health checks, locking, and optional email alerts.

This is the private edition. It supports update packages supplied by installed plugins and themes, including vendor-hosted premium updates. It is not intended for submission to the WordPress.org Plugin Directory.

## Requirements

- WordPress 6.3 or newer
- PHP 7.4 or newer
- MySQL or MariaDB advisory-lock support
- An active UpdraftPlus installation with a qualifying existing backup

## Installation

1. Download or package the `bma-update-scheduler` directory as a ZIP archive.
2. In WordPress, open **Plugins > Add New > Upload Plugin**.
3. Upload and activate the plugin.
4. Open **Tools > BMA Update Scheduler**.
5. Review the backup gate and click **Save Schedule** with an authorized administrator account.

If migrating from the Code Snippets version, disable the old BMA scheduler snippet before activating this plugin. Existing settings and update history use the same WordPress option names and are retained.

## Backup policy

BMA does not create backups. Before manual or scheduled updates, it requires active UpdraftPlus and checks the latest existing backup for successful completion, acceptable age, and expected components. The default maximum age is 30 days.

Remote backup availability and restorability still require independent restore testing.

## License

GPL v2 or later. See [LICENSE.txt](LICENSE.txt).

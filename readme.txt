=== BMA Update Scheduler ===
Tags: updates, scheduled updates, backup, maintenance
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Manage manual and scheduled updates with existing-backup checks, update history and post-update health reporting.

== Description ==

BMA Update Scheduler manages installed plugin, theme and WordPress core updates using WordPress's native update APIs.

* Manual and scheduled updates with individual component results.
* An existing UpdraftPlus backup safety gate; BMA never creates a backup.
* A configurable maximum backup age, defaulting to 30 days.
* Backup component records and local archive readability/size checks.
* Post-update health checks, history and optional email alerts.
* Update locking, permission checks and protection for BMA and Code Snippets.

All included functionality is available without a license key, subscription, paid unlock, trial period or usage quota. Backup and permission requirements are operational safety checks. The plugin does not automatically reactivate plugins or perform rollback.

An active UpdraftPlus installation is required for the backup safety gate. Install and configure it separately; BMA does not install it or purchase services. UpdraftPlus Premium is not required. This plugin is not affiliated with or endorsed by UpdraftPlus.

= Source and license =

All PHP, CSS and JavaScript source is included in this package. No compiler, external build service or obfuscated code is required. To package it, ZIP the bma-update-scheduler directory with that directory at the archive root. LICENSE.txt contains the GPL version 2 text; the plugin is licensed under GPL version 2 or any later version.

WordPress and UpdraftPlus implementations are not bundled. BMA calls their installed APIs. No third-party fonts, images, libraries or executable assets are shipped or fetched for the dashboard.

= External communication and privacy =

BMA uses WordPress's native update checks and upgraders. These contact WordPress.org and may use update providers registered by installed plugins/themes. Update requests may transmit the site URL, WordPress/PHP versions, installed component versions and provider credentials already configured by the administrator. Download requests retrieve the selected update packages. These communications are necessary for update functionality; BMA does not add analytics, tracking, advertising, a licensing server or an author-operated service.

This is a privately distributed edition, not a WordPress.org Plugin Directory submission. It retains vendor-hosted premium plugin and theme updates through WordPress's native updater. Valid vendor licenses or credentials may be required by those installed products; BMA itself adds no paid feature gates. No WordPress.org listing, review approval or SVN publication is claimed.

WordPress.org privacy policy: https://wordpress.org/about/privacy/
WordPress.org terms: https://wordpress.org/about/terms-of-service/
Other update providers are selected by the installed components, not BMA; consult those providers' privacy policies and terms before enabling their updates.

Email alerts are disabled until an administrator saves a notification email address. Alerts contain the site name/URL, operation time, component identifiers and update/health results. Delivery uses the site's configured WordPress mail transport, including any SMTP provider selected by the site owner. Leave the email field blank and save to disable alerts. BMA does not send these reports to its author.

Settings, scheduler owner ID, notification email, update history, health records and mail acceptance status are stored in the site's WordPress database. Notification dismissals use the administrator's browser localStorage. The plugin adds no links, credits, tracking or content to the public-facing site.

== Private Distribution ==

Distribute the ZIP directly to the sites you administer. No WordPress.org SVN repository is required for this edition. GPLv2-or-later licensing still applies; private distribution does not remove recipients' GPL rights. The Update URI identifies this private plugin and is not an automatic update feed. Install future BMA releases using WordPress's plugin upload/replace interface while no BMA update is running.

== Installation ==

1. Wait for any BMA update operation to finish.
2. Disable only the old BMA Update Scheduler snippet. Keep Code Snippets active if other snippets need it.
3. Upload bma-update-scheduler-1.0.2.zip through Plugins > Add New > Upload Plugin and activate.
4. Open Tools > BMA Update Scheduler. Existing BMA settings and history are reused.
5. Review the backup gate, schedule and email, then Save Schedule as an authorized administrator.

For multisite, disable conflicting BMA snippets on all affected sites before network activation. Settings and history are per site. New sites start with scheduling disabled. The update lock is shared for the installation's database/table prefix. Avoid concurrent schedules for shared plugins on multiple sites.

== Frequently Asked Questions ==

= How do I change the backup age? =

Add this before the stop-editing line in wp-config.php; the value is seconds:

define( 'BMA_US_MAX_BACKUP_AGE', 30 * 24 * 60 * 60 );

= Are remote backups guaranteed to restore? =

No. Remote archives rely on UpdraftPlus completion and component records. Local files are checked for readability and size. An independent restore test is still necessary to establish restorability.

= What happens on deactivation or deletion? =

Deactivation clears BMA scheduled events and obsolete pending backup jobs. Settings, history and all UpdraftPlus backups are retained. Deletion does not purge those records. To return to the TXT snippet, deactivate this plugin, enable the snippet and save its schedule again.

= Can BMA update itself? =

BMA excludes itself and Code Snippets from its own update batches. Use the normal WordPress plugin installation/update interface for BMA while no operation is running. The private edition has no remote self-update feed.

= What infrastructure is required? =

A consistent MySQL/MariaDB connection supporting advisory locks, WordPress filesystem access and active UpdraftPlus with a qualifying backup. WP-Cron needs traffic or a server cron trigger. Email delivery depends on the site's mail configuration.

== Changelog ==

= 1.0.3 =
* Removed the private Update URI header for compatibility with WordPress Plugin Check.
* Premium plugin and theme update support is unchanged.

= 1.0.2 =
* Explicit sanitization of submitted email and component selections.
* User-bound nonce verification for redirected administrator notices.
* Documented necessary uncached database-lock query exceptions.
* Added Tested up to: 7.1 for the owner-reported deployment.
* Retained the private Update URI intentionally; WordPress.org-only Plugin Check rules do not apply to this distribution.

= 1.0.1 =
* Added GPLv2-or-later declarations and the complete GPLv2 license text.
* Expanded source formatting for human review.
* Added dependency, source, privacy and external communication documentation.
* No changes to update behavior.

= 1.0.0 =
* Initial standalone package of the BMA Update Scheduler snippet.

== Upgrade Notice ==

= 1.0.1 =
Licensing, documentation and source-formatting update. Existing settings/history are retained.

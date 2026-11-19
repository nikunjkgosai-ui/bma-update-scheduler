# BMA Update Scheduler Release Process

Use this checklist for every BMA Update Scheduler release.

## Canonical working folder

Work only from:

`C:\Users\nikun\Downloads\Plugins made by Me\BMA Update Scheduler\BMA Update Scheduler\bma-update-scheduler`

Do not use temporary clones or older backup folders as the main working copy.

## Before creating a release

1. Start from an up-to-date `main` branch.
2. Create a dedicated branch for the release changes.
3. Update the version in `bma-update-scheduler.php`.
4. Update `Stable tag` and the changelog in `readme.txt`.
5. Keep the plugin folder name exactly `bma-update-scheduler`.
6. Run PHP syntax checks on every PHP file.
7. Run all scheduling regression tests.
8. Confirm the GitHub Actions PHP checks pass on PHP 7.4 and PHP 8.4.
9. Merge through a pull request after all required checks pass.

## Build the installable ZIP

The ZIP must be named:

`bma-update-scheduler-VERSION.zip`

The archive must contain one top-level folder:

`bma-update-scheduler/`

That folder must contain `bma-update-scheduler.php` directly. Exclude development and repository files, including:

- `.git/`
- `.github/`
- `tests/`
- temporary build directories
- previous ZIP packages

Inspect the ZIP contents before publishing. Verify its SHA-256 checksum and include that checksum in the release notes.

## Publish on GitHub

1. Create the tag `vVERSION` from the merged `main` commit.
2. Use the title `BMA Update Scheduler VERSION`.
3. Add a concise summary and change list.
4. Attach the verified installable ZIP, not GitHub's automatically generated source archive.
5. Mark the release as **Latest**.
6. Confirm the tag, commit, ZIP filename, file size, and SHA-256 checksum before publishing.
7. After publishing, verify that the ZIP appears under **Assets** and that its displayed checksum matches the local checksum.

## v1.0.16 workflow failure lesson

The v1.0.16 release was published successfully, but the redundant release workflow failed at **Validate requested version**.

Cause:

- Publishing tag `v1.0.16` triggered `.github/workflows/release.yml`.
- A tag-triggered run has no `workflow_dispatch` version input.
- The workflow used the hard-coded fallback `1.0.15`.
- It compared `1.0.15` with the plugin's actual `1.0.16` version and exited with code 1.
- The Node.js 20 deprecation message was only a warning and was not the failure cause.

Required workflow correction:

1. Make the release workflow manual-only unless a fully tested tag-release design is intentionally introduced.
2. Remove the hard-coded `1.0.15` fallback.
3. Require and validate the manual version input.
4. Upgrade `actions/checkout` to a currently supported major version.
5. Ensure workflow-built packages exclude tests, build folders, old ZIP files, `.git`, and `.github`.
6. Never rerun the failed v1.0.16 release job without correcting the workflow; it will fail for the same reason.

The failed workflow did not invalidate the published v1.0.16 release or its manually verified ZIP.

## Final checks

- Confirm installed sites detect the new version.
- Confirm **View details** displays the release information.
- Test an update on a staging site before broad deployment.
- Confirm the plugin directory remains `bma-update-scheduler` after updating.
- Confirm the plugin remains active after updating.
- Keep backup protection, update locking, health checks, and reporting unchanged unless the release intentionally modifies them.

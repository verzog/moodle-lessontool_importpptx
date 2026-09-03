# Releasing

This plugin is published on the [Moodle plugins directory](https://moodle.org/plugins/),
which resolves each published version to a **git tag**. The directory does not
track `main`, so a release only exists once a tag for it exists. Bumping the
version without tagging leaves `main` ahead of every published version — the
marketplace keeps offering the older, still-tagged code.

**The rule: bump the version and cut the tag in the same step.** Never let one
happen without the other.

## Versioning

Two fields in `version.php` move together on every release:

- `$plugin->version` — the integer build stamp, `YYYYMMDDXX` (date plus a
  two-digit counter for same-day builds). Moodle uses this to decide when to
  upgrade, so it must strictly increase.
- `$plugin->release` — the human-readable semver-ish string (e.g. `1.12.3`).
  Bump the patch for bugfixes, the minor for backward-compatible features.

The tag name is `v` followed by the release string: release `1.12.3` is tagged
`v1.12.3`.

## Steps

1. Make sure `main` is green and holds exactly the code you intend to ship.
2. Update **both** fields in `version.php` (raise `$plugin->version`, set
   `$plugin->release`).
3. Add a section for the new release at the top of `CHANGELOG.md`.
4. Commit those together, e.g. `Release 1.12.3`, and push `main`.
5. Cut the tag — either via a GitHub Release (recommended, below) or on the
   command line:

   ```bash
   git tag -a v1.12.3 -m "v1.12.3"
   git push origin v1.12.3
   ```

6. On the plugin's moodle.org page, add a new version pointing at the new tag
   (or upload a ZIP of that tag). A bugfix update to an already-approved plugin
   goes through a lightweight review, not the full initial-approval process.

## Cutting the tag via a GitHub Release (recommended)

The simplest way to create and push the tag is to publish a GitHub Release,
which cuts the tag from the target branch for you — no local tag push needed:

1. Go to **Releases → Draft a new release**
   (`https://github.com/verzog/moodle-lessontool_importpptx/releases/new`).
2. **Choose a tag** → type the new tag (e.g. `v1.12.3`). It will read
   *"will be created from the target when you publish this release."*
3. **Target** → `main` (the commit whose `version.php` holds this release).
4. Set the **title** to the tag name and click **Generate release notes** to
   pull in the merged changes since the last tag.
5. Leave the label on **Latest** for a production release.
6. **Publish release.** This creates and pushes the tag in one step.

Then point the moodle.org version at that tag as in step 6 above.

## Checking for drift

To confirm the latest tag matches `main` before you publish:

```bash
git fetch origin --tags
git describe --tags --abbrev=0            # newest tag
git log "$(git describe --tags --abbrev=0)"..origin/main --oneline   # empty == in sync
```

If that last command prints commits, `main` has moved past the last tag and a
new release is due before publishing.

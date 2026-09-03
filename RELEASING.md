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
4. Commit those together, e.g. `Release 1.12.3`.
5. Tag that commit and push the tag:

   ```bash
   git tag -a v1.12.3 -m "v1.12.3"
   git push origin main
   git push origin v1.12.3
   ```

6. On the plugin's moodle.org page, add a new version pointing at the new tag
   (or upload a ZIP of that tag). A bugfix update to an already-approved plugin
   goes through a lightweight review, not the full initial-approval process.

## Checking for drift

To confirm the latest tag matches `main` before you publish:

```bash
git fetch origin --tags
git describe --tags --abbrev=0            # newest tag
git log "$(git describe --tags --abbrev=0)"..origin/main --oneline   # empty == in sync
```

If that last command prints commits, `main` has moved past the last tag and a
new release is due before publishing.

# Releasing

This plugin is published on the [Moodle plugins directory](https://moodle.org/plugins/),
which resolves each published version to a **git tag**. The directory does not
track `main`, so a release only exists once a tag for it exists. Bumping the
version without tagging leaves `main` ahead of every published version — the
marketplace keeps offering the older, still-tagged code.

**The rule: bump the version and cut the tag in the same step.** Never let one
happen without the other, and always tag a commit that CI has already passed.

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

1. On a branch, update **both** fields in `version.php` (raise
   `$plugin->version`, set `$plugin->release`) and add a section for the new
   release at the top of `CHANGELOG.md`. Commit them together, e.g.
   `Release 1.12.3`.
2. Open a pull request and let CI run. **Wait for it to pass**, then merge to
   `main`. This is what guarantees the exact commit you are about to tag has
   been validated — pushing straight to `main` only *starts* CI asynchronously,
   so a bad `version.php` could otherwise become an immutable release tag before
   the failure is known.
3. Cut the tag **from the merged `main` commit** — via a GitHub Release
   (recommended, below) or on the command line. Because that commit is already
   on `origin`, you only push the tag; there is no paired `main`+tag push that
   could half-fail:

   ```bash
   git fetch origin main
   git tag -a v1.12.3 origin/main -m "v1.12.3"
   git push origin v1.12.3
   git ls-remote --tags origin v1.12.3   # confirm the tag is really on the remote
   ```

   (If your workflow ever does push a branch and its tag together, use
   `git push --atomic origin main v1.12.3` so a failure cannot publish only
   half of the release.)
4. On the plugin's moodle.org page, add a new version pointing at the new tag
   (or upload a ZIP of that tag). A bugfix update to an already-approved plugin
   goes through a lightweight review, not the full initial-approval process.

## Cutting the tag via a GitHub Release (recommended)

The simplest way to create and push the tag is to publish a GitHub Release,
which cuts the tag from the target branch for you — no local tag push needed.
Do this only **after** the release commit has been merged to `main` (step 2),
so the tag lands on already-validated code:

1. Go to **Releases → Draft a new release**
   (`https://github.com/verzog/moodle-local_lessonimportpptx/releases/new`).
2. **Choose a tag** → type the new tag (e.g. `v1.12.3`). It will read
   *"will be created from the target when you publish this release."*
3. **Target** → `main` (which now holds the merged, CI-passed release commit).
4. Set the **title** to the tag name and click **Generate release notes** to
   pull in the merged changes since the last tag.
5. Leave the label on **Latest** for a production release.
6. **Publish release.** This creates and pushes the tag in one step.

Then point the moodle.org version at that tag as in step 4 above.

## Checking for drift

To confirm the newest tag actually matches `origin/main` — and exists on the
remote, not just locally — before you publish:

```bash
git fetch origin --prune 'refs/tags/*:refs/tags/*'   # refresh remote tags
latest=$(git describe --tags --abbrev=0 origin/main) # newest tag reachable from origin/main
git ls-remote --tags origin "$latest"                # confirm that tag is on the remote
git log "$latest"..origin/main --oneline             # empty == origin/main is tagged
```

Anchor the check on `origin/main`, not your local `HEAD`: a stale local `main`,
a release branch, or a local-only tag can otherwise make the range falsely
report "in sync". If the last command prints commits, `origin/main` has moved
past the newest tag and a new release is due before publishing.

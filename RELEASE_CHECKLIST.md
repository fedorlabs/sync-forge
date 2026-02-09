# Release Checklist

Use this checklist before publishing a beta or stable release.

## 1. Branch and Scope

- [ ] Release branch is up to date with `main`.
- [ ] Changelog entries are updated and reviewed.
- [ ] No unrelated changes are included.

## 2. Quality Gates

- [ ] `composer cs-check`
- [ ] `composer analyse`
- [ ] `composer test`
- [ ] CI is green on GitHub Actions.
- [ ] PostgreSQL integration job is green.
- [ ] MySQL integration job is green.

## 3. Docs and Notes

- [ ] `README.md` reflects current API behavior.
- [ ] `CHANGELOG.md` has a release section.
- [ ] `ROADMAP.md` is aligned with next milestones.
- [ ] Release notes drafted from `.github/RELEASE_TEMPLATE.md`.

## 4. Publish

- [ ] Merge PR into `main`.
- [ ] Create git tag (example: `v0.1.0-beta.1`).
- [ ] Publish GitHub Release.
- [ ] Verify package metadata (if publishing to Packagist).

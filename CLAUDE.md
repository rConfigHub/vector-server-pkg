# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**rConfig Vector Server (`vector-server-pkg`)** is the server side Laravel package that rConfig V8 Pro installs to manage data sync with the rConfig Vector agent software. It is published as the Composer package `rconfighub/vector-server-pkg` and consumed by the main V8 Pro application, not run standalone.

It provides the agent queue and job lifecycle, agent task run tracking, agent provisioning and authentication (IP allow ranges and tokens), agent logs, and the console commands that reconcile stale agent jobs.

### Layout

- `src/` is the package root, PSR-4 mapped to `Rconfig\VectorServer\`. `src/Http/Controllers/`, `src/Models/`, `src/Services/`, `src/Console/Commands/`.
- `config/vector-server.php` is the published config, including the agent job and watchdog timeouts.
- `routes/`, `database/`, and `resources/` carry the package routes, migrations, and views.
- The service provider is `Rconfig\VectorServer\VectorServerServiceProvider`, auto discovered through `extra.laravel.providers`.

### Conventions

- Follow the host application's conventions. This package references `App\Models\Device` and `App\Services\Notifications\AgentDeviceFailureNotifier` from V8 Pro, so changes here can break against a Pro version that does not have them. Keep new cross package references guarded where the host may not provide them, as `RunTrackerService::tablesAvailable()` does.
- Laravel Pint style: `! $foo` rather than `!$foo`, imported class names rather than leading slash FQCNs, a blank line before a return.
- Do not use em dashes in body copy or documentation. Use a period, comma, parentheses, or colon instead.

## Releases and tagging

The canonical step by step commands live in [README.md](README.md), which doubles as the release runbook and is itself rewritten for each version. This section is the process and the rules that the commands do not capture.

- **Release tags are bare and `v` prefixed**, for example `v1.3.2`. Use annotated tags: `git tag -a v1.3.2 -m "Release version v1.3.2"`. Note this differs from `v8core`, whose tags are prefixed `core-`.
- **The version lives in three places and they must all be updated together**, before tagging:
  1. `composer.json`, the `version` field, `v` prefixed (`"version": "v1.3.2"`).
  2. `CHANGELOG`, a new dated `## [1.3.2] – YYYY-MM-DD` section at the top, no `v` prefix in the heading. Note the file is `CHANGELOG` with no extension.
  3. `README.md`, every occurrence of the previous version. The README is the release runbook, so bumping it is what leaves the next person a correct set of commands to copy.
- Work on a `release/vX.Y.Z` branch, commit as `Prepare release vX.Y.Z`, push the branch, then merge to `main` and push `main` before tagging. Tag the merge commit on `main`, never the release branch tip.
- **Do not open the release PR until the release commit exists on the branch.** PR #26 for v1.3.2 was created and merged while `release/v1.3.2` was still level with `main`, so it merged nothing and left `main` claiming v1.3.1 after the release was believed done. Check `git log main..release/vX.Y.Z` is non empty before merging.
- **A release is not finished when the tag is pushed. It needs a GitHub Release too.** Every tag from `v1.1.0` through `v1.3.1` was pushed without one, because this step was undocumented and there is no workflow in this repo to automate it. Create it by hand: `gh release create vX.Y.Z --title "rConfig Vector Server vX.Y.Z" --notes-file <file> --latest --verify-tag`.
- **Release notes are not the CHANGELOG entry.** The release body is condensed one line bullets grouped under the Keep a Changelog headings that apply, ending with `See CHANGELOG for detail.` Never paste the full CHANGELOG section: the CHANGELOG carries the detail, the release body is the summary. Lead with any action the operator must take, for example a config value to set after upgrading.
- Never move or re-point a tag that has already been pushed. If a published tag is wrong, leave it and correct it in the next release. The rollback recipe at the end of the README applies only to a tag you have just pushed and nobody has consumed.
- After tagging, the package is picked up from the Git tag by Composer. Update the host application with `composer require rconfighub/vector-server-pkg:vX.Y.Z`, then `php artisan rconfig:clear-all`.

### The full release sequence

1. `git checkout -b release/vX.Y.Z`
2. Bump the three version locations above, dating the CHANGELOG entry.
3. `git commit -m "Prepare release vX.Y.Z"` and push the branch.
4. Confirm `git log main..release/vX.Y.Z` is non empty, then merge to `main` and push `main`.
5. `git tag -a vX.Y.Z -m "Release version vX.Y.Z"` on the `main` merge commit, and push the tag.
6. `gh release create vX.Y.Z ... --latest --verify-tag` with condensed notes.
7. Update V8 Pro with `composer require rconfighub/vector-server-pkg:vX.Y.Z`, then `composer clear-cache && composer update`, then `php artisan rconfig:clear-all`.
8. Confirm the new version is present in [Repman](https://app.repman.io/login), which is the private registry V8 Pro installs this package from. A tag that Repman has not picked up will not install.

# Implementation Log: #407 — Add concurrency support to CI workflow

## Issue Context
Issue originally requested adding a `concurrency` block to `ci.yml`. Claude action found `concurrency` already exists in `ci.yml`. The latest comments from TomMurrayAlz clarified the actual fix: create a new workflow `.github/workflows/cancel-ci-on-merge.yml` using `styfle/cancel-workflow-action` to cancel in-progress CI runs when a PR is closed/merged.

## Implementation

### Created `.github/workflows/cancel-ci-on-merge.yml`
- Triggers on `pull_request` `closed` events targeting `main` or `develop`
- Uses `styfle/cancel-workflow-action@0.13.1` with `ignore_sha: true` to cancel all in-progress `ci.yml` runs
- `permissions: actions: write` required for the action to cancel runs via the GitHub API
- `runs-on: ubuntu-24.04` consistent with existing `ci.yml` jobs

## Test Results

N/A — change is a GitHub Actions YAML workflow file only; no PHP code modified.

## Lint Results

N/A — no PHP code modified. YAML workflow files are not covered by project linters (Pint/PHPStan/PHPArkitect/Deptrac/TLint).

## Handoff Notes

- Single file created: `.github/workflows/cancel-ci-on-merge.yml`
- Exact YAML provided by TomMurrayAlz in issue comments, with `ubuntu-24.04` runner matching the project standard
- No PHP changes; no tests or linting needed
- The existing `concurrency` block in `ci.yml` handles in-PR cancellation; this new workflow handles cleanup at PR close/merge
- No concerns; straightforward implementation

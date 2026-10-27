# Triage rubric — alz-core

## Category notes

- Dependency upgrades blocked on upstream releases are `chore`, maturity `deferred`.
- Linter, CI and git-hook changes are `chore` (this repo has no `sdlc` tier).
- A batch of mixed fixes takes the category of its majority; a few behaviour-visible fixes inside a mostly-refactor batch do not make it a `minor_bug`.

## SDLC surfaces

None — the `product` profile has no `sdlc` tier; classify tooling work as `chore`.

## Cost

Cost is recurring spend on a production path: Railway compute, queue throughput, and paid external API calls (ShopWired, Linnworks, Google Ads, Twilio, HelpScout). `moderate` or `high` only for a saving on a job, schedule or endpoint that runs at least daily; local developer speed-ups are `low`.

A cost counts only when the issue's own deliverable delivers the saving; measuring, evaluating or enabling a later saving is `low` or `none`. `moderate` raises the rubric tier one step and `high` two, never past High.

## Overrides

Line format: `~/.agents/skills/triage/SKILL.md` §Judgement.

Only projects listed as `release` lines count as release features; parked projects (e.g. Product Image Optimiser) are deliberately absent.

```triage-overrides
release|Call Tracking
```

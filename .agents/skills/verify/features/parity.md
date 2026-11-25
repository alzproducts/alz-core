# Response parity

A client gets the same response from the change as from the commit the change was built on.

## Sub-features

- `parity-baseline` boots a base run, built from an ancestor commit, beside the head run.
- `parity-compare` sends the same GET requests to both runs and compares status code and body.

## How to get to it (user POV)

- A response parity criterion carries the `runtime` proof tag and names:
  - each request, with its method and path;
  - any setup run on both sides;
  - the seed;
  - the persona;
  - the fields compared;
  - the fields ignored.
- A criterion that leaves one of these out cannot be proven from its text: report it as under-specified, never fill the gap. Never use a capture or harness the implementor wrote.

## Driving it with verify

Preconditions:

- `up` printed `base_url=` and `doctor` exits 0 on the head run.
- No journey has changed the head run's data yet: run `parity` before any that does.
- The criterion's `--ref`:

  | Case | `--ref` |
  |---|---|
  | A PR on trunk | none |
  | A stacked PR | the merge base of HEAD and the PR's base branch |
  | After the merge | the change's parent; later commits on trunk are then part of the comparison |

- **Base run.** Run `.agents/skills/verify/verify baseline --evidence <dir> [--ref <SHA>]`. Exit 0, with `baseline.sha=`, `baseline.evidence=`, `baseline.entry=` and `baseline.cwd=`. Exit 75 blocks the criterion, never fails it.
- **Setup on both sides.** Run each setup command on the head run, then with the `baseline.cwd=` value as cwd: `<baseline.entry> exec --evidence <baseline.evidence> -- <argv>`.
- **Compare.** Run `.agents/skills/verify/verify parity --evidence <dir> [--ignore <field path>]... [--expect <code>] GET <path> [GET <path>]...`. Exit 0 and one `match` line per request.
- **Proof.** The `parity/` verdict, the `http/` captures of both runs, and `baseline.sha=`.

## Gotchas

- `--ignore` takes only the fields the criterion names, e.g. `.data[].updated_at`; `[]` is every element. An ignored object or array is ignored whole.
- Array order, number form (`1` against `1.0`) and null against an absent field are differences. Object key order is not. A body that is not JSON is compared byte for byte, and headers are not compared.
- App classes the seeders use are not covered by the seed check, so a change to one can show up as a difference in seeded data.
- Some GET routes queue vendor syncs, such as `GET /api/products/{id}/custom-fields` on stale data. They are not driven unless `up` blanked the vendor keys with `--env-override KEY=`; the base run copies those overrides.
- A persona other than the bypass user, or a write, is not driven: `parity` sends GET requests as the X-Local-Bypass user only.

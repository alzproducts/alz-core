---
paths:
  - "app/Application/**/Commands/*Command.php"
---

# Application — Command Rules

## Partial-Update / Merge-Patch Commands

Apply when the command represents a JSON Merge Patch (RFC 7396) write — body fields are individually optional and `null` means *clear the column*.

- DO model the change set with two maps: `array<value-of<{Field}Enum>, scalar> $valuesToSet` keyed by DB column name, and `list<{Field}Enum> $columnsToClear`. Three states (untouched / set / clear) → three structural positions; no `null` overloading.
- DO add a field enum per write-target table in `app/Domain/{Context}/{Aggregate}/Enums/` — backing values are DB column names; each case exposes `isClearable(): bool` reflecting per-column NOT NULL constraints.
- DO enforce two constructor invariants with `Webmozart\Assert\Assert`: (1) no column appears in both maps; (2) every case in `$columnsToClear` is clearable.
- DO NOT use per-property nullable fields plus a `list<string> $touchedKeys` companion. **Why:** that shape leaks DB column names across layers and overloads `null` (untouched vs. cleared).

Canonical: `SaveCustomFieldGeneralSettingsCommand` + `CustomFieldGeneralSettingsField`.

### DTO `toCommand()`

- DO build the two accumulators in one call: `[$valuesToSet, $columnsToClear] = MergePatchMapper::buildMaps([[FieldEnum::Case, $this->property], …])`. Canonical: `App\Presentation\Http\Api\Support\MergePatchMapper`.

### Repository `save()`

- DO spread both maps into `EloquentGateway::upsertOne` attributes — `$command->valuesToSet` directly, `$command->columnsToClear` via `array_fill_keys(array_map(static fn ({Field}Enum $c): string => $c->value, …), null)`. **Why:** field enum backing values are already DB column names; no translation table needed.

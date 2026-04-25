---
paths:
  - "app/Domain/**/*ValueObject*.php"
  - "app/Domain/**/ValueObjects/*.php"
---

# Domain — Value Object Rules

- DO NOT add `toArray()`, `toJson()`, or any serialisation method that commits to a wire format: snake_case keys, stringified enums (`->value` in the return), formatted dates (`->format(...)`), or encoding booleans as non-native types.
- DO return native PHP types from Domain methods: `DateTimeImmutable`, enum instances, `int`, `string`, `bool`, `null`, arrays of native types.
- DO add typed accessors (`name()`, `label()`, `type()`, `rawValue()`) for individual properties — consumers decide how to format them.
- EXCEPTION: a structural `toArray()` that returns native types only (no wire conventions) and is used exclusively for internal Domain/Application purposes is acceptable. Document the use case with a comment.
- Canonical violation (removed): `AbstractCustomFieldValue::toArray()` — used snake_case keys, stringified enum via `->value`, formatted `DateTimeImmutable` as ATOM in the override.
- Canonical correct: `CustomFieldValueResource::toArray(Request $request)` in Presentation owns all wire-format decisions.

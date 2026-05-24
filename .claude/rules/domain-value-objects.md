---
paths:
  - "app/Domain/**/*ValueObject*.php"
  - "app/Domain/**/ValueObjects/*.php"
---

# Domain — Value Object Rules

- DO declare the class `final readonly` unless it contains a property hook; in that case, mark each non-hooked property `readonly` individually.
- DO route all named constructors and `from*` factories through the private `__construct` that carries `Assert::*` guards. DO NOT add a "trusted" or "raw" construction path — any path that bypasses the guard makes the invariant unenforceable.
- DO NOT add instance methods that imply the object might not be safe (`isValid()`, `isUsable()`, `check()`). Successfully-constructed VOs are always safe; methods that re-ask the question undermine that.
- DO use domain types over primitives in constructor parameters when a domain VO exists for the concept (`Money` not `float`, `Sku` not `string`, `IntId` not `int`). Canonical table: `app/Domain/CLAUDE.md`.
- DO NOT add `toArray()`, `toJson()`, or any serialisation method that commits to a wire format: snake_case keys, stringified enums (`->value` in the return), formatted dates (`->format(...)`), or encoding booleans as non-native types.
- DO return native PHP types, other Domain VOs, or Domain enums from Domain methods — not wire-format-committed types.
- DO add typed accessors (`name()`, `label()`, `type()`, `rawValue()`) for individual properties — consumers decide how to format them.
- EXCEPTION: a structural `toArray()` that returns native types only (no wire conventions) and is used exclusively for internal Domain/Application purposes is acceptable. Document the use case with a comment.
- Canonical: `CustomFieldValueResource::toArray(Request $request)` in Presentation owns all wire-format decisions.

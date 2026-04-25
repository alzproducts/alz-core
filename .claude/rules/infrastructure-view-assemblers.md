---
paths:
  - "app/Infrastructure/**/Mappers/*ViewAssembler.php"
---

# Infrastructure — View Assembler Rules

## Responsibility

- DO orchestrate include checks, relation guards, factory wiring, and conditional embed selection in the assembler. The assembler decides *what* to assemble.
- DO delegate VO construction to a source-model factory (`Model::buildXxx(...)`), a dedicated mapper, or a self-constructing VO that accepts primitives and builds its own internal domain types. The assembler should not call `new SomeValueObject(...)` directly with derived fields.
- DO NOT construct VOs field-by-field inside the assembler — wiring nested `new` calls couples the assembler to every leaf VO's constructor signature and duplicates type-conversion logic that belongs on the VO.

Canonical: `ProductViewAssembler` — class docstring states the convention ("the VO self-constructs domain types from primitives"); `toViewDomain()` passes Eloquent column primitives directly into `new ProductView(...)`.

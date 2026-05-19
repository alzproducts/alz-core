---
name: frontend-spec
description: Generate frontend specification from backend feature for ${FRONTEND_APP} consumption
argument-hint: "app/path/to/File.php {/api/endpoint GET description}"
model: opus
effort: medium
allowed-tools: mcp__phpstorm__*, mcp__intellij__*, mcp__sequential-thinking__sequentialthinking, Read, Grep, Glob, Agent, AskUserQuestion, Write
---

# Generate Frontend Specification

## Setup

This skill resolves `${FRONTEND_APP}` and `${FRONTEND_APP_PATH}` from the `env` block in `.claude/settings.local.json`. If those vars are missing, set them there before invoking — e.g. `FRONTEND_APP=my-frontend` and `FRONTEND_APP_PATH=/absolute/path/to/my-frontend`.

<backend_file>
$ARGUMENTS
</backend_file>

If the arguments above are empty, use AskUserQuestion to ask which file/feature to spec.

## Multiple Inputs

Arguments are space-separated. Each is **either** a file path **or** a `{curly-brace description}` for ad-hoc features (e.g. an endpoint not yet backed by a use case). `{...}` groups count as one argument regardless of internal spaces.

- **Single input** → standard single-feature spec (format below)
- **Multiple inputs** → one spec document, each input gets its own `## Feature: {Name}` section containing the full per-feature template (API Endpoints, Backend Source Files, etc.). Add a shared header above all sections:

```markdown
# Frontend Spec: {Overall Feature Name}
> {1-2 sentence description of the combined feature}
**Date:** {YYYY-MM-DD}
**Inputs:** `{list all inputs}`
```

Process each input independently through the same Read → Catalogue → Write pipeline.

## Goal

From the given backend file, produce a specification document in `${FRONTEND_APP_PATH}/.ai/specs/` that gives an LLM everything it needs to build the corresponding frontend feature. **Pointers to source files, not translations** — the consuming agent reads the backend files directly.

## Scope

**Stay in alz-core only.** Do not read or traverse ${FRONTEND_APP}. The spec is a backend-only artefact — the consuming agent handles frontend concerns itself.

**Do NOT include:** frontend file suggestions, recommended directory layouts, framework-specific patterns, or any advice about how to build the frontend. The consuming agent knows its own conventions.

## Process

Use JetBrains MCP tools and Explore agents in parallel for speed.

1. **Read & trace** — Read the input file. Trace to the HTTP layer: find the controller, route, request DTO/FormRequest, and response shape. If no HTTP endpoint exists, ask user how to proceed.
2. **Catalogue** — Collect all relevant backend files. Verify each exists. Note business rules and domain concepts that affect the frontend.
3. **Write spec** — Output to `${FRONTEND_APP_PATH}/.ai/specs/{feature-name}.md` (kebab-case). If the file already exists, ask before overwriting.

## Output Format

All paths in the document must be **relative** to the alz-core project root.

```markdown
# Frontend Spec: {Feature Name}

> {1-2 sentence description}

**Generated from:** `{backend file path relative to alz-core}`
**Date:** {YYYY-MM-DD}

Backend file paths are relative to `alz-core`. Use existing project instructions to locate the repository.

---

## API Endpoint(s)

| Method | Path | Success | Errors |
|---|---|---|---|
| ... | ... | ... | ... |

**Auth:** Supabase JWT (Bearer token)
**Content-Type:** `application/json`

## Backend Source Files

Read these for authoritative field names, types, and validation rules.

| File | What you'll find |
|---|---|
| `app/Presentation/...` | Request schema — field names, types, validation attributes |
| `app/Presentation/...` | Controller — request→use case mapping, response JSON shape |
| `app/Domain/...` | Business rule: ... |
| `routes/api.php` | Route definition, middleware |

## Request Shape Summary

> Read `{DTO class}` for the complete schema. Key points:

- {Brief bullet points — field names, required/optional, notable constraints}
- {Note any input mapping like SnakeCaseMapper}

## Response Shape Summary

> Read `{Controller::method()}` return statement for the complete shape.

- {Brief bullet points — field names and what they represent}

## Business Rules

- {Rules the frontend needs to know about — validation, constraints, conditional logic}
```

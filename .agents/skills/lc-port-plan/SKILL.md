---
name: lc-port-plan
argument-hint: "path/to/legacy-report.md"
description: Create a porting plan from a legacy feature report
allowed-tools: mcp__phpstorm__*, mcp__intellij__*, mcp__sequential-thinking__sequentialthinking, Read, Grep, Glob, Agent, AskUserQuestion, Write, Edit, Bash(gh *), Bash(git log *), Bash(git diff *)
model: opus
effort: high
---

# Port Legacy Feature to Clean Architecture

<report_path>
$ARGUMENTS
</report_path>

**The report path above is required.** If empty or the file does not exist, stop and ask for it.

## Core Principle

Extract WHAT the legacy system does, not HOW it does it. Legacy code quality is poor — every feature will be modernised, improved, and implemented using this project's Clean Architecture patterns. The legacy report is a business requirements source, not an implementation reference.

---

## Phase 1: Parse Report

Read the legacy report at the provided path. Extract every distinct feature, side-effect, and integration point into a flat list. Group by logical area (e.g., "ShopWired updates", "Linnworks EP updates", "Notifications", "Cron/scheduling").

---

## Phase 2: Scope Confirmation

Present ALL extracted features to the user as a **multi-select checklist** using AskUserQuestion. The user deselects features they do NOT want to port. Frame each item as a business capability, not an implementation detail.

Example framing:
- "Update product sale price on ShopWired" (not "Call PUT /products/{id} with salePrice payload")
- "Notify team when product added to sale" (not "Send Slack message via BotMan")

If any features are ambiguous or could be interpreted multiple ways, add a follow-up AskUserQuestion to clarify before proceeding. Keep this phase to 1-2 interactions maximum.

The confirmed feature list becomes the scope for all subsequent phases. Do not investigate or plan anything the user deselected.

---

## Phase 3: Codebase Investigation

For each system/integration in the confirmed scope, launch an **Agent subagent** (subagent_type: Explore) to investigate the current codebase. Run agents in parallel where possible.

Each agent should answer:
1. What infrastructure already exists for this system? (clients, DTOs, value objects, events, listeners)
2. What API endpoints/methods are currently used?
3. What patterns does existing code follow? (e.g., how are ShopWired product updates structured?)
4. What is missing that would need to be built?

Example agents for a pricing feature:
- Agent 1: "Investigate ShopWired product update infrastructure" — clients, DTOs, existing update methods
- Agent 2: "Investigate Linnworks EP update infrastructure" — clients, EP update patterns
- Agent 3: "Investigate notification/chat infrastructure" — ChatNotificationInterface, existing notifications
- Agent 4: "Investigate event/listener infrastructure" — event dispatch patterns, existing product events

Keep agent prompts focused. Each should return a concise summary (not raw code dumps).

---

## Phase 4: Collaborative Planning

### Step 4a: Present Investigation Summary

Before any planning discussions, present a structured summary of what the agents found:

**Exists and ready to use:**
- List each existing piece of infrastructure with its location

**Exists but needs extending:**
- List infrastructure that exists but needs new methods/capabilities

**Needs building from scratch:**
- List what must be created from scratch

Ask the user to confirm this assessment is accurate before continuing. They know the codebase — if something was missed, they'll flag it.

### Step 4b: Feature-by-Feature Planning

For each confirmed feature, use AskUserQuestion to collaboratively decide:

1. **Architecture** — Will this use the event system? Direct use case call? Job dispatch?
2. **Integration method** — Which API endpoints? Same as legacy or different/better approach?
3. **Data flow** — What data is needed? Where does it come from? What domain types apply?
4. **Error handling** — What failure modes exist? Retry strategy?

Present concrete options where possible. Reference existing codebase patterns by name. For example:
- "ShopWired product updates currently use `FieldUpdate` VOs via `ShopWiredProductClient::updateField()`. Should this new feature follow the same pattern, or does it need a different approach because [reason]?"

Do not ask about implementation details that follow naturally from established project patterns (layer placement, naming conventions, exception handling). Only surface decisions where there's a genuine choice to make.

---

## Phase 5: Write Requirements Document

Save to: `.ai/plans/{date}_port-{feature-name-kebab}.md`

Use format `YYYY-MM-DD` for date (e.g., `2026-03-23_port-price-update-side-effects.md`).

### Document Structure

```markdown
# Port: {Feature Name}

**Source report:** `.ai/reports/legacy/{report-filename}.md`
**Date:** {YYYY-MM-DD}
**Scope:** {1-line summary of what's being ported}

## Business Requirements

{Numbered list of confirmed features from Phase 2. Each item describes WHAT the system must do, not how.}

## Current Infrastructure

### Available
{What exists and can be reused — class names, patterns, locations}

### Needs Extending
{What exists but needs new methods/capabilities}

### Needs Building
{What must be created from scratch}

## Feature Specifications

### {Feature 1 Name}

**Requirement:** {What it must do}
**Architecture:** {Event-driven / direct use case / job dispatch — as decided in Phase 4}
**Integration:** {API endpoints, data sources, external services}
**Data flow:** {Input → transformation → output, domain types}
**Error handling:** {Failure modes, retry strategy}

{Repeat for each feature}

## Decisions Log

{Key decisions made during Phase 4 with brief rationale. These are MANDATED — implementation must follow them.}

## Proposed Implementation

{Suggested class names, method signatures, layer placement. These are GUIDANCE — the implementing LLM may adapt based on current codebase state, but should follow the spirit of the decisions above.}
```

### After Writing

1. Confirm the document was saved and show the path
2. Use AskUserQuestion to offer next steps:
   - "Create a GitHub issue linked to this plan" → run `/issue-with-plan` skill with the plan content
   - "Done for now" → end the command

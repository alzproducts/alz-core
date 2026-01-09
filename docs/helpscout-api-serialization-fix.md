# HelpScout API Serialization Fix - Implementation Requirements for alz-core

> **Purpose**: Document the API contract issues between alz-admin (frontend) and alz-core (Laravel backend) for the HelpScout dashboard tables. This document provides all context needed to implement fixes in alz-core.

---

## Problem Summary

All 4 HelpScout dashboard tables in alz-admin are broken:

- Escalations
- Your Conversations (assigned)
- To-Dos
- Negative Reviews

The tables display "Received invalid data from server" because Laravel's API responses fail Zod schema validation in alz-admin.

**Important Context**: These Zod schemas worked perfectly for months when alz-admin called the HelpScout API directly. The schemas accurately represent what HelpScout returns. The issue is that alz-core's serialization differs from the original HelpScout format.

---

## Exact Validation Errors

From alz-admin logs (`logs/next-dev-*.log`), the following Zod validation errors occur on all 4 endpoints:

### Date Serialization Errors

PHP's `DateTimeImmutable` objects serialize to `{date, timezone_type, timezone}` instead of ISO strings.

| Field Path                    | Expected            | Received                                     |
| ----------------------------- | ------------------- | -------------------------------------------- |
| `data[n].createdAt`           | `string` (ISO 8601) | `object` (`{date, timezone_type, timezone}`) |
| `data[n].userUpdatedAt`       | `string` (ISO 8601) | `object`                                     |
| `data[n].updatedAt`           | `string` (ISO 8601) | `null`                                       |
| `data[n].snooze.snoozedUntil` | `string` (ISO 8601) | `object`                                     |

### Structure Mismatch Errors

Domain objects have different structure than the expected API contract.

| Field Path                          | Expected | Received    | Cause                                                          |
| ----------------------------------- | -------- | ----------- | -------------------------------------------------------------- |
| `data[n].customerWaitingSince.time` | `string` | `undefined` | Domain flattens `{time, friendly}` to just `DateTimeImmutable` |
| `data[n].tags[n].tag`               | `string` | `undefined` | Domain uses `name` property, not `tag`                         |

### Field Name Mismatch Errors

Domain objects use different property names than the API contract.

| Domain Property                      | Expected API Property |
| ------------------------------------ | --------------------- |
| `ConversationTag.name`               | `tag`                 |
| `ConversationSnooze.snoozedByUserId` | `snoozedBy`           |
| `Conversation.customer`              | `primaryCustomer`     |

### Nullable Field Errors

Fields that can be null are being included as `null` rather than omitted.

| Field Path         | Expected           | Received |
| ------------------ | ------------------ | -------- |
| `data[n].assignee` | `object` or absent | `null`   |
| `data[n].snooze`   | `object` or absent | `null`   |

### Missing Fields

Fields that exist in HelpScout API but were discarded during Response → Domain transformation.

| Object                 | Missing Field             | Type                                           |
| ---------------------- | ------------------------- | ---------------------------------------------- |
| `ConversationSnooze`   | `unsnoozeOnCustomerReply` | `boolean`                                      |
| `customerWaitingSince` | `friendly`                | `string` (human-readable, e.g., "2 hours ago") |
| `ConversationAssignee` | `email`                   | `string`                                       |

---

## Root Cause Analysis

### Current Data Flow

```
HelpScout API → Response DTO → Domain Object → JsonResponse → alz-admin
                     ↓              ↓
              (correct format)  (transformed for
                                business logic)
```

### The Problem

1. **Response DTOs match HelpScout format** - `TagResponse` has `$tag`, `CustomerWaitingSinceResponse` has `$time` and `$friendly`

2. **Domain Objects are transformed** - `ConversationTag` uses `$name`, `Conversation.customerWaitingSince` is just `DateTimeImmutable`

3. **Controller returns Domain directly** - `JsonResponse(['data' => $domainObjects])` causes PHP to serialize Domain objects, not the original API format

4. **PHP serialization differs from API contract** - `DateTimeImmutable` becomes `{date, timezone_type, timezone}` instead of ISO string

### Why This Matters

The frontend Zod schemas are the **source of truth** for the API contract. They match what HelpScout returns. Laravel must output the same format.

---

## Architectural Decision: Keep Domain + Add API Resources

### Why Keep the Domain Layer

The Domain layer contains substantial business logic that justifies its existence:

1. **GetEscalationsUseCase** - Orchestrates 5 parallel queries across mailboxes, deduplicates results by conversation ID

2. **ConversationSorter.byStatusAndDate()** - Sorts conversations by status priority (active → pending → closed) then by update time

3. **ConversationSorter.byPriorityHierarchy()** - Complex tag-based priority sorting:
   - Priority-tagged conversations first
   - Assigned-tagged conversations second
   - Standard conversations last
   - Within each tier, oldest `customerWaitingSince` first

4. **Tag-based business rules** - Sorter iterates `$conversation->tags`, checks `$tag->name` against `EscalationsConfig` rules

This business logic requires Domain objects with clean APIs. Converting the sorter to work on Response DTOs would couple business logic to external API structure.

### Solution: API Resource Layer

Add Laravel API Resources to transform Domain → API contract:

```
HelpScout API → Response DTO → Domain Object → Business Logic → API Resource → JsonResponse
                                     ↓
                              (sorting, dedup,
                               priority rules)
```

---

## Decisions Made

### 1. Use Laravel API Resources

Laravel's built-in `JsonResource` classes will handle the transformation from Domain objects to API contract format.

### 2. Add Missing Fields to Domain Objects

Rather than trying to access Response DTOs at serialization time, add the missing fields to Domain objects:

- Add `friendly` string to represent human-readable wait time
- Add `unsnoozeOnCustomerReply` boolean to snooze data

This keeps Domain objects as the single source of data for both business logic and serialization.

### 3. Preserve Data in Response → Domain Transformation

Update the `toDomain()` methods to preserve fields that were previously discarded:

- `CustomerWaitingSinceResponse.friendly` → Domain
- `SnoozeResponse.unsnoozeOnCustomerReply` → Domain

### 4. Filter Null Values from API Output

When a field is null, **omit it from the response** rather than including `"field": null`.

This matches the original HelpScout API behavior where optional fields are absent, not null. The frontend Zod schemas use `.optional()` which accepts absent fields but not `null`.

**Implementation**: Use Laravel's `$this->when()` helper or `array_filter()`:

```php
// Option A: Use when() for conditional inclusion
return [
    'assignee' => $this->when($this->assignee !== null, fn() =>
        new AssigneeResource($this->assignee)
    ),
];

// Option B: Filter nulls at the end
return array_filter([
    'assignee' => $this->assignee ? new AssigneeResource($this->assignee) : null,
    'snooze' => $this->snooze ? new SnoozeResource($this->snooze) : null,
], fn($v) => $v !== null);
```

**Important**: Null filtering must apply **recursively** to nested objects. For example, `AssigneeResource` should also filter its own null fields:

```php
// AssigneeResource
return array_filter([
    'firstName' => $this->resource->firstName,
    'lastName' => $this->resource->lastName,
    'email' => $this->resource->email,
], fn($v) => $v !== null);
```

### 5. Date Formatting

All `DateTimeImmutable` fields must be serialized as ISO 8601 strings.

**Implementation**: Use PHP's `DateTimeInterface::ATOM` constant:

```php
$this->createdAt->format(DateTimeInterface::ATOM)
// Output: "2024-01-15T10:30:00+00:00"
```

This produces standard ISO 8601 format that JavaScript's `Date` constructor and Zod's `z.string()` accept.

### 6. Field Name Mapping in Resources

API Resources handle the mapping from Domain property names to API contract names:

- `ConversationTag.name` → `tag`
- `ConversationSnooze.snoozedByUserId` → `snoozedBy`
- `Conversation.customer` → `primaryCustomer`

### 7. Structure Reconstruction

`customerWaitingSince` in Domain is `DateTimeImmutable` but API expects `{time: string, friendly: string}`. The Resource must reconstruct this structure using both the timestamp and the preserved `friendly` string.

---

## Expected API Contract

Reference: `alz-admin/src/services/apis/core/customer-service/schemas.ts`

### Conversation Object

```
{
  id: number (required)
  number: number (required)
  subject: string (required)
  status: "active" | "pending" | "closed" (required)
  createdAt: string ISO 8601 (required)
  userUpdatedAt?: string ISO 8601 (omit if null)
  updatedAt?: string ISO 8601 (omit if null)
  primaryCustomer?: { email?, first?, last? } (omit if null)
  mailboxName?: string (omit if null)
  assignee?: { firstName?, lastName?, email? } (omit if null)
  tags?: [{ id, color, tag }] (can be empty array)
  customerWaitingSince?: { time: string, friendly?: string } (omit if null)
  snooze?: { snoozedBy?, snoozedUntil?, unsnoozeOnCustomerReply? } (omit if null)
}
```

### Response Wrapper

All endpoints return: `{ data: Conversation[] }`

---

## Files Requiring Changes in alz-core

### Domain Objects (add missing fields)

- `app/Domain/CustomerService/ValueObjects/Conversation.php` - Add `?string $customerWaitingFriendly` (the human-readable wait time string, e.g., "2 hours ago")
- `app/Domain/CustomerService/ValueObjects/ConversationSnooze.php` - Add `?bool $unsnoozeOnCustomerReply`
- `app/Domain/CustomerService/ValueObjects/ConversationAssignee.php` - Add `?string $email`

**Note on customerWaitingSince**: The Domain will have TWO fields for this data:

1. `customerWaitingSince: ?DateTimeImmutable` (existing - the timestamp, used for sorting)
2. `customerWaitingFriendly: ?string` (new - the human-readable string)

The API Resource combines these into `{ time: datetime.format(), friendly: friendlyString }`.

### Response → Domain Transformations (preserve data)

- `app/Infrastructure/HelpScout/Responses/ConversationResponse.php` - Pass `friendly` to Domain as `customerWaitingFriendly`
- `app/Infrastructure/HelpScout/Responses/SnoozeResponse.php` - Pass `unsnoozeOnCustomerReply` to Domain
- `app/Infrastructure/HelpScout/Responses/AssigneeResponse.php` - Pass `email` to Domain

### New API Resources (create)

- `ConversationResource` - Main conversation transformation
- `TagResource` - Handle `name` → `tag` mapping
- `AssigneeResource` - Assignee transformation
- `CustomerResource` - Customer transformation
- `SnoozeResource` - Handle field mapping and date formatting
- `CustomerWaitingSinceResource` - Reconstruct `{time, friendly}` structure

### Controller (update)

- `app/Presentation/Http/Controllers/HelpScoutController.php` - Wrap results with Resources

---

## Affected Endpoints

All 4 conversation endpoints need the fix:

| Method | Endpoint                                    | Controller Method   |
| ------ | ------------------------------------------- | ------------------- |
| GET    | `/helpscout/conversations/assigned`         | `assigned()`        |
| GET    | `/helpscout/conversations/todos`            | `todos()`           |
| GET    | `/helpscout/conversations/negative-reviews` | `negativeReviews()` |
| GET    | `/helpscout/conversations/escalations`      | `escalations()`     |

Plus their refresh counterparts (POST endpoints).

---

## Verification

After implementation, verify:

1. All 4 HelpScout tables load data in alz-admin dashboard
2. No Zod validation errors in alz-admin logs
3. Date fields are ISO 8601 strings
4. Null fields are omitted (not included as `null`)
5. Tag objects have `tag` property (not `name`)
6. `customerWaitingSince` has `{time, friendly}` structure
7. Snooze has `snoozedBy` property (not `snoozedByUserId`)
8. Customer object uses `primaryCustomer` key (not `customer`)
9. Assignee objects include `email` field when present

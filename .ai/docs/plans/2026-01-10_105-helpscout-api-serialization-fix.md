# HelpScout API Serialization Fix - Implementation Plan

## Problem Summary

All 4 HelpScout dashboard tables in alz-admin are broken due to API contract mismatch. Laravel returns serialized Domain objects instead of the format Zod schemas expect (matching original HelpScout API).

**Root Cause:** Domain objects serialize differently than API contract:
- `DateTimeImmutable` → `{date, timezone_type, timezone}` instead of ISO 8601 strings
- Field names differ (`name` vs `tag`, `customer` vs `primaryCustomer`)
- Null fields included as `null` instead of omitted
- Missing fields (`friendly`, `unsnoozeOnCustomerReply`, `email`)

---

## Solution Architecture

Add Laravel API Resources to transform Domain → API contract:

```
HelpScout API → Response DTO → Domain → Business Logic → API Resource → JsonResponse
```

---

## Implementation Phases

### Phase 1: Domain Object Modifications

Add missing fields to preserve data from HelpScout API.

| File | Change |
|------|--------|
| `app/Domain/CustomerService/ValueObjects/ConversationAssignee.php` | Add `?string $email` |
| `app/Domain/CustomerService/ValueObjects/ConversationSnooze.php` | Add `?bool $unsnoozeOnCustomerReply` |
| `app/Domain/CustomerService/ValueObjects/Conversation.php` | Add `?string $customerWaitingFriendly` |

### Phase 2: Response DTO Modifications

Update `toDomain()` methods to preserve previously discarded data.

| File | Change |
|------|--------|
| `app/Infrastructure/HelpScout/Responses/AssigneeResponse.php` | Pass `$this->email` to Domain |
| `app/Infrastructure/HelpScout/Responses/SnoozeResponse.php` | Pass `$this->unsnoozeOnCustomerReply` to Domain |
| `app/Infrastructure/HelpScout/Responses/ConversationResponse.php` | Pass `$this->customerWaitingSince?->friendly` as `customerWaitingFriendly` |

### Phase 3: Create API Resources

Location: `app/Presentation/Http/Resources/HelpScout/`

| Resource | Responsibility |
|----------|----------------|
| `CustomerResource` | Map `firstName` → `first`, `lastName` → `last`; filter nulls |
| `AssigneeResource` | Include `firstName`, `lastName`, `email`; filter nulls |
| `TagResource` | Map `name` → `tag`; include `id`, `color` |
| `SnoozeResource` | Map `snoozedByUserId` → `snoozedBy`; format `snoozedUntil` as ISO 8601 |
| `ConversationResource` | Orchestrate all above; map `customer` → `primaryCustomer`; format dates |

**Key Implementation Details:**

1. **Null Filtering** - Use `$this->when()` for optional fields:
   ```php
   'assignee' => $this->when(
       $this->assignee !== null,
       fn() => new AssigneeResource($this->assignee)
   ),
   ```

2. **Date Formatting** - Use `DateTimeInterface::ATOM`:
   ```php
   'createdAt' => $this->createdAt->format(DateTimeInterface::ATOM),
   ```

3. **customerWaitingSince** - Reconstruct structure with helper method:
   ```php
   'customerWaitingSince' => $this->when(
       $this->customerWaitingSince !== null,
       fn() => array_filter([
           'time' => $this->customerWaitingSince->format(DateTimeInterface::ATOM),
           'friendly' => $this->customerWaitingFriendly,
       ], fn($v) => $v !== null)
   ),
   ```

### Phase 4: Controller Integration

Update `app/Presentation/Http/Controllers/HelpScoutController.php`:

```php
// Before:
return new JsonResponse(['data' => $conversations]);

// After:
return new JsonResponse(['data' => ConversationResource::collection($conversations)]);
```

**Endpoints to update:** All 8 (4 GET + 4 POST refresh)
- `assigned()`, `refreshAssigned()`
- `todos()`, `refreshTodos()`
- `negativeReviews()`, `refreshNegativeReviews()`
- `escalations()`, `refreshEscalations()`

### Phase 5: Testing

Per TestingStrategy.md - Feature tests for Presentation layer:

| Test | Verifies |
|------|----------|
| Endpoint returns valid JSON | Structure matches Zod schema |
| Null fields omitted | No `"field": null` in response |
| Dates formatted | ISO 8601 strings (`2024-01-15T10:30:00+00:00`) |
| Field mappings | `tag`, `primaryCustomer`, `snoozedBy`, `first`/`last` |

---

## Files Summary

**Modify (7 files):**
- `app/Domain/CustomerService/ValueObjects/Conversation.php`
- `app/Domain/CustomerService/ValueObjects/ConversationAssignee.php`
- `app/Domain/CustomerService/ValueObjects/ConversationSnooze.php`
- `app/Infrastructure/HelpScout/Responses/AssigneeResponse.php`
- `app/Infrastructure/HelpScout/Responses/SnoozeResponse.php`
- `app/Infrastructure/HelpScout/Responses/ConversationResponse.php`
- `app/Presentation/Http/Controllers/HelpScoutController.php`

**Create (5 files):**
- `app/Presentation/Http/Resources/HelpScout/CustomerResource.php`
- `app/Presentation/Http/Resources/HelpScout/AssigneeResource.php`
- `app/Presentation/Http/Resources/HelpScout/TagResource.php`
- `app/Presentation/Http/Resources/HelpScout/SnoozeResource.php`
- `app/Presentation/Http/Resources/HelpScout/ConversationResource.php`

**Create (1 test file):**
- `tests/Feature/HelpScout/ConversationApiContractTest.php`

---

## Expected API Contract

```json
{
  "data": [{
    "id": 123,
    "number": 456,
    "subject": "Order inquiry",
    "status": "active",
    "createdAt": "2024-01-15T10:30:00+00:00",
    "userUpdatedAt": "2024-01-15T11:00:00+00:00",
    "primaryCustomer": {
      "email": "customer@example.com",
      "first": "John",
      "last": "Doe"
    },
    "assignee": {
      "firstName": "Support",
      "lastName": "Agent",
      "email": "agent@company.com"
    },
    "tags": [
      { "id": 1, "color": "#ff0000", "tag": "urgent" }
    ],
    "customerWaitingSince": {
      "time": "2024-01-15T09:00:00+00:00",
      "friendly": "2 hours ago"
    },
    "snooze": {
      "snoozedBy": 789,
      "snoozedUntil": "2024-01-16T09:00:00+00:00",
      "unsnoozeOnCustomerReply": true
    }
  }]
}
```

*Note: Optional fields (`assignee`, `snooze`, `primaryCustomer`, etc.) are omitted entirely when null.*

---

## Verification

1. Run `make lint` - All linters pass
2. Run `make test` - All tests pass
3. Test in alz-admin - All 4 tables load data without Zod errors
4. Check alz-admin logs - No validation errors

---

## Risk Assessment

| Risk | Mitigation |
|------|------------|
| Breaking existing behavior | Domain changes are backward-compatible (new optional params) |
| Missing edge cases | Feature tests verify structure for all scenarios |
| Performance impact | Resources add minimal overhead (simple transformations) |

# QuickBooks Order Sync API — Admin Dashboard Contract

API contract for the staff QuickBooks order sync page and its failed-items table. Three endpoints post ShopWired orders to the connected QuickBooks Online (QBO) company and list the ones that failed. Design: [`../architecture/quickbooks-order-sync.md`](../architecture/quickbooks-order-sync.md); decisions: [ADR-0017](../adr/0017-qbo-order-sync-unit-and-post-once.md), [ADR-0018](../adr/0018-qbo-mappings-hold-semantic-keys.md), [ADR-0019](../adr/0019-qbo-m1-duplicate-safety-and-batch-document-create.md).

| Method | Path | Purpose |
|--------|------|---------|
| `POST` | `/api/quickbooks/orders/sync-range` | Queue a run over every order placed in a date range |
| `POST` | `/api/quickbooks/orders/sync` | Sync up to 10 order references now and return each one's outcome |
| `GET` | `/api/quickbooks/orders/failed` | List the connected company's failed order syncs |

- **Authentication**: staff JWT; every endpoint requires `RoleTier::Staff` (ADR-0014).
- **Rate limited**: the `throttle:api` middleware.

---

## Conventions

- Keys are snake_case.
- Timestamps are ISO 8601 with an offset, e.g. `2026-11-21T14:30:00+00:00`.
- Money is a decimal string, e.g. `"49.99"`.
- "Today" and every range date are Europe/London calendar dates.
- A **reference** is the ShopWired order `reference` (an integer). One reference has at most one **order sync** per QBO company (ADR-0017).
- Every error body uses one envelope; `errors` is present only where a row below says so:

```json
{
  "error": {
    "type": "validation_error",
    "message": "The given data was invalid.",
    "errors": {
      "to": ["The range may span at most 31 days."]
    }
  }
}
```

Message text is for display only and may change. ADM branches on the status code, `type` and the `errors` keys, never on `message`.

---

## Preconditions

Checked in the request, before any QBO call.

| Status | `type` | `errors` keys | Endpoints | When |
|--------|--------|---------------|-----------|------|
| 401 | `unauthorized` | — | all | Missing or invalid JWT (middleware) |
| 403 | `forbidden` | — | all | Caller is below the staff tier (middleware) |
| 412 | `precondition_failed` | — | all | QBO is not connected: no connection, blank credentials (ADR-0015), or a revoked or invalid grant while the connection row still exists |
| 412 | `precondition_failed` | `missing_mapping_keys` | both POSTs | QBO is connected but one or more seeded mapping keys have no mapping for the connected company (ADR-0018) |
| 422 | `validation_error` | the invalid field(s) | all | Request body or query fails validation (per endpoint below) |
| 429 | `error` | — | all | `throttle:api` per-minute limit reached; the response carries no `Retry-After` header |
| 503 | `service_unavailable` | — | both POSTs | An access token cannot be obtained for a transient reason |
| 502 | `upstream_error` | — | both POSTs | A permanent QBO API failure outside any one order's outcome |
| 500 | `server_error` | — | all | Unexpected error |

- ADM tells the two 412s apart by whether `errors.missing_mapping_keys` is present: absent → show the reconnect prompt; present → list the missing keys.
- A revoked or invalid grant returns the same body as "not connected", so ADM shows the reconnect prompt instead of an outage message.
- A 503 is generic (an auth key fetch, a lock or a transient upstream failure share it); ADM must not infer its cause and should offer a retry.

```json
{
  "error": {
    "type": "precondition_failed",
    "message": "QuickBooks mappings are incomplete.",
    "errors": {
      "missing_mapping_keys": ["tax.uk_standard", "payment_route.paypal"]
    }
  }
}
```

The keys shown are illustrative; the real list is whatever the migration seeds.

---

## `POST /api/quickbooks/orders/sync-range`

Queues one run over every order whose `order_placed_at` falls in the inclusive range. Results are not returned here: a range run's failures reach ADM only as `failed` rows in `GET /api/quickbooks/orders/failed`.

### Request

```json
{
  "from": "2026-11-01",
  "to": "2026-11-21",
  "force_retry": false
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `from` | string | ✅ | `YYYY-MM-DD`, inclusive |
| `to` | string | ✅ | `YYYY-MM-DD`, inclusive |
| `force_retry` | boolean | ❌ | Default `false`; re-attempts stored `failed` rows (see Row-state rules) |

### Range limits

Every limit returns 422 `validation_error`.

| Limit | Status | Error field key |
|-------|--------|-----------------|
| `from` is not a `YYYY-MM-DD` date | 422 | `from` |
| `to` is not a `YYYY-MM-DD` date | 422 | `to` |
| `to` before `from` | 422 | `to` |
| `to` after today | 422 | `to` |
| Span over 31 days inclusive (`from` and `to` both count) | 422 | `to` |
| `from` before the floor date | 422 | `from` |

- The floor date is a server-side config value a human sets at cutover. It is never sent to ADM except inside the `errors.from` message.
- Until the floor date is set, every range request fails closed with 422 keyed under `from`.

### Responses

| Status | Body |
|--------|------|
| 202 | Run accepted (below) |
| 401, 403, 412, 422, 429, 500, 502, 503 | Error envelope (Preconditions, Range limits) |

```json
{
  "message": "QuickBooks order sync queued.",
  "run_id": "0f8b6d2e-3c1a-4e5b-9a7d-2b6c4e8f1a90",
  "candidate_count": 42
}
```

| Field | Type | Notes |
|-------|------|-------|
| `message` | string | Display text |
| `run_id` | string (uuid) \| null | A fresh id per request; `null` when `candidate_count` is 0 and nothing is dispatched |
| `candidate_count` | integer | Order references in the range the run will consider |

- Every valid request returns 202. A request with candidates dispatches its own run; one with `candidate_count` 0 dispatches nothing and returns `run_id: null`. A second request for an overlapping range is not dropped. Leases make the overlap safe (ADR-0017).
- `run_id` is a log and support correlation id only: no endpoint takes it.

---

## `POST /api/quickbooks/orders/sync`

Syncs the given references in the request and returns one outcome per reference.

### Request

```json
{
  "references": [100245, 100246, 100250],
  "force_retry": false
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `references` | integer[] | ✅ | 1 to 10 items, distinct |
| `force_retry` | boolean | ❌ | Default `false`; re-attempts stored `failed` rows |

An empty array, more than 10 items, a duplicate or a non-integer item returns 422 `validation_error` keyed under `references` (or `references.<index>` for one item).

A request with any reference whose order was placed before the floor date (see [Range limits](#range-limits)) returns 422 `validation_error` keyed under `references`, and nothing in it is synced. Until the floor date is set, every request fails closed the same way. A reference with no order passes this check and returns `skipped` (`order_not_found`).

### Responses

| Status | Body |
|--------|------|
| 200 | Per-reference results (below), whatever the outcomes |
| 401, 403, 412, 422, 429, 500, 502, 503 | Error envelope (Preconditions) |

```json
{
  "total": 3,
  "counts": {
    "synced": 1,
    "already_synced": 0,
    "skipped": 1,
    "failed": 1,
    "not_attempted": 0
  },
  "results": [
    {
      "reference": 100245,
      "outcome": "synced",
      "doc_type": "invoice",
      "qbo_doc_number": "<doc number>",
      "qbo_txn_id": "<txn id>",
      "warnings": [],
      "reason": null
    },
    {
      "reference": 100246,
      "outcome": "skipped",
      "doc_type": null,
      "qbo_doc_number": null,
      "qbo_txn_id": null,
      "warnings": [],
      "reason": {
        "code": "cancelled",
        "message": "The order is cancelled.",
        "retryable": false
      }
    },
    {
      "reference": 100250,
      "outcome": "failed",
      "doc_type": "sales_receipt",
      "qbo_doc_number": null,
      "qbo_txn_id": null,
      "warnings": [],
      "reason": {
        "code": "item_missing",
        "message": "A line's SKU has no matching QuickBooks item.",
        "retryable": true
      }
    }
  ]
}
```

| Field | Type | Notes |
|-------|------|-------|
| `total` | integer | Number of references asked about |
| `counts` | object | One integer per outcome; every outcome key is always present |
| `results[]` | array | Exactly one item per reference asked about, unknown references included |
| `results[].reference` | integer | |
| `results[].outcome` | string | `synced`, `already_synced`, `skipped`, `failed` or `not_attempted` |
| `results[].doc_type` | string \| null | `invoice`, `sales_receipt` or `null` |
| `results[].qbo_doc_number` | string \| null | Set on `synced` and `already_synced` |
| `results[].qbo_txn_id` | string \| null | Set on `synced` and `already_synced` |
| `results[].warnings[]` | array | `{code, message, details}`; always empty in M1 (see Reserved) |
| `results[].reason` | object \| null | `{code, message, retryable}`; set on `skipped`, `failed` and `not_attempted`, `null` otherwise |

---

## `GET /api/quickbooks/orders/failed`

Lists the connected company's order syncs in the requested status, newest `last_attempted_at` first. Only stored rows appear: `skipped` and `not_attempted` are outcomes, never stored states, so they are never listed.

### Query

| Param | Type | Default | Notes |
|-------|------|---------|-------|
| `status` | string | `failed` | Exactly `failed` or `changed_after_sync`; anything else returns 422 keyed under `status` |
| `page` | integer | 1 | |
| `per_page` | integer | 50 | Max 1000 |

`status=changed_after_sync` is reserved and returns an empty page in M1 (see Reserved).

### Responses

| Status | Body |
|--------|------|
| 200 | Paginated rows (below) |
| 401, 403, 412, 422, 429, 500 | Error envelope (Preconditions) |

```json
{
  "data": [
    {
      "reference": 100250,
      "order_external_id": 123456,
      "order_placed_at": "2026-11-20T09:12:44+00:00",
      "doc_type": "sales_receipt",
      "status": "failed",
      "attempts": 2,
      "last_attempted_at": "2026-11-21T10:05:13+00:00",
      "reason": {
        "code": "item_missing",
        "message": "A line's SKU has no matching QuickBooks item.",
        "retryable": true
      },
      "warnings": []
    }
  ],
  "links": {
    "first": "https://api.example/api/quickbooks/orders/failed?page=1",
    "last": "https://api.example/api/quickbooks/orders/failed?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "path": "https://api.example/api/quickbooks/orders/failed",
    "per_page": 50,
    "to": 1,
    "total": 1
  }
}
```

| Field | Type | Notes |
|-------|------|-------|
| `reference` | integer | |
| `order_external_id` | integer | The ShopWired order id the attempt used |
| `order_placed_at` | string \| null | ISO 8601; `null` once the order no longer exists |
| `doc_type` | string \| null | `invoice`, `sales_receipt` or `null` when the failure came before the document type was known |
| `status` | string | The stored row state: `failed` |
| `attempts` | integer | Attempts made on this row |
| `last_attempted_at` | string | ISO 8601 |
| `reason` | object | `{code, message, retryable}` from the last attempt; one of the `failed` codes below |
| `warnings[]` | array | Always empty in M1 |

`links` and `meta` are the Laravel paginator envelope, as on `GET /api/contact-submissions/failed`; query parameters carry into the links.

---

## Outcomes and reason codes

`retryable` means attempting again may succeed. A stored `failed` row is re-attempted only with `force_retry: true`, so `previously_failed` is retryable. ADM offers retry where `retryable` is `true`.

| Outcome | Code | `retryable` | Returned by | Meaning |
|---------|------|-------------|-------------|---------|
| `synced` | — | — (no reason) | `POST sync` | Posted now; `doc_type`, `qbo_doc_number` and `qbo_txn_id` set |
| `already_synced` | — | — (no reason) | `POST sync` | Posted earlier; never re-created, whatever `force_retry` says |
| `skipped` | `cancelled` | `false` | `POST sync` | The order is cancelled; no row is written |
| `skipped` | `order_not_found` | `false` | `POST sync` | No order has this reference; no row is written |
| `skipped` | `previously_failed` | `true` | `POST sync` | A stored `failed` row exists and `force_retry` was not set; the row stays `failed` |
| `failed` | `mapping_missing` | `false` | `POST sync`, `GET failed` | The order needs a mapping key the migration did not seed, so the 412 check could not catch it (a seeded key with no mapping is a 412 instead), or the order's payment method was not stored |
| `failed` | `item_missing` | `true` | `POST sync`, `GET failed` | A line's SKU has no matching QBO item; succeeds once the item exists |
| `failed` | `zero_lines` | `false` | `POST sync`, `GET failed` | The order produced no document lines |
| `failed` | `tax_rate_unsupported` | `false` | `POST sync`, `GET failed` | A VAT rate on the order is one the sync has no tax key for (only 20% and 0% are supported) |
| `failed` | `closed_period` | `false` | `POST sync`, `GET failed` | QBO rejected the document because its date is in a closed books period |
| `failed` | `qbo_validation` | `false` | `POST sync`, `GET failed` | QBO rejected the document as invalid |
| `failed` | `qbo_unavailable` | `true` | `POST sync`, `GET failed` | QBO was unavailable or throttled the call, and nothing was posted |
| `failed` | `outcome_unknown` | `true` | `POST sync`, `GET failed` | The post may have reached QBO but no result came back; re-attempting can post a duplicate document (ADR-0019) |
| `not_attempted` | `in_progress` | `true` | `POST sync` | Another worker holds a live lease on this reference |
| `not_attempted` | `deadline_exceeded` | `true` | `POST sync` | The request's server deadline passed before this reference was reached |

- Every raw ShopWired payment method routes to a document type, so no payment method is skipped. An order whose payment method was not stored fails with `mapping_missing`.
- A range run produces the same outcomes, but only `failed` rows are visible to ADM afterwards, through `GET failed`.

---

## Row-state rules (`force_retry`)

For each reference, first match wins:

| # | State | `force_retry: false` | `force_retry: true` |
|---|-------|----------------------|---------------------|
| 1 | Stored row is `synced` | `already_synced` | `already_synced` |
| 2 | Order is cancelled | `skipped` (`cancelled`) | `skipped` (`cancelled`) |
| 3 | No order has the reference | `skipped` (`order_not_found`) | `skipped` (`order_not_found`) |
| 4 | Stored row is `failed` | `skipped` (`previously_failed`) | attempted |
| 5 | Stored row is `processing` with a live lease | `not_attempted` (`in_progress`) | `not_attempted` (`in_progress`) |
| 6 | Stored row is `processing` with an expired lease | attempted (as `pending`) | attempted (as `pending`) |
| 7 | No row, or stored row is `pending` | attempted | attempted |

- An attempted reference ends as `synced`, `failed`, or `not_attempted` (`deadline_exceeded`).
- `skipped` reasons are computed on every run and never stored.
- Rule 1 comes first so a posted order never reads as skipped; rules 2–3 come before rule 4 so a now-cancelled or deleted order reports its current state over a stale failure.

---

## Reserved

`changed_after_sync` belongs to COR-307 (M2). M1 never returns it.

- As a `GET failed` `status` filter value: accepted, returns an empty page.
- As a `warnings[]` code: `{"code": "changed_after_sync", "message": "...", "details": {"synced_external_id": "...", "current_external_id": "..."}}`, set when a posted order sync's reference now has a different live ShopWired `external_id`.

---

## Quick Reference

| Endpoint | Success | Body / query limits |
|----------|---------|---------------------|
| `POST /api/quickbooks/orders/sync-range` | 202 `{message, run_id, candidate_count}` | `from`, `to` `YYYY-MM-DD`; `to` ≥ `from`; `to` ≤ today; ≤ 31 days inclusive; `from` ≥ floor; `force_retry` optional |
| `POST /api/quickbooks/orders/sync` | 200 `{total, counts, results}` | `references` 1–10 distinct integers; every order placed ≥ floor; `force_retry` optional |
| `GET /api/quickbooks/orders/failed` | 200 `{data, links, meta}` | `status` `failed` \| `changed_after_sync`; `page`; `per_page` ≤ 1000, default 50 |

| Response | ADM action |
|----------|------------|
| 412 without `errors` | Show the QuickBooks reconnect prompt |
| 412 with `errors.missing_mapping_keys` | List the missing mapping keys |
| 422 | Show each `errors` entry beside its field |
| 429 | Wait about a minute (no `Retry-After` header is sent), then allow a retry |
| 503 | Show a temporary-failure message and offer a retry |
| 502 | Show a QuickBooks failure message; offer no retry |

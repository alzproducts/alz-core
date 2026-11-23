# Order Status Summary API — alz-connect Contract

API contract for alz-connect's homepage order status data, read from alz-core's database. Decision: [ADR-0023](../adr/0023-service-callers-authenticate-by-token-with-ip-allowlist.md).

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/internal/order-status-summary` | Paid and errored order counts, and the references of unpaid orders |

- **Authentication**: the alz-connect service caller's bearer token, sent from an allowlisted client IP. Both are required.
- **Rate limited**: 60 requests a minute per client IP (`throttle:service-callers`; off when `APP_ENV=local`).

## Request

```
GET /api/internal/order-status-summary
Authorization: Bearer <token>
```

## Response

```json
{
  "data": {
    "pending_paid_count": 3,
    "unpaid_order_references": ["12345", "12346"],
    "errored_paid_count": 0
  }
}
```

| Field | Type | Meaning |
|-------|------|---------|
| `pending_paid_count` | integer | Orders with ShopWired status 73877 (Paid) |
| `unpaid_order_references` | list of strings | References of orders with status 73875 (Not Paid) placed within the last 700 days, oldest first, then by ShopWired order ID; uncapped |
| `errored_paid_count` | integer | Orders with status 180255 (Received) |

- Archived orders are excluded. An edited order counts once, as its current row (`shopwired.orders_deduplicated`).
- Test customers' orders are included.
- References have no `A` prefix.
- With no matching orders, both counts are `0` and the list is `[]`.

## Errors

Checked in this order. Every body uses the standard envelope:

```json
{
  "error": {
    "type": "unauthorized",
    "message": "Unauthorized."
  }
}
```

| Status | `type` | When |
|--------|--------|------|
| 429 | `error` | Rate limit reached |
| 503 | `service_unavailable` | The token hash or IP allowlist is blank or unset; also a transient database outage |
| 401 | `unauthorized` | Missing, malformed or wrong token (one body for all three) |
| 403 | `forbidden` | Correct token, client IP not allowlisted |
| 500 | `server_error` | Unexpected error |

Message text is for display only and may change.

## Environment variables

| Variable | Value |
|----------|-------|
| `ALZ_CONNECT_BEARER_SHA256` | Lowercase hex SHA-256 of the bearer token; alz-core never holds the token |
| `ALZ_CONNECT_ALLOWED_IPS` | Comma-separated client IP addresses, IPv4 or IPv6; spaces are ignored and CIDR ranges are not supported |

The caller's token is 256 random bits, for example `openssl rand -hex 32`. Hash it with no trailing newline: `printf %s "$TOKEN" | shasum -a 256 | cut -d' ' -f1`.

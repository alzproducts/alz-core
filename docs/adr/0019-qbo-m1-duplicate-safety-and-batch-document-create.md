# M1 duplicate safety is the lease and post-once record; documents are created by SDK batch calls

In M1, duplicate safety is the sync row's lease (ADR-0012) and its post-once record (ADR-0017) only. Every Invoice and SalesReceipt create is a QuickBooks PHP SDK batch call per chunk. Customer find is one plain `Customer` query per chunk over the chunk's distinct DisplayNames: `DisplayName IN (…)`, each value escaped with a backslash before `'`, with `Active IN (true, false)` and MAXRESULTS set; results are keyed back to orders case-insensitively. QBO compares `=` and `IN` values the same way, case-insensitively, so the chunk query finds what the replaced Zap's per-order `DisplayName =` find would. Each miss is created by a single call, as in the Zap, and a 6240 (duplicate name) on that create re-queries the one name. No stable or idempotent `requestid` is sent: the SDK's `Batch::Execute()` sends a random id on every call, which gives no duplicate protection across retries, and M1 does not hand-build a batch request (COR-294).

The evidence is recorded as verified from the SDK v6.4.1 source and Intuit's documentation: per-item Faults in a batch response are parsed without throwing, keeping only the first error code; a batch holds at most 30 items; and each company allows 40 batch requests per minute. The sandbox run that confirms them belongs to COR-300. Any of these sandbox results overturns this decision:

- one bad item fails its whole chunk instead of only its own item;
- QBO caps a batch below the chunk size;
- the 40-per-minute batch limit throttles a range run;
- QBO rejects `IN` on `DisplayName` (the fallback is one find per distinct name).

## Considered Options

- One customer find per order, as in the Zap: rejected because it costs one call per distinct customer instead of one per chunk, against the 500-per-minute limit and the manual sync's server deadline, for the same match.
- Putting the customer query or customer creates inside the document batch: rejected because batch items are independent and unordered, so an invoice cannot use a customer found or created in the same batch, and Intuit advises against batching list entities.
- Hand-building a batch request without a `requestid`: rejected because it is new code beyond Zap parity and still gives no idempotency.
- A QBO-side duplicate check before re-posting: deferred as COR-321 points 2 and 3, undecided.

## Consequences

- A post can reach QBO and go unrecorded: a whole-batch null or timeout (the SDK's curl timeout is 15 s, QBO's server timeout is 120 s) or a worker dying between the call and the record leaves a posted document with no post-once record. Its row is recorded `failed` with reason `outcome_unknown`, or stays `processing` until its lease expires, and a re-attempt by expired lease or `force_retry` then posts the document again. Recovering from that stays with COR-321 points 2 and 3.
- Overlapping runs can both miss and create the same customer, as the Zap can; serialising customer find-or-create per company is COR-321 point 14, undecided.
- A chunk holds at most 30 orders (the batch maximum), and each chunk spends one batch request and one customer query.

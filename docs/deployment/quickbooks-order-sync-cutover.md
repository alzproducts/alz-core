# QuickBooks Order Sync Production Cutover

The steps that move the order sync from Zapier to alz-core in production (COR-314). Every step is run by a human; agents prepare the commands and SQL and run none of them against production. Work through the steps in order. Where a step does not show its required state, stop and go no further.

This repo is public. Write no date, count, reference, QuickBooks id, account name or other value from the live system in this file, the issue, a PR or a commit.

**The gap** runs from the day the Zap went off to the last day of the dry run you reviewed, both included, as Europe/London days. The last day must already be over when the dry run runs. Replace `<day the Zap went off>` and `<last day>` with `YYYY-MM-DD` dates as you run each command, and do not save them here.

Three rules hold throughout:

- **Never click Retry on a "Sync failed" alert, and never submit the form again, until you have checked.** A queued reference sync shows "Sync failed" on the dashboard although it was queued. Search the worker and web logs for `Requested QuickBooks order sync by reference` and read the Failed orders table first. Do not let two forced runs overlap: a retry from the Failed orders table always sends `force_retry`.
- **Never retry a QuickBooks job from Horizon or with `php artisan queue:retry`.** It repeats the whole payload and can post a document twice ([ADR-0024](../adr/0024-qbo-posting-jobs-run-once.md)). Queue the range again instead.
- **Never deploy or change an environment variable while a run is in flight.** It redeploys the services and can leave orders `processing` or `outcome_unknown`.

## 1. Confirm the release

A human runs this step.

1. In the Railway dashboard, open the production web, worker and scheduler services in turn, and read the commit of each active deployment. All three must be the same commit.
2. In a local alz-core checkout, run each command with that commit. Each must exit 0, so `ok` is printed.

```bash
git fetch origin
```

```bash
git merge-base --is-ancestor 96346bfc <deployed commit> && echo "COR-306 ok"
```

```bash
git merge-base --is-ancestor f479899c <deployed commit> && echo "COR-353 ok"
```

```bash
git merge-base --is-ancestor 3622a4cd <deployed commit> && echo "COR-357 ok"
```

```bash
git cat-file -e <deployed commit>:docs/deployment/quickbooks-order-sync-cutover.md && echo "COR-314 ok"
```

## 2. Confirm the sync settings

A human runs this step, in the Railway dashboard's Variables tab of the production web, worker and scheduler services. On all three:

- `QUICKBOOKS_BACKFILL_FLOOR_DATE` is set to a date on or before the day the Zap went off. If it is later, stop and take it to the user; do not change it here.
- `QUICKBOOKS_ORDER_SYNC_CONCURRENCY` is unset or 1.

## 3. Confirm the dashboard

A human runs this step.

1. In the Vercel dashboard, open alz-admin's production deployment and read its commit. In a local alz-admin checkout, confirm it contains ADM-101:

   ```bash
   git fetch origin
   ```

   ```bash
   git merge-base --is-ancestor 34a46f55 <deployed commit> && echo "ADM-101 ok"
   ```

2. In the Vercel dashboard, confirm that `ENABLE_QUICKBOOKS_SYNC` is `true` for production, or set it and redeploy.
3. Open `/orders/quickbooks-sync` in production alz-admin. The QuickBooks Sync page must open.
4. Staff now see the page too. The user tells them not to use it before M2.

## 4. Confirm the connection

A human runs this step, in a shell on the production worker. The command must exit 0 and name the production company.

```bash
php artisan verify:api quickbooks
```

## 5. Map the company

A human runs this step, in an interactive shell on the production worker. Pick the entity for each key it prompts for:

```bash
php artisan quickbooks:mappings:resolve
```

Then check every mapping. The command must exit 0:

```bash
php artisan quickbooks:mappings:resolve --check
```

## 6. Confirm the QuickBooks company settings

A human runs this step, in the production QuickBooks company.

1. Account and settings > Sales > Sales form content: Custom transaction numbers is on.
2. Taxes > VAT, in the list of VAT codes: the codes mapped to `tax.eu_goods` and `tax.eu_shipping` each carry 0% on sales. To see which codes those are, a human runs this in the Supabase SQL editor:

```sql
SELECT key, qbo_ref_name
FROM quickbooks.mappings
WHERE key IN ('tax.eu_goods', 'tax.eu_shipping');
```

## 7. Run and review the dry run

A human runs this step, in a shell on the production worker, after `<last day>` is over. The command must exit 0. It posts nothing.

```bash
php artisan quickbooks:dry-run-totals --from=<day the Zap went off> --to=<last day>
```

The expected totals round VAT per line, as QuickBooks does (COR-309). Read every row whose `result` is not `match`:

- `warning` is a difference in totals. It does not block the sync. A structural difference, such as a missing discount line, goes to COR-321.
- Any other result is the reason code the order would fail with. Fix its cause before step 9 where it can be fixed.

When you have read every such row, comment on COR-314 that you have. Put no count, date or reference in the comment.

## 8. Run the small reference sync

A human runs this step.

1. Choose 1 to 10 gap orders: one of each document type (invoice, sales receipt) that the gap contains. Take orders QuickBooks does not already hold: in QuickBooks, search for `A` followed by each reference and find nothing. A document the Zap posted would be recorded as `synced` and prove nothing about the mappings.
2. On the QuickBooks Sync page, under "Sync order references", enter the references, leave "Force retry" unticked and click "Sync references" once. Expect the "Sync failed" alert, and follow the first rule above.
3. Wait until the worker log holds `Ran QuickBooks order chunk` with an empty `unfinished` for the run id in `Requested QuickBooks order sync by reference`. The Failed orders table must hold none of the references.
4. In QuickBooks, open the document of every order and check it:
   - It was created on the day of this sync: More > Audit history. An earlier creation means the document is not from this sync, so choose another order.
   - Its number is `A` followed by the reference.
   - Its type, customer, date, lines, VAT and total are as the M1 behaviour column of the [Zap parity table](../architecture/quickbooks-order-sync.md) gives them.
   - Its tax codes, deposit account, payment method, department, class, terms and custom fields are the ones the mappings name, where the document type carries them.

Do not go on until every document is right. To see the mapped names, a human runs this in the Supabase SQL editor:

```sql
SELECT key, qbo_entity, qbo_ref_name
FROM quickbooks.mappings
ORDER BY key;
```

## 9. Queue the range syncs

A human runs this step, on the QuickBooks Sync page, only after the dry run's COR-314 comment is posted.

Under "Sync a date range", pick the first month of the gap, starting on the day the Zap went off, leave "Force retry" unticked and click "Sync range". Repeat for each month until `<last day>`. Each range is at most 31 days, and together they cover every day of the gap. A green alert gives each run's id; keep the ids outside the repo.

## 10. Check the outcome

A human runs this step, once the runs have finished: in Horizon, the `low` queue holds no `SyncQuickBooksOrderRangeJob` or `SyncQuickBooksOrderChunkJob`, pending or delayed.

1. On the QuickBooks Sync page, read every page of the Failed orders table. An empty table does not prove that every order posted, because an order whose job died may not be in it.
2. In the web and worker logs, search for each of these lines since the first range sync:
   - `QuickBooks order range run failed`
   - `QuickBooks order chunk failed`
   - `QuickBooks batch create outcome is unknown`
   - `QuickBooks order sync lease lost before its outcome was recorded`
   - `QuickBooks order sync outcome could not be recorded`
   - `QuickBooks order sync row could not be released`
3. In the Supabase SQL editor, run the count per month, with the gap's two dates filled in. `orders` must equal `synced` plus `failed` in every month:

   ```sql
   WITH bounds AS (
       SELECT
           DATE '<day the Zap went off>' AS first_day,
           DATE '<last day>' AS last_day
   ),
   gap AS (
       SELECT
           o.reference,
           date_trunc('month', o.order_placed_at AT TIME ZONE 'Europe/London')::date AS london_month
       FROM shopwired.orders_deduplicated o
       CROSS JOIN bounds b
       WHERE o.order_placed_at >= b.first_day::timestamp AT TIME ZONE 'Europe/London'
         AND o.order_placed_at < (b.last_day + 1)::timestamp AT TIME ZONE 'Europe/London'
         AND o.lifecycle_status <> 'cancelled'
   )
   SELECT
       g.london_month,
       count(*) AS orders,
       count(*) FILTER (WHERE EXISTS (
           SELECT 1 FROM quickbooks.order_syncs s
           WHERE s.reference = g.reference AND s.status = 'synced'
       )) AS synced,
       count(*) FILTER (WHERE EXISTS (
           SELECT 1 FROM quickbooks.order_syncs s
           WHERE s.reference = g.reference AND s.status = 'failed'
       )) AS failed
   FROM gap g
   GROUP BY g.london_month
   ORDER BY g.london_month;
   ```

4. In the Supabase SQL editor, run the outcome SQL, with the gap's two dates filled in. It returns the references of uncancelled gap orders with no sync row, or with one that is neither `synced` nor `failed`:

   ```sql
   WITH bounds AS (
       SELECT
           DATE '<day the Zap went off>' AS first_day,
           DATE '<last day>' AS last_day
   )
   SELECT o.reference
   FROM shopwired.orders_deduplicated o
   CROSS JOIN bounds b
   WHERE o.order_placed_at >= b.first_day::timestamp AT TIME ZONE 'Europe/London'
     AND o.order_placed_at < (b.last_day + 1)::timestamp AT TIME ZONE 'Europe/London'
     AND o.lifecycle_status <> 'cancelled'
     AND (
         NOT EXISTS (
             SELECT 1 FROM quickbooks.order_syncs s
             WHERE s.reference = o.reference
         )
         OR EXISTS (
             SELECT 1 FROM quickbooks.order_syncs s
             WHERE s.reference = o.reference
               AND s.status NOT IN ('synced', 'failed')
         )
     )
   ORDER BY o.reference;
   ```

5. If it returns references, wait 15 minutes for leases to lapse. Queue the range sync for those days again, as in step 9, with "Force retry" unticked. When that run has finished, run the outcome SQL again. Go on only when it returns nothing.

## 11. Decide each failed order

A human runs this step, on the QuickBooks Sync page. For every gap order in the Failed orders table, choose one of:

- **Retry it.** For `outcome_unknown`, first search QuickBooks for `A` followed by the reference, because the document may already be there. Select the order and click "Retry selected" once. Where the order cannot be selected, fix its cause and sync it under "Sync order references" with "Force retry" ticked.
- **Handle it outside the sync**, by entering it in QuickBooks by hand.
- **Leave it.**

Afterwards, comment on COR-314 that you have chosen for every one. Put no count, date or reference in the comment.

## 12. Keep up until the hourly catch-up

A human runs this step: until COR-315 runs, the user queues a range sync on the QuickBooks Sync page for the days after the gap, as in step 9.

## 13. Archive the Zap

A human runs this step, in Zapier, after one month-end close: archive the Zap, which stays switched off until then. COR-314 does not wait for this step.

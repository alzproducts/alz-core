# Agent production reads

Agents read production through `bin/agent-db` as the Postgres role `agent_readonly`. The role's grants are the boundary: `USAGE` on the allowlisted schemas and column-level `SELECT` on the columns in `database/agent-read/read-allowlist.php`, nothing else. Its `default_transaction_read_only` and `statement_timeout` are session defaults only, which the role can `SET` back, and `pgaudit.log = 'read'` logs every query it runs.

## How it is kept

- A migration creates the role `NOLOGIN`. It never grants anything.
- `AgentReadGrantSync` runs after every forward `migrate`, including a run with nothing pending: locally, in CI, in verify and on deploy. It revokes everything the role holds and grants exactly the Read allowlist. A view a later migration drops and recreates gets its grants back on the same run, and a grant made by hand is undone on the next deploy.
- A new table or column stays unreadable until it is added to the allowlist. Every grant names its columns, so `SELECT *` fails on every relation.
- `AgentReadAllowlistRules` refuses an allowlist that names:
  - a relation outside the nine business schemas;
  - a column whose name marks a person's identifier, customer-written free text, a staff identifier or a secret;
  - a `json`/`jsonb` column missing from `database/agent-read/json-exceptions.php`.
  
  The JSON exception list may name no order, customer, basket, contact or call relation. A refused allowlist fails the migrate.
- `database/agent-read/privilege-audit.sql`, run as the role, lists every privilege it can use beyond the allowlist and the PUBLIC set accepted for every role (`CONNECT` and `TEMPORARY` on the database, `USAGE` on `public`, catalog reads). It reports PUBLIC grants too. No rows means clean. CI runs it on the Supabase baseline, and the human step below runs it in production.

## Changing the allowlist

Edit `database/agent-read/read-allowlist.php` (and `json-exceptions.php` for product JSON). No migration is needed: the next `migrate` applies it, and `AgentReadPrivilegesTest` fails until the role's effective privileges match. Leave out every column that identifies a person or their parcel (names, emails, phones, street, town, postcode, IP addresses, ad click IDs, tracking numbers, date of birth, user agent), every customer-written free-text column, and every secret or token. Keep opaque keys (order reference, external IDs, `customer_id`) and system-written error text.

## One-time production set-up (human)

Do this only after both of these are true:

- COR-393's production check returns no rows.
- The deploy that creates the role has run.

PUBLIC must not be able to execute any `SECURITY DEFINER` function before the role can log in.

1. **Install `psql`:** `brew install libpq`. The wrapper also finds Homebrew's keg-only `/opt/homebrew/opt/libpq/bin/psql`.
2. **Place the Supabase root certificate:**
   - In the dashboard, open Database settings, then SSL configuration, then Download certificate.
   - Save the file as `~/.config/alz-core/supabase-root.crt`.
   - The wrapper connects with `sslmode=verify-full` against it.
3. **Create the Keychain item.**
   - The account is the Session pooler user, `agent_readonly.<project-ref>`.
   - The comment is the Session pooler host from the dashboard's Connect panel.
   - The password is new and random; `-w` given last makes `security` prompt for it.

   ```bash
   security add-generic-password -U -s alz-core-agent-db -a 'agent_readonly.<project-ref>' -j '<session pooler host>' -w
   ```

4. **Turn the password into a SCRAM verifier** on this machine, so the plaintext never reaches Postgres or its DDL log. Hosted Supabase logs DDL and masks only the plaintext `PASSWORD '...'` form.

   ```bash
   security find-generic-password -s alz-core-agent-db -w | python3 -c '
   import base64, hashlib, hmac, os, sys
   pw = sys.stdin.read().rstrip("\n").encode(); salt = os.urandom(16); it = 4096
   salted = hashlib.pbkdf2_hmac("sha256", pw, salt, it)
   client = hmac.new(salted, b"Client Key", "sha256").digest()
   server = hmac.new(salted, b"Server Key", "sha256").digest()
   b = lambda x: base64.b64encode(x).decode()
   print(f"SCRAM-SHA-256${it}:{b(salt)}${b(hashlib.sha256(client).digest())}:{b(server)}")'
   ```

5. **Set the verifier and enable login** in the dashboard SQL editor:

   ```sql
   ALTER ROLE agent_readonly LOGIN PASSWORD 'SCRAM-SHA-256$4096:...';
   ```

6. **Confirm pgaudit is enabled.** `SELECT extname FROM pg_extension WHERE extname = 'pgaudit';` must return a row. If it doesn't, enable pgaudit under Database, then Extensions. Whether production needs the extension created for the role's `AUDIT` entries is unverified until the first audited query shows one.
7. **Audit the role's production privileges** through the wrapper. It must print no rows:

   ```bash
   bin/agent-db -f database/agent-read/privilege-audit.sql
   ```

8. **Check the audit trail.** Run one `bin/agent-db -c` query on allowlisted columns. In the dashboard's Postgres logs, filter on `AUDIT`: an entry for that query under `agent_readonly` must appear. The entries are kept 7 days on Pro.

## Revoke

Run this in the dashboard SQL editor:

```sql
ALTER ROLE agent_readonly NOLOGIN;
```

The wrapper's next connection fails, and the next deploy leaves the role `NOLOGIN`. A re-run of the migration never changes an existing role. Delete the Keychain item (`security delete-generic-password -s alz-core-agent-db`) if login stays off. To rotate the password, repeat steps 3 to 5 with a new password.

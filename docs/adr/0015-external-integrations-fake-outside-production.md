# External integrations are faked outside production unless opted in

Local contact submissions and lead conversions used to call HelpScout, Slack, Google Ads and Bing for real whenever a token was present, because nothing checked the environment and most vendor hosts are hard-coded with no non-production endpoint. Now each integration's service provider binds its real client only when `APP_ENV` is `production` or that integration's **live opt-in** is set. Otherwise it binds a **recording fake** if a local flow (start, seed, tests, verify, e2e) calls it, else a stub that fails closed and names the opt-in. The swap is at the client-interface binding, so alz-core's own jobs, action claims, completions and events still run (COR-261).

## Considered Options

- **Rely on blank credentials:** rejected, because a token left in `.env` silently reopens the route.
- **Fake at the dispatcher:** rejected, because it skips the jobs, so the contact-action and conversion flows never run locally.
- **One global live switch:** rejected, because opting in to one vendor for a deliberate check would open every vendor.
- **Gate on `APP_ENV=local`:** rejected, because `testing`, CI, verify and any unnamed environment would stay live.

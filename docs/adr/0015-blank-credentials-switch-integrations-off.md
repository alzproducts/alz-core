# Blank credentials switch integrations off

A local checkout holds no production credentials, and the app must still run, more or less, without them. The switch is the credentials themselves: an integration binds its real client only when all of its secret credentials are set (`IntegrationCredentials`). With any of them blank, it binds a **stand-in** outside production, and in production the binding throws `InvalidConfigurationException` naming the blank key, as does the boot check. Identifiers (client ids, customer ids, mailbox ids, channel names, conversion action ids) are not part of the switch.

Stand-ins exist only where a local flow must finish, because the contact and lead flows are queued and a failure there is silent (stuck action, retries, stale-action re-dispatch):

- HelpScout: the conversation write client, the conversation lookup used on retry, and a placeholder system user id (contact flow).
- Slack notifications (contact flow).
- Google Ads and Bing conversions, one stand-in per platform, chosen where the conversion resolver builds its adapter list (lead flow). Each answers `platform()`, `supports()` and `extractClickId()` as the real adapter does, so lead eligibility is unchanged.

A stand-in writes one info line per call (`Integration stand-in swallowed a call`, with the integration, the operation and ids only) and does nothing else. Every other integration keeps failing on use when its credentials are blank.

## Considered Options

- **Per-integration `*_LIVE` flags behind a gate (COR-261, PR 1043):** rejected. The flags are settings the agent they guard against can change, and each flag is itself a route to production. Coverage was by enumeration, so nothing forced the next integration to join, and several configs re-implemented the gate from raw `env()`. The real boundary is that the credentials are absent.
- **A stand-in for every client interface:** rejected, about 40 classes for little value; outside the contact and lead flows a local error is acceptable.
- **Nullable clients:** rejected, they force a null check into every consumer.

## Consequences

- In production, a blank secret for a switched integration fails at boot and at resolve; a stand-in never runs there.
- A mis-set `APP_ENV` outside `production` would swallow contact forms silently, so `config/app.php` keeps `production` as the `APP_ENV` default.
- The verify skill drives the contact and lead journeys against the stand-ins and proves them from the log lines.
- A new integration that a local flow must finish gets a stand-in here and its secrets in `IntegrationCredentials`; any other integration needs nothing.

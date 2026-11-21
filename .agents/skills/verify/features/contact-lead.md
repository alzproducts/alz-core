# Contact and lead

A storefront visitor submits the contact form; the queue worker opens a HelpScout conversation and posts a Slack notice. When the submission carries ad click ids, staff marking it a lead uploads a lead conversion to Google Ads (gclid) and Bing Ads (msclkid). This journey's `up` blanks those four integrations' secrets, so stand-ins answer instead and log one `Integration stand-in swallowed a call` line per call (ADR-0015), and no real conversation, Slack post or upload is made.

## Sub-features

- Contact submission: `POST /api/contact` stores the submission and a pending `helpscout` action; the worker completes it.
- Lead conversion: `POST /api/conversions/lead` queues one `lead_received` action per eligible platform; the worker uploads each.

## How to get to it (user POV)

- Contact: the storefront's contact form.
- Lead: the dashboard's contact submission view, "mark as lead".

## Driving it with verify

Preconditions: a run brought up with the four integrations' secrets blanked, and `doctor` exits 0. The stand-in lines land in the worker's log, `<evidence>/logs/worker/laravel.log`, when the env file logs to the `single` file channel (directly or through `stack`); otherwise read them with `logs worker`.

```bash
.agents/skills/verify/verify up --evidence <dir> --env-override HELPSCOUT_APP_SECRET= --env-override SLACK_BOT_USER_OAUTH_TOKEN= --env-override GOOGLE_ADS_DEVELOPER_TOKEN= --env-override GOOGLE_ADS_CLIENT_SECRET= --env-override GOOGLE_ADS_REFRESH_TOKEN= --env-override BING_ADS_CLIENT_SECRET= --env-override BING_ADS_REFRESH_TOKEN= --env-override BING_ADS_DEVELOPER_TOKEN=
```

Contact:

```bash
.agents/skills/verify/verify http --evidence <dir> POST /api/contact --data '{"form":{"name":"Verify Probe","email":"verify.probe@example.com","phone":"07700 900123","reason":"<a ContactReason label>","message":"Verify probe message"},"consent":{"marketing":false,"statistics":false,"preferences":false,"has_responded":true},"context":{"timestamp":"<now, ISO-8601>"},"attribution":{"gclid":"probe-gclid","msclkid":"probe-msclkid"}}'
.agents/skills/verify/verify db --evidence <dir> "select action_type, status, external_id from customer_service.contact_submission_actions where contact_submission_id = '<id from the response>'"
grep 'Integration stand-in swallowed a call' <dir>/logs/worker/laravel.log
```

- Pass: `status=200` with an `id`; the `helpscout` row is `completed` with a numeric `external_id`; the log holds `helpscout`/`createConversationFromCustomer` and `slack`/`sendContactFormProcessed` stand-in lines.

Lead (on a submission made as above):

```bash
.agents/skills/verify/verify http --evidence <dir> POST /api/conversions/lead --data '{"id":"<submission id>","is_potential_quote":false}'
.agents/skills/verify/verify db --evidence <dir> "select ad_platform, status from customer_service.contact_submission_actions where contact_submission_id = '<submission id>' and action_type = 'lead_received' order by ad_platform"
```

- Pass: `status=202`; `lead_received` rows for `bing` and `google`, both `completed`; the log gains `google_ads`/`upload` and `bing_ads`/`upload` lines.
- Repeat on a fresh contact submission carrying `gclid` only, then one carrying `msclkid` only: exactly one row each, `google` and `bing` respectively.

## Gotchas

- `reason` must be an exact `ContactReason` label (`app/Domain/ContactSubmission/Enums/ContactReason.php`); anything else is a 422.
- Horizon completes the rows asynchronously: re-read a `pending` row before calling it a failure.
- The stand-in lines carry ids only; a name, email, phone or message in them is a failure.
- A run brought up without the blanking reaches the real vendors with the env file's keys.

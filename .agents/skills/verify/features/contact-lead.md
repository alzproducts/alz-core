# Contact and lead

A storefront visitor submits the contact form; the queue worker opens a HelpScout conversation and posts a Slack notice. When the submission carries ad click ids, staff marking it a lead uploads a lead conversion to Google Ads (gclid) and Bing Ads (msclkid). In a verify run every vendor secret is blank, so stand-ins answer instead and log one `Integration stand-in swallowed a call` line per call (ADR-0015).

## Sub-features

- Contact submission: `POST /api/contact` stores the submission and a pending `helpscout` action; the worker completes it.
- Lead conversion: `POST /api/conversions/lead` queues one `lead_received` action per eligible platform; the worker uploads each.

## How to get to it (user POV)

- Contact: the storefront's contact form.
- Lead: the dashboard's contact submission view, "mark as lead".

## Driving it with verify.sh

Preconditions: a launched run (`doctor` exits 0). The log is the worktree's `storage/logs/laravel.log` (the run sets `LOG_CHANNEL=single`).

Contact:

```bash
bash .agents/skills/verify/scripts/verify.sh http --evidence <dir> POST /api/contact --data '{"form":{"name":"Verify Probe","email":"verify.probe@example.com","phone":"07700 900123","reason":"<a ContactReason label>","message":"Verify probe message"},"consent":{"marketing":false,"statistics":false,"preferences":false,"has_responded":true},"context":{"timestamp":"<now, ISO-8601>"},"attribution":{"gclid":"probe-gclid","msclkid":"probe-msclkid"}}'
bash .agents/skills/verify/scripts/verify.sh db --evidence <dir> "select action_type, status, external_id from customer_service.contact_submission_actions where contact_submission_id = '<id from the response>'"
grep 'Integration stand-in swallowed a call' storage/logs/laravel.log
```

- Pass: [SKILL.md §Contact and lead journeys](../SKILL.md#contact-and-lead-journeys), contact bullet.

Lead (on a submission made as above):

```bash
bash .agents/skills/verify/scripts/verify.sh http --evidence <dir> POST /api/conversions/lead --data '{"id":"<submission id>","is_potential_quote":false}'
bash .agents/skills/verify/scripts/verify.sh db --evidence <dir> "select ad_platform, status from customer_service.contact_submission_actions where contact_submission_id = '<submission id>' and action_type = 'lead_received' order by ad_platform"
```

- Pass: [SKILL.md §Contact and lead journeys](../SKILL.md#contact-and-lead-journeys), lead bullet.
- Repeat on a fresh contact submission carrying `gclid` only, then one carrying `msclkid` only: exactly one row each, `google` and `bing` respectively.

## Gotchas

- `reason` must be an exact `ContactReason` label (`app/Domain/ContactSubmission/Enums/ContactReason.php`); anything else is a 422.
- The queue worker completes the rows asynchronously: re-read a `pending` row before calling it a failure.
- The stand-in lines carry ids only; a name, email, phone or message in them is a failure.

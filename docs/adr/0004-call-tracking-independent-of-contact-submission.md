# Call tracking as an independent system, not a ContactSubmission extension

Call-attributed conversions have their own domain model, tables and submit use case. They share only the platform upload layer with contact submissions. `ConversionSubmissionOrchestratorService` routes by UUID at submission time and is the only coupling point; a third conversion source follows the same pattern.

## Considered Options

- **Promote calls to ContactSubmission:** rejected. A phone call is not a form submission.
- **Abstract ConversionSource interface:** rejected. It touches every layer for a problem that does not exist at scale.

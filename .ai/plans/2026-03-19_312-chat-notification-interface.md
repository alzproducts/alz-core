# Plan: ChatNotificationInterface — Domain-Data-In, Framework-Out

## Context

All 5 Slack listeners duplicate boilerplate (config lookup, channel guard, `Notification::route()`) and directly construct Laravel Notification objects. This couples every listener to the Laravel Notification framework. The `ChatNotificationInterface` inverts this: callers pass domain data, Infrastructure handles all framework mechanics internally — Notification construction, BlockKit formatting, channel resolution.

## Design Principle

The interface accepts **domain-level data only**. Each method maps to a distinct notification the system sends. The Infrastructure implementation builds the Laravel Notification object, resolves the channel from config, and delivers. If the interface grows large, that's an honest signal about notification count — not a design problem to abstract away.

## Interface: `Application/Contracts/ChatNotificationInterface.php`

```php
interface ChatNotificationInterface
{
    public function sendAdminAlert(
        string $title,
        string $message,
        array $context,
        DateTimeImmutable $firedAt,
    ): void;

    public function sendPriceUpdateAlert(
        IntId $productId,
        array $priceChanges,  // list<SkuPriceChange>
    ): void;

    public function sendContactFormProcessed(
        IntId $conversationId,
        string $customerName,
        string $customerEmail,
    ): void;

    public function sendContactFormFailed(
        ContactSubmission $submission,
        string $submissionId,
        string $errorMessage,
        ?bool $emailValid,
    ): void;

    public function sendVariantSkusGenerated(
        int $productId,
        string $productTitle,
        int $created,
        int $skipped,
        int $failed,
        array $createdVariants,  // list<string>
    ): void;
}
```

Each method silently skips if its channel is not configured (consistent with current behaviour). No return value needed — fire-and-forget from the caller's perspective.

### Channel Routing

The implementation owns channel routing. Callers don't specify channels — each notification type has a fixed channel:

| Method | Channel Config Key |
|--------|-------------------|
| `sendAdminAlert` | `admin_alerts_channel` |
| `sendPriceUpdateAlert` | `verbose_channel` |
| `sendContactFormProcessed` | `verbose_channel` |
| `sendContactFormFailed` | `channel` (default) |
| `sendVariantSkusGenerated` | `channel` (default) |

The `SlackChannel` enum still exists internally in the implementation as a private concern (or as a simple match/mapping). It does NOT need to be in Application — callers don't specify channels.

## Implementation: `Infrastructure/Notifications/SlackChatNotificationClient.php`

```php
final readonly class SlackChatNotificationClient implements ChatNotificationInterface
{
    public function sendAdminAlert(...): void
    {
        $this->send('admin_alerts_channel', new AdminAlertNotification(...));
    }

    public function sendPriceUpdateAlert(...): void
    {
        $this->send('verbose_channel', new ProductPricingUpdatedNotification(...));
    }

    // ... other methods follow same pattern

    private function send(string $configKey, Notification $notification): void
    {
        $channel = config("services.slack.notifications.{$configKey}");
        if (!is_string($channel) || $channel === '') {
            return;
        }
        Notification::route('slack', $channel)->notify($notification);
    }
}
```

All framework concerns (`Notification::route()`, BlockKit formatting, config access) are encapsulated. The existing `*Notification` classes stay unchanged — they're internal to Infrastructure.

## Listener Migration

Listeners become thin event→interface bridges. No more config checks, no Notification construction:

**Before (ProductPricingUpdatedSlackListener):**
```php
public function handle(ProductPricingUpdatedEvent $event): void
{
    $channel = config('services.slack.notifications.verbose_channel');
    if (!is_string($channel) || $channel === '') { return; }
    Notification::route('slack', $channel)
        ->notify(new ProductPricingUpdatedNotification(
            productId: $event->productId->value,
            priceChanges: $event->priceChanges,
        ));
}
```

**After:**
```php
public function __construct(
    private readonly ChatNotificationInterface $chat,
) {}

public function handle(ProductPricingUpdatedEvent $event): void
{
    $this->chat->sendPriceUpdateAlert(
        productId: $event->productId,
        priceChanges: $event->priceChanges,
    );
}
```

### ContactFormFailedSlackListener (enrichment case)

This listener keeps its repository injection for enrichment. It fetches the submission, then passes domain data to the interface:

```php
public function __construct(
    private readonly ContactSubmissionRepositoryInterface $submissionRepository,
    private readonly ChatNotificationInterface $chat,
) {}

public function handle(ContactFormProcessingFailedEvent $event): void
{
    $submission = $this->submissionRepository->findById($event->submissionId);

    $this->chat->sendContactFormFailed(
        submission: $submission,
        submissionId: $event->submissionId,
        errorMessage: $event->exceptionMessage,
        emailValid: $event->emailValid,
    );
}
```

### AdminAlert Review

AdminAlert already follows the correct pattern: domain data → Infrastructure notification. The migration is mechanical — same as the other 4. No structural changes needed beyond injecting `ChatNotificationInterface`.

**Future consideration:** Since `AdminAlertEvent` is dispatched from Application UseCases and is explicitly a notification request (not a domain event with notification as side-effect), those UseCases could eventually call `$this->chat->sendAdminAlert(...)` directly instead of dispatching an event. But that's a separate scope — the event+listener pattern is fine for now and provides async/queue benefits.

## UseCase Extraction: Not Required

None of the 5 listeners need UseCase extraction. The interface itself absorbs what would have been the UseCase's role — accepting domain data and delegating to Infrastructure. The listeners are now just event→interface bridges.

## Changes

### Phase 1: Foundation (2 new files + 2 edits)

| Action | File | Notes |
|--------|------|-------|
| Create | `app/Application/Contracts/ChatNotificationInterface.php` | 5 methods, domain types only |
| Create | `app/Infrastructure/Notifications/SlackChatNotificationClient.php` | Implements interface, owns channel routing |
| Edit | Service provider | Bind `ChatNotificationInterface` → `SlackChatNotificationClient` |
| Edit | `phparkitect.php` | Only if naming rule triggers (client naming convention check) |

### Phase 2: Migrate 5 Listeners (5 edits)

| File | Notes |
|------|-------|
| `AdminAlertSlackListener.php` | Replace config+route with `$this->chat->sendAdminAlert(...)` |
| `ProductPricingUpdatedSlackListener.php` | Replace with `$this->chat->sendPriceUpdateAlert(...)` |
| `ContactFormProcessedSlackListener.php` | Replace with `$this->chat->sendContactFormProcessed(...)` |
| `VariantSkusGeneratedSlackListener.php` | Replace with `$this->chat->sendVariantSkusGenerated(...)` |
| `ContactFormFailedSlackListener.php` | Keep repo injection, add chat, replace notification construction |

### Phase 3: Tests

| File | What |
|------|------|
| `tests/Unit/Infrastructure/Notifications/SlackChatNotificationClientTest.php` | Each method: sends when configured, skips when not, passes correct data to Notification |

## Verification

1. `make lint` — PHPArkitect + Deptrac validate no layer violations
2. `make test` — all existing tests pass
3. `php artisan slack:test` — manual verification notifications still deliver

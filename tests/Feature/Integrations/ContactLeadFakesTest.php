<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Application\Contracts\ChatNotificationInterface;
use App\Application\Contracts\HelpScout\AgentsClientInterface;
use App\Application\Contracts\HelpScout\ConnectivityClientInterface;
use App\Application\Contracts\HelpScout\ContactConversationLookupInterface;
use App\Application\Contracts\HelpScout\ConversationsClientInterface;
use App\Application\Contracts\HelpScout\ConversationWriteClientInterface;
use App\Application\Contracts\HelpScout\MailboxesClientInterface;
use App\Application\Conversion\Enums\AdPlatform;
use App\Application\Conversion\Services\AdPlatformAdapterResolverService;
use App\Application\HelpScout\Config\HelpScoutSystemUserId;
use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\ContactSubmission\Events\ContactFormProcessedEvent;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\Conversion\Enums\ConversionType;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\Conversion\Fakes\RecordingAdPlatformConversionAdapter;
use App\Infrastructure\HelpScout\Fakes\RecordingAgentsClient;
use App\Infrastructure\HelpScout\Fakes\RecordingConnectivityClient;
use App\Infrastructure\HelpScout\Fakes\RecordingContactConversationLookup;
use App\Infrastructure\HelpScout\Fakes\RecordingConversationsClient;
use App\Infrastructure\HelpScout\Fakes\RecordingConversationWriteClient;
use App\Infrastructure\HelpScout\Fakes\RecordingMailboxesClient;
use App\Infrastructure\Integrations\LiveIntegrationGate;
use App\Infrastructure\Integrations\OutboundCallRecorder;
use App\Infrastructure\Jobs\ContactForm\ProcessContactSubmissionJob;
use App\Infrastructure\Jobs\Conversion\ProcessLeadConversionJob;
use App\Infrastructure\Notifications\Fakes\RecordingChatNotification;
use App\Infrastructure\Notifications\SlackChatNotificationClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Stringable;
use Tests\Feature\Concerns\AuthenticatesAsApprovedUser;
use Tests\TestCase;

/**
 * Runs the contact and lead flows end to end with no `*_LIVE` opt-in (phpunit pins them all false),
 * asserting on rows this test creates inside a rolled-back transaction.
 */
final class ContactLeadFakesTest extends TestCase
{
    use AuthenticatesAsApprovedUser;
    use DatabaseTransactions;

    private const string ACTIONS = 'customer_service.contact_submission_actions';

    private const string NAME = 'Fakes Probe Customer';

    private const string EMAIL = 'fakes.probe.customer@example.com';

    private const string PHONE = '07700 900123';

    private const string MESSAGE = 'Probe message that must never reach a log line';

    /** @var list<array{message: string, context: array<mixed>}> */
    private array $records = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $records = &$this->records;
        $logger = new class ($records) extends AbstractLogger {
            /** @param list<array{message: string, context: array<mixed>}> $records */
            public function __construct(private array &$records) {}

            /** @param array<mixed> $context */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };
        $this->app->instance(OutboundCallRecorder::class, new OutboundCallRecorder($logger));

        // The jobs run below through handle(); faking them stops the sync queue running them inline at submit.
        Queue::fake([ProcessContactSubmissionJob::class, ProcessLeadConversionJob::class]);
    }

    #[Test]
    public function every_contact_and_lead_seam_resolves_to_its_recording_fake(): void
    {
        $this->assertInstanceOf(RecordingConversationWriteClient::class, $this->app->make(ConversationWriteClientInterface::class));
        $this->assertInstanceOf(RecordingContactConversationLookup::class, $this->app->make(ContactConversationLookupInterface::class));
        $this->assertInstanceOf(RecordingConversationsClient::class, $this->app->make(ConversationsClientInterface::class));
        $this->assertInstanceOf(RecordingMailboxesClient::class, $this->app->make(MailboxesClientInterface::class));
        $this->assertInstanceOf(RecordingAgentsClient::class, $this->app->make(AgentsClientInterface::class));
        $this->assertInstanceOf(RecordingConnectivityClient::class, $this->app->make(ConnectivityClientInterface::class));
        $this->assertInstanceOf(RecordingChatNotification::class, $this->app->make(ChatNotificationInterface::class));
        $this->assertSame(1, $this->app->make(HelpScoutSystemUserId::class)->value->value);

        $resolver = $this->app->make(AdPlatformAdapterResolverService::class);
        $this->assertInstanceOf(RecordingAdPlatformConversionAdapter::class, $resolver->adapterFor(AdPlatform::Google));
        $this->assertInstanceOf(RecordingAdPlatformConversionAdapter::class, $resolver->adapterFor(AdPlatform::Bing));
    }

    #[Test]
    public function the_contact_flow_completes_against_the_fakes(): void
    {
        $processed = [];
        Event::listen(ContactFormProcessedEvent::class, static function (ContactFormProcessedEvent $event) use (&$processed): void {
            $processed[] = $event;
        });

        $submissionId = $this->submitContactForm();
        $action = $this->actionsFor($submissionId, 'helpscout')[0];
        $this->assertSame('pending', $action->status);
        $this->assertSame(0, $action->attempts);

        $this->app->call([new ProcessContactSubmissionJob($submissionId, $action->id), 'handle']);

        $completed = $this->actionsFor($submissionId, 'helpscout')[0];
        $this->assertSame('completed', $completed->status);
        $this->assertSame(1, $completed->attempts);
        $this->assertNotNull($completed->processing_started_at);
        $this->assertMatchesRegularExpression('/^[1-9]\d*$/', (string) $completed->external_id);
        $this->assertCount(1, $processed);
        $this->assertSame($submissionId, $processed[0]->submissionId);
        $this->assertSame((int) $completed->external_id, $processed[0]->conversationId->value);
        $this->assertContains('helpscout.createConversationFromCustomer', $this->recordedOperations());
        $this->assertContains('slack.sendContactFormProcessed', $this->recordedOperations());
        $this->assertNoPayloadFieldsLogged();
    }

    #[Test]
    public function the_lead_flow_uploads_to_both_platforms_against_the_fakes(): void
    {
        $submissionId = $this->submitContactForm(['gclid' => 'probe-gclid', 'msclkid' => 'probe-msclkid']);

        $this->asApprovedUser()
            ->postJson('/api/conversions/lead', ['id' => $submissionId, 'is_potential_quote' => false])
            ->assertStatus(202);

        $leads = $this->actionsFor($submissionId, 'lead_received');
        $this->assertSame(['bing', 'google'], \array_map(static fn(object $row): string => $row->ad_platform, $leads));

        foreach ($leads as $lead) {
            $this->app->call([new ProcessLeadConversionJob($submissionId, $lead->id, AdPlatform::from($lead->ad_platform)), 'handle']);
        }

        foreach ($this->actionsFor($submissionId, 'lead_received') as $uploaded) {
            $this->assertSame('completed', $uploaded->status);
            $this->assertSame('uploaded', $uploaded->external_id);
        }
        $this->assertContains('google_ads.upload', $this->recordedOperations());
        $this->assertContains('bing_ads.upload', $this->recordedOperations());
        $this->assertNoPayloadFieldsLogged();
    }

    #[Test]
    public function quote_conversions_skip_bing(): void
    {
        $attribution = new MarketingAttribution(gclid: 'probe-gclid', msclkid: 'probe-msclkid');
        $resolver = $this->app->make(AdPlatformAdapterResolverService::class);

        $this->assertSame([AdPlatform::Google], $resolver->eligiblePlatforms(ConversionType::QuoteIssued, $attribution));
        $this->assertSame([AdPlatform::Google, AdPlatform::Bing], $resolver->eligiblePlatforms(ConversionType::LeadReceived, $attribution));
    }

    #[Test]
    public function one_opt_in_makes_only_that_integration_real(): void
    {
        \config(['integrations.live.slack' => true]);
        $this->app->forgetInstance(LiveIntegrationGate::class);

        $this->assertInstanceOf(SlackChatNotificationClient::class, $this->app->make(ChatNotificationInterface::class));
        $this->assertInstanceOf(RecordingConversationWriteClient::class, $this->app->make(ConversationWriteClientInterface::class));
        $this->assertInstanceOf(
            RecordingAdPlatformConversionAdapter::class,
            $this->app->make(AdPlatformAdapterResolverService::class)->adapterFor(AdPlatform::Google),
        );
    }

    #[Test]
    public function an_unparseable_opt_in_fails_closed_naming_its_setting(): void
    {
        // A value FILTER_VALIDATE_BOOL cannot parse (e.g. `maybe`) reaches config as null.
        \config(['integrations.live.helpscout' => null]);
        $this->app->forgetInstance(LiveIntegrationGate::class);

        try {
            $this->app->make(ConversationWriteClientInterface::class);
            $this->fail('Expected the unparseable HELPSCOUT_LIVE flag to throw');
        } catch (InvalidConfigurationException $e) {
            $this->assertSame('HELPSCOUT_LIVE', $e->configKey);
        }
    }

    /**
     * @param array<string, string> $attribution
     */
    private function submitContactForm(array $attribution = []): string
    {
        $payload = [
            'form' => [
                'name' => self::NAME,
                'email' => self::EMAIL,
                'phone' => self::PHONE,
                'reason' => ContactReason::cases()[0]->label(),
                'message' => self::MESSAGE,
            ],
            'consent' => ['marketing' => false, 'statistics' => false, 'preferences' => false, 'has_responded' => true],
            'context' => ['timestamp' => \now()->toIso8601String()],
        ];
        if ($attribution !== []) {
            $payload['attribution'] = $attribution;
        }

        $id = $this->postJson('/api/contact', $payload)->assertOk()->json('id');
        $this->assertIsString($id);

        return $id;
    }

    /**
     * @return list<object{id: string, status: string, attempts: int, processing_started_at: ?string, external_id: ?string, ad_platform: ?string}>
     */
    private function actionsFor(string $submissionId, string $actionType): array
    {
        /** @var list<object{id: string, status: string, attempts: int, processing_started_at: ?string, external_id: ?string, ad_platform: ?string}> */
        return DB::table(self::ACTIONS)
            ->where('contact_submission_id', $submissionId)
            ->where('action_type', $actionType)
            ->orderBy('ad_platform')
            ->get(['id', 'status', 'attempts', 'processing_started_at', 'external_id', 'ad_platform'])
            ->all();
    }

    /**
     * @return list<string>
     */
    private function recordedOperations(): array
    {
        $operations = [];
        foreach ($this->records as $record) {
            if ($record['message'] === OutboundCallRecorder::MESSAGE) {
                $operations[] = $record['context']['integration'] . '.' . $record['context']['operation'];
            }
        }

        return $operations;
    }

    private function assertNoPayloadFieldsLogged(): void
    {
        $this->assertNotSame([], $this->records);
        $logged = \json_encode($this->records, JSON_THROW_ON_ERROR);

        foreach ([self::NAME, self::EMAIL, self::PHONE, self::MESSAGE] as $payloadField) {
            $this->assertStringNotContainsString($payloadField, $logged);
        }
    }
}

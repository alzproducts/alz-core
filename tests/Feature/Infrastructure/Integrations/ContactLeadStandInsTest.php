<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Integrations;

use App\Application\Conversion\Enums\AdPlatform;
use App\Application\Conversion\Services\AdPlatformAdapterResolverService;
use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\ContactSubmission\Events\ContactFormProcessedEvent;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\Conversion\Enums\ConversionType;
use App\Infrastructure\Integrations\StandInCallLogger;
use App\Infrastructure\Jobs\ContactForm\ProcessContactSubmissionJob;
use App\Infrastructure\Jobs\Conversion\ProcessLeadConversionJob;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Stringable;
use Tests\Feature\Concerns\AuthenticatesAsApprovedUser;
use Tests\TestCase;

/**
 * Runs the contact and lead flows end to end with every switched secret blank (phpunit pins them empty),
 * asserting on rows this test creates inside a rolled-back transaction.
 */
#[CoversClass(StandInCallLogger::class)]
final class ContactLeadStandInsTest extends TestCase
{
    use AuthenticatesAsApprovedUser;
    use DatabaseTransactions;

    private const string ACTIONS = 'customer_service.contact_submission_actions';

    private const string NAME = 'Stand-in Probe Customer';

    private const string EMAIL = 'standin.probe.customer@example.com';

    private const string PHONE = '07700 900123';

    private const string MESSAGE = 'Probe message that must never reach a log line';

    /** @var list<array{message: string, context: array<mixed>}> */
    private array $records = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->detectEnvironment(static fn(): string => 'local');

        $records = &$this->records;
        $logger = new class ($records) extends AbstractLogger {
            /** @param list<array{message: string, context: array<mixed>}> $records */
            public function __construct(private array &$records) {}

            /** @param array<mixed> $context */
            #[Override]
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };
        $this->app->instance(StandInCallLogger::class, new StandInCallLogger($logger));

        // The jobs run below through handle(); faking them stops the sync queue running them inline at submit.
        Queue::fake([ProcessContactSubmissionJob::class, ProcessLeadConversionJob::class]);
    }

    #[Test]
    public function the_contact_flow_completes_against_the_stand_ins(): void
    {
        $processed = [];
        Event::listen(ContactFormProcessedEvent::class, static function (ContactFormProcessedEvent $event) use (&$processed): void {
            $processed[] = $event;
        });

        $submissionId = $this->submitContactForm();
        $action = $this->actionsFor($submissionId, 'helpscout')[0];
        $this->assertSame('pending', $action->status);

        $this->app->call([new ProcessContactSubmissionJob($submissionId, $action->id), 'handle']);

        $completed = $this->actionsFor($submissionId, 'helpscout')[0];
        $this->assertSame('completed', $completed->status);
        $this->assertSame(1, $completed->attempts);
        $this->assertMatchesRegularExpression('/^[1-9]\d*$/', (string) $completed->external_id);
        $this->assertCount(1, $processed);
        $this->assertSame((int) $completed->external_id, $processed[0]->conversationId->value);
        $operations = \array_count_values($this->recordedOperations());
        $this->assertSame(1, $operations['helpscout.createConversationFromCustomer'] ?? 0);
        $this->assertSame(1, $operations['slack.sendContactFormProcessed'] ?? 0);
        $this->assertNoPayloadFieldsLogged();
    }

    #[Test]
    public function the_lead_flow_uploads_to_both_platforms_against_the_stand_ins(): void
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
        }
        $this->assertEqualsCanonicalizing(['google_ads.upload', 'bing_ads.upload'], $this->recordedOperations());
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
     * @return list<object{id: string, status: string, attempts: int, external_id: ?string, ad_platform: ?string}>
     */
    private function actionsFor(string $submissionId, string $actionType): array
    {
        /** @var list<object{id: string, status: string, attempts: int, external_id: ?string, ad_platform: ?string}> */
        return DB::table(self::ACTIONS)
            ->where('contact_submission_id', $submissionId)
            ->where('action_type', $actionType)
            ->orderBy('ad_platform')
            ->get(['id', 'status', 'attempts', 'external_id', 'ad_platform'])
            ->all();
    }

    /**
     * @return list<string>
     */
    private function recordedOperations(): array
    {
        $operations = [];
        foreach ($this->records as $record) {
            if ($record['message'] === StandInCallLogger::MESSAGE) {
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

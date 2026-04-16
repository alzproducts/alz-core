<?php

declare(strict_types=1);

namespace Tests\Integration\ContactSubmission;

use App\Application\ContactSubmission\UseCases\ProcessContactSubmissionUseCase;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\HelpScout\ConversationWriteClientInterface;
use App\Application\HelpScout\Commands\CreateCustomerConversationCommand;
use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\Customer\Enums\CustomerType;
use App\Domain\CustomerService\Enums\Mailbox;
use App\Infrastructure\Ingest\ContactSubmission\Models\ContactSubmissionActionModel;
use App\Infrastructure\Ingest\ContactSubmission\Models\ContactSubmissionModel;
use App\Infrastructure\Jobs\ContactForm\ProcessContactSubmissionJob;
use Faker\Factory as Faker;
use Faker\Generator;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * End-to-end integration test for the contact form flow.
 *
 * Tests the complete journey from HTTP request → database → HelpScout (mocked).
 * Uses Faker to generate realistic test data.
 *
 * Database cleanup: Tests clean up their own data in tearDown() since
 * this project uses a shared Supabase database (no RefreshDatabase).
 */
#[CoversClass(ProcessContactSubmissionJob::class)]
final class ContactFormEndToEndTest extends TestCase
{
    private Generator $faker;
    private int $mockConversationId;

    /** @var list<string> Submission IDs to clean up */
    private array $createdSubmissionIds = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->faker = Faker::create('en_GB');
        $this->mockConversationId = $this->faker->numberBetween(1000000, 9999999);
    }

    #[Override]
    protected function tearDown(): void
    {
        // Clean up test data (order matters - actions first due to FK)
        foreach ($this->createdSubmissionIds as $submissionId) {
            ContactSubmissionActionModel::query()
                ->where('contact_submission_id', $submissionId)
                ->delete();

            ContactSubmissionModel::query()
                ->where('id', $submissionId)
                ->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function complete_flow_creates_submission_and_helpscout_conversation(): void
    {
        // Arrange: Fake queue so job doesn't run synchronously
        Queue::fake();

        // Arrange: Mock HelpScout client to capture what we send
        $capturedCommand = null;
        $this->mockHelpScoutClient(function (CreateCustomerConversationCommand $command) use (&$capturedCommand): int {
            $capturedCommand = $command;

            return $this->mockConversationId;
        });

        $payload = $this->buildFakePayload();

        // Act 1: Submit the form (creates submission + queues job)
        $response = $this->postJson('/api/contact', $payload);

        // Assert 1: HTTP response is correct
        $response->assertOk();
        $submissionId = $response->json('id');
        self::assertNotNull($submissionId);
        self::assertIsString($submissionId);

        // Track for cleanup
        $this->createdSubmissionIds[] = $submissionId;

        // Assert 2: Submission persisted correctly
        $submission = ContactSubmissionModel::query()->where('id', $submissionId)->first();
        self::assertNotNull($submission);
        self::assertSame($payload['form']['email'], $submission->email);
        self::assertSame($payload['form']['name'], $submission->name);
        self::assertSame($payload['form']['message'], $submission->message);

        // Assert 3: Action created with pending status
        $action = ContactSubmissionActionModel::query()
            ->where('contact_submission_id', $submissionId)
            ->first();
        self::assertNotNull($action);
        self::assertSame(ActionStatus::Pending, $action->status);

        // Act 2: Process the job (sends to HelpScout)
        $job = new ProcessContactSubmissionJob($submissionId, $action->id);
        $job->handle(
            \app(ProcessContactSubmissionUseCase::class),
            \app(ContactSubmissionActionRepositoryInterface::class),
            \app(ContactSubmissionRepositoryInterface::class),
        );

        // Assert 4: HelpScout received correct data
        self::assertNotNull($capturedCommand, 'HelpScout client should have been called');
        self::assertSame(\mb_strtolower($payload['form']['email']), $capturedCommand->email);
        self::assertSame($payload['form']['name'], $capturedCommand->name);
        self::assertSame(Mailbox::Support, $capturedCommand->mailbox);
        self::assertStringContainsString($payload['form']['message'], $capturedCommand->body);

        // Assert 5: Action marked as completed with conversation ID
        $action->refresh();
        self::assertSame(ActionStatus::Completed, $action->status);
        self::assertSame((string) $this->mockConversationId, $action->external_id);
    }

    #[Test]
    public function submission_with_product_includes_product_in_helpscout_body(): void
    {
        $capturedCommand = null;
        $this->mockHelpScoutClient(function (CreateCustomerConversationCommand $command) use (&$capturedCommand): int {
            $capturedCommand = $command;

            return $this->mockConversationId;
        });

        $payload = $this->buildFakePayloadWithProduct();

        // Submit and process
        $response = $this->postJson('/api/contact', $payload);
        $response->assertOk();
        $submissionId = $response->json('id');
        $this->createdSubmissionIds[] = $submissionId;

        $action = ContactSubmissionActionModel::query()
            ->where('contact_submission_id', $submissionId)
            ->first();

        $job = new ProcessContactSubmissionJob($submissionId, $action->id);
        $job->handle(
            \app(ProcessContactSubmissionUseCase::class),
            \app(ContactSubmissionActionRepositoryInterface::class),
            \app(ContactSubmissionRepositoryInterface::class),
        );

        // Assert product details in body
        self::assertNotNull($capturedCommand);
        self::assertStringContainsString("<strong>Product:</strong> <a href=\"{$payload['product']['url']}\">{$payload['product']['title']}</a> - {$payload['product']['sku']}", $capturedCommand->body);
        self::assertStringContainsString("<strong>Price:</strong> {$payload['product']['price']}", $capturedCommand->body);
    }

    #[Test]
    public function submission_with_customer_type_includes_metadata_in_helpscout_body(): void
    {
        $capturedCommand = null;
        $this->mockHelpScoutClient(function (CreateCustomerConversationCommand $command) use (&$capturedCommand): int {
            $capturedCommand = $command;

            return $this->mockConversationId;
        });

        $payload = $this->buildFakePayload();
        $payload['form']['customer_type'] = 'nhs';
        $payload['form']['order_number'] = 'ORD-' . $this->faker->numerify('######');
        $payload['form']['delivery_postcode'] = $this->faker->postcode();

        $response = $this->postJson('/api/contact', $payload);
        $response->assertOk();
        $submissionId = $response->json('id');
        $this->createdSubmissionIds[] = $submissionId;

        $action = ContactSubmissionActionModel::query()
            ->where('contact_submission_id', $submissionId)
            ->first();

        $job = new ProcessContactSubmissionJob($submissionId, $action->id);
        $job->handle(
            \app(ProcessContactSubmissionUseCase::class),
            \app(ContactSubmissionActionRepositoryInterface::class),
            \app(ContactSubmissionRepositoryInterface::class),
        );

        // Assert customer metadata in body
        self::assertNotNull($capturedCommand);
        self::assertStringContainsString('<strong>Customer Type:</strong> ' . CustomerType::Nhs->label(), $capturedCommand->body);
        self::assertStringContainsString("<strong>Order Number:</strong> {$payload['form']['order_number']}", $capturedCommand->body);
        self::assertStringContainsString("<strong>Delivery Postcode:</strong> {$payload['form']['delivery_postcode']}", $capturedCommand->body);
    }

    #[Test]
    public function job_is_dispatched_to_queue_on_submission(): void
    {
        Queue::fake();

        $this->mockHelpScoutClient(fn(): int => $this->mockConversationId);

        $payload = $this->buildFakePayload();

        $response = $this->postJson('/api/contact', $payload);
        $response->assertOk();
        $this->createdSubmissionIds[] = $response->json('id');

        Queue::assertPushed(ProcessContactSubmissionJob::class, static fn(ProcessContactSubmissionJob $job): bool => $job->submissionId !== '' && $job->actionId !== '');
    }

    #[Test]
    public function pii_is_excluded_from_helpscout_body(): void
    {
        $capturedCommand = null;
        $this->mockHelpScoutClient(function (CreateCustomerConversationCommand $command) use (&$capturedCommand): int {
            $capturedCommand = $command;

            return $this->mockConversationId;
        });

        $payload = $this->buildFakePayloadWithAllFields();

        $response = $this->postJson('/api/contact', $payload);
        $response->assertOk();
        $submissionId = $response->json('id');
        $this->createdSubmissionIds[] = $submissionId;

        $action = ContactSubmissionActionModel::query()
            ->where('contact_submission_id', $submissionId)
            ->first();

        $job = new ProcessContactSubmissionJob($submissionId, $action->id);
        $job->handle(
            \app(ProcessContactSubmissionUseCase::class),
            \app(ContactSubmissionActionRepositoryInterface::class),
            \app(ContactSubmissionRepositoryInterface::class),
        );

        // Assert PII is NOT in the body
        self::assertNotNull($capturedCommand);
        self::assertStringNotContainsString($payload['attribution']['gclid'], $capturedCommand->body);
        self::assertStringNotContainsString($payload['attribution']['msclkid'], $capturedCommand->body);
        self::assertStringNotContainsString($payload['attribution']['fbclid'], $capturedCommand->body);
        self::assertStringNotContainsString($payload['attribution']['utm_source'], $capturedCommand->body);
        self::assertStringNotContainsString($payload['context']['user_agent'], $capturedCommand->body);
        self::assertStringNotContainsString($payload['context']['page_url'], $capturedCommand->body);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * @param callable(CreateCustomerConversationCommand): int $callback
     */
    private function mockHelpScoutClient(callable $callback): void
    {
        $this->mock(
            ConversationWriteClientInterface::class,
            static function (MockInterface $mock) use ($callback): void {
                $mock->shouldReceive('createConversationFromCustomer')
                    ->andReturnUsing($callback);
            },
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFakePayload(): array
    {
        return [
            'form' => [
                'name' => $this->faker->name(),
                'email' => $this->faker->safeEmail(),
                'reason' => $this->faker->randomElement(ContactReason::cases())->label(),
                'message' => $this->faker->paragraph(3),
            ],
            'consent' => [
                'marketing' => $this->faker->boolean(),
                'statistics' => $this->faker->boolean(),
                'preferences' => $this->faker->boolean(),
                'has_responded' => true,
            ],
            'context' => [
                'timestamp' => \now()->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFakePayloadWithProduct(): array
    {
        $payload = $this->buildFakePayload();
        $payload['product'] = [
            'product_id' => $this->faker->numberBetween(10000, 99999),
            'sku' => $this->faker->regexify('[A-Z]{2,4}-[0-9]{3,5}'),
            'source' => 'recently_viewed',
            'title' => $this->faker->words(4, true),
            'price' => '£' . $this->faker->randomFloat(2, 10, 500),
            'quantity' => $this->faker->numberBetween(1, 5),
            'url' => $this->faker->url(),
        ];

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFakePayloadWithAllFields(): array
    {
        $payload = $this->buildFakePayloadWithProduct();

        $payload['form']['phone'] = $this->faker->phoneNumber();
        $payload['form']['customer_type'] = $this->faker->randomElement([
            'personal', 'nhs', 'government', 'care_home', 'charity', 'other_business',
        ]);
        $payload['form']['order_number'] = 'ORD-' . $this->faker->numerify('######');
        $payload['form']['delivery_postcode'] = $this->faker->postcode();

        $payload['attribution'] = [
            'gclid' => $this->faker->regexify('[a-zA-Z0-9]{20,40}'),
            'msclkid' => $this->faker->regexify('[a-zA-Z0-9]{20,40}'),
            'fbclid' => $this->faker->regexify('[a-zA-Z0-9]{20,40}'),
            'utm_source' => $this->faker->randomElement(['google', 'facebook', 'email']),
            'utm_medium' => $this->faker->randomElement(['cpc', 'organic', 'social']),
            'utm_campaign' => $this->faker->slug(3),
        ];

        $payload['context']['page_url'] = $this->faker->url();
        $payload['context']['referrer_url'] = $this->faker->url();
        $payload['context']['user_agent'] = $this->faker->userAgent();

        $payload['user'] = [
            'customer_id' => $this->faker->uuid(),
            'session_id' => $this->faker->uuid(),
        ];

        return $payload;
    }
}

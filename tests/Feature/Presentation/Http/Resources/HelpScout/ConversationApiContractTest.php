<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Resources\HelpScout;

use App\Domain\CustomerService\ValueObjects\Conversation;
use App\Domain\CustomerService\ValueObjects\ConversationAssignee;
use App\Domain\CustomerService\ValueObjects\ConversationCustomer;
use App\Domain\CustomerService\ValueObjects\ConversationSnooze;
use App\Domain\CustomerService\ValueObjects\ConversationTag;
use App\Presentation\Http\Resources\HelpScout\AssigneeResource;
use App\Presentation\Http\Resources\HelpScout\ConversationResource;
use App\Presentation\Http\Resources\HelpScout\CustomerResource;
use App\Presentation\Http\Resources\HelpScout\SnoozeResource;
use App\Presentation\Http\Resources\HelpScout\TagResource;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests API contract compliance for HelpScout Resources.
 *
 * These tests validate that the API output matches the Zod schemas in alz-admin,
 * which are based on the original HelpScout API response format.
 *
 * @see docs/helpscout-api-serialization-fix.md
 */
#[CoversClass(ConversationResource::class)]
#[CoversClass(TagResource::class)]
#[CoversClass(SnoozeResource::class)]
#[CoversClass(AssigneeResource::class)]
#[CoversClass(CustomerResource::class)]
final class ConversationApiContractTest
{
    // ─────────────────────────────────────────────────────────────────────────
    // Static Factory Methods (for creating Domain objects)
    // ─────────────────────────────────────────────────────────────────────────

    public static function createConversation(array $overrides = []): Conversation
    {
        $defaults = [
            'id' => 12345,
            'number' => 6789,
            'subject' => 'Test conversation subject',
            'status' => 'active',
            'mailboxId' => 1,
            'createdAt' => new DateTimeImmutable('2024-01-15T10:30:00', new DateTimeZone('UTC')),
            'updatedAt' => new DateTimeImmutable('2024-01-15T11:00:00', new DateTimeZone('UTC')),
            'userUpdatedAt' => new DateTimeImmutable('2024-01-15T10:45:00', new DateTimeZone('UTC')),
            'customerWaitingSince' => new DateTimeImmutable('2024-01-15T09:00:00', new DateTimeZone('UTC')),
            'snooze' => null,
            'tags' => [],
            'customer' => null,
            'assignee' => null,
            'mailboxName' => 'Support',
            'customerWaitingFriendly' => '2 hours ago',
        ];

        $merged = \array_merge($defaults, $overrides);

        return new Conversation(
            id: $merged['id'],
            number: $merged['number'],
            subject: $merged['subject'],
            status: $merged['status'],
            mailboxId: $merged['mailboxId'],
            createdAt: $merged['createdAt'],
            updatedAt: $merged['updatedAt'],
            userUpdatedAt: $merged['userUpdatedAt'],
            customerWaitingSince: $merged['customerWaitingSince'],
            snooze: $merged['snooze'],
            tags: $merged['tags'],
            customer: $merged['customer'],
            assignee: $merged['assignee'],
            mailboxName: $merged['mailboxName'],
            customerWaitingFriendly: $merged['customerWaitingFriendly'],
        );
    }

    public static function createTag(array $overrides = []): ConversationTag
    {
        $defaults = [
            'id' => 1,
            'name' => 'urgent',
            'color' => '#ff0000',
        ];

        $merged = \array_merge($defaults, $overrides);

        return new ConversationTag(
            id: $merged['id'],
            name: $merged['name'],
            color: $merged['color'],
        );
    }

    public static function createAssignee(array $overrides = []): ConversationAssignee
    {
        $defaults = [
            'id' => 100,
            'firstName' => 'John',
            'lastName' => 'Agent',
            'photoUrl' => 'https://example.com/photo.jpg',
            'email' => 'john.agent@company.com',
        ];

        $merged = \array_merge($defaults, $overrides);

        return new ConversationAssignee(
            id: $merged['id'],
            firstName: $merged['firstName'],
            lastName: $merged['lastName'],
            photoUrl: $merged['photoUrl'],
            email: $merged['email'],
        );
    }

    public static function createCustomer(array $overrides = []): ConversationCustomer
    {
        $defaults = [
            'id' => 200,
            'firstName' => 'Jane',
            'lastName' => 'Customer',
            'email' => 'jane@example.com',
        ];

        $merged = \array_merge($defaults, $overrides);

        return new ConversationCustomer(
            id: $merged['id'],
            firstName: $merged['firstName'],
            lastName: $merged['lastName'],
            email: $merged['email'],
        );
    }

    public static function createSnooze(array $overrides = []): ConversationSnooze
    {
        $defaults = [
            'snoozedUntil' => new DateTimeImmutable('2024-01-16T09:00:00', new DateTimeZone('UTC')),
            'snoozedByUserId' => 789,
            'unsnoozeOnCustomerReply' => true,
        ];

        $merged = \array_merge($defaults, $overrides);

        return new ConversationSnooze(
            snoozedUntil: $merged['snoozedUntil'],
            snoozedByUserId: $merged['snoozedByUserId'],
            unsnoozeOnCustomerReply: $merged['unsnoozeOnCustomerReply'],
        );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Bind request to $this to prevent linter from adding 'static' to closures
// ─────────────────────────────────────────────────────────────────────────────

\beforeEach(function (): void {
    $this->request = Request::create('/helpscout/conversations/assigned');
});

// ─────────────────────────────────────────────────────────────────────────────
// ConversationResource Tests
// ─────────────────────────────────────────────────────────────────────────────

\describe('ConversationResource', function (): void {
    \it('formats all dates as ISO 8601 strings', function (): void {
        $conversation = ConversationApiContractTest::createConversation();
        $resource = new ConversationResource($conversation);

        $result = $resource->toArray($this->request);

        \expect($result['createdAt'])->toBe('2024-01-15T10:30:00+00:00');
        \expect($result['updatedAt'])->toBe('2024-01-15T11:00:00+00:00');
        \expect($result['userUpdatedAt'])->toBe('2024-01-15T10:45:00+00:00');
        \expect($result['customerWaitingSince']['time'])->toBe('2024-01-15T09:00:00+00:00');
    });

    \it('maps customer to primaryCustomer key', function (): void {
        $customer = ConversationApiContractTest::createCustomer();
        $conversation = ConversationApiContractTest::createConversation(['customer' => $customer]);
        $resource = new ConversationResource($conversation);

        $result = $resource->toArray($this->request);

        \expect($result)->toHaveKey('primaryCustomer');
        \expect($result)->not->toHaveKey('customer');
        \expect($result['primaryCustomer'])->toBeInstanceOf(JsonResource::class);
    });

    \it('reconstructs customerWaitingSince as object with time and friendly', function (): void {
        $conversation = ConversationApiContractTest::createConversation([
            'customerWaitingSince' => new DateTimeImmutable('2024-01-15T09:00:00', new DateTimeZone('UTC')),
            'customerWaitingFriendly' => '2 hours ago',
        ]);
        $resource = new ConversationResource($conversation);

        $result = $resource->toArray($this->request);

        \expect($result['customerWaitingSince'])->toBeArray();
        \expect($result['customerWaitingSince']['time'])->toBe('2024-01-15T09:00:00+00:00');
        \expect($result['customerWaitingSince']['friendly'])->toBe('2 hours ago');
    });

    \it('omits customerWaitingSince.friendly when null', function (): void {
        $conversation = ConversationApiContractTest::createConversation([
            'customerWaitingSince' => new DateTimeImmutable('2024-01-15T09:00:00', new DateTimeZone('UTC')),
            'customerWaitingFriendly' => null,
        ]);
        $resource = new ConversationResource($conversation);

        $result = $resource->toArray($this->request);

        \expect($result['customerWaitingSince'])->toBeArray();
        \expect($result['customerWaitingSince'])->toHaveKey('time');
        \expect($result['customerWaitingSince'])->not->toHaveKey('friendly');
    });

    \it('omits customerWaitingSince entirely when null', function (): void {
        $conversation = ConversationApiContractTest::createConversation([
            'customerWaitingSince' => null,
            'customerWaitingFriendly' => null,
        ]);
        $resource = new ConversationResource($conversation);

        $result = $resource->toArray($this->request);

        \expect($result)->not->toHaveKey('customerWaitingSince');
    });

    \it('omits null optional fields from output', function (): void {
        $conversation = ConversationApiContractTest::createConversation([
            'assignee' => null,
            'snooze' => null,
            'customer' => null,
            'updatedAt' => null,
            'userUpdatedAt' => null,
            'mailboxName' => null,
            'customerWaitingSince' => null,
            'customerWaitingFriendly' => null,
        ]);
        $resource = new ConversationResource($conversation);

        $result = $resource->toArray($this->request);

        // These keys should NOT exist (not be null values)
        \expect($result)->not->toHaveKey('assignee');
        \expect($result)->not->toHaveKey('snooze');
        \expect($result)->not->toHaveKey('primaryCustomer');
        \expect($result)->not->toHaveKey('updatedAt');
        \expect($result)->not->toHaveKey('userUpdatedAt');
        \expect($result)->not->toHaveKey('mailboxName');
        \expect($result)->not->toHaveKey('customerWaitingSince');

        // Required fields should still exist
        \expect($result)->toHaveKey('id');
        \expect($result)->toHaveKey('number');
        \expect($result)->toHaveKey('subject');
        \expect($result)->toHaveKey('status');
        \expect($result)->toHaveKey('createdAt');
        \expect($result)->toHaveKey('tags');
    });

    \it('transforms tags using TagResource', function (): void {
        $tags = [
            ConversationApiContractTest::createTag(['id' => 1, 'name' => 'urgent', 'color' => '#ff0000']),
            ConversationApiContractTest::createTag(['id' => 2, 'name' => 'priority', 'color' => '#00ff00']),
        ];
        $conversation = ConversationApiContractTest::createConversation(['tags' => $tags]);
        $resource = new ConversationResource($conversation);

        $result = $resource->toArray($this->request);

        \expect($result['tags'])->toHaveCount(2);
    });

    \it('includes empty tags array when no tags', function (): void {
        $conversation = ConversationApiContractTest::createConversation(['tags' => []]);
        $resource = new ConversationResource($conversation);

        $result = $resource->toArray($this->request);

        // Tags should be present (not omitted) and empty
        // Note: TagResource::collection() returns an AnonymousResourceCollection
        \expect($result)->toHaveKey('tags');
        \expect($result['tags']->resource)->toBeInstanceOf(Collection::class);
        \expect($result['tags']->resource)->toBeEmpty();
    });

    \it('full conversation matches expected API contract', function (): void {
        $conversation = ConversationApiContractTest::createConversation([
            'id' => 12345,
            'number' => 6789,
            'subject' => 'Order inquiry',
            'status' => 'active',
            'createdAt' => new DateTimeImmutable('2024-01-15T10:30:00', new DateTimeZone('UTC')),
            'updatedAt' => new DateTimeImmutable('2024-01-15T11:00:00', new DateTimeZone('UTC')),
            'userUpdatedAt' => new DateTimeImmutable('2024-01-15T10:45:00', new DateTimeZone('UTC')),
            'customerWaitingSince' => new DateTimeImmutable('2024-01-15T09:00:00', new DateTimeZone('UTC')),
            'customerWaitingFriendly' => '2 hours ago',
            'mailboxName' => 'Support',
            'customer' => ConversationApiContractTest::createCustomer([
                'firstName' => 'John',
                'lastName' => 'Doe',
                'email' => 'john@example.com',
            ]),
            'assignee' => ConversationApiContractTest::createAssignee([
                'firstName' => 'Support',
                'lastName' => 'Agent',
                'email' => 'agent@company.com',
            ]),
            'tags' => [
                ConversationApiContractTest::createTag(['id' => 1, 'name' => 'urgent', 'color' => '#ff0000']),
            ],
            'snooze' => ConversationApiContractTest::createSnooze([
                'snoozedByUserId' => 789,
                'snoozedUntil' => new DateTimeImmutable('2024-01-16T09:00:00', new DateTimeZone('UTC')),
                'unsnoozeOnCustomerReply' => true,
            ]),
        ]);
        $resource = new ConversationResource($conversation);

        $result = $resource->toArray($this->request);

        // Verify structure matches API contract
        \expect($result['id'])->toBe(12345);
        \expect($result['number'])->toBe(6789);
        \expect($result['subject'])->toBe('Order inquiry');
        \expect($result['status'])->toBe('active');
        \expect($result['createdAt'])->toBe('2024-01-15T10:30:00+00:00');
        \expect($result['updatedAt'])->toBe('2024-01-15T11:00:00+00:00');
        \expect($result['userUpdatedAt'])->toBe('2024-01-15T10:45:00+00:00');
        \expect($result['mailboxName'])->toBe('Support');

        // Nested structures are Resources (will be serialized to arrays in JSON response)
        \expect($result['primaryCustomer'])->toBeInstanceOf(CustomerResource::class);
        \expect($result['assignee'])->toBeInstanceOf(AssigneeResource::class);
        \expect($result['snooze'])->toBeInstanceOf(SnoozeResource::class);

        // customerWaitingSince is an array (built inline, not a Resource)
        \expect($result['customerWaitingSince'])->toBeArray();
        \expect($result['customerWaitingSince']['time'])->toBe('2024-01-15T09:00:00+00:00');
        \expect($result['customerWaitingSince']['friendly'])->toBe('2 hours ago');
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// TagResource Tests
// ─────────────────────────────────────────────────────────────────────────────

\describe('TagResource', function (): void {
    \it('maps name property to tag key', function (): void {
        $tag = ConversationApiContractTest::createTag(['name' => 'priority']);
        $resource = new TagResource($tag);

        $result = $resource->toArray($this->request);

        \expect($result)->toHaveKey('tag');
        \expect($result)->not->toHaveKey('name');
        \expect($result['tag'])->toBe('priority');
    });

    \it('includes id and color fields', function (): void {
        $tag = ConversationApiContractTest::createTag([
            'id' => 42,
            'name' => 'urgent',
            'color' => '#ff0000',
        ]);
        $resource = new TagResource($tag);

        $result = $resource->toArray($this->request);

        \expect($result['id'])->toBe(42);
        \expect($result['tag'])->toBe('urgent');
        \expect($result['color'])->toBe('#ff0000');
    });

    \it('includes color as null when not set', function (): void {
        $tag = ConversationApiContractTest::createTag(['color' => null]);
        $resource = new TagResource($tag);

        $result = $resource->toArray($this->request);

        // Tags always include all fields (id, tag, color)
        \expect($result)->toHaveKey('color');
        \expect($result['color'])->toBeNull();
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// SnoozeResource Tests
// ─────────────────────────────────────────────────────────────────────────────

\describe('SnoozeResource', function (): void {
    \it('maps snoozedByUserId to snoozedBy key', function (): void {
        $snooze = ConversationApiContractTest::createSnooze(['snoozedByUserId' => 789]);
        $resource = new SnoozeResource($snooze);

        $result = $resource->toArray($this->request);

        \expect($result)->toHaveKey('snoozedBy');
        \expect($result)->not->toHaveKey('snoozedByUserId');
        \expect($result['snoozedBy'])->toBe(789);
    });

    \it('formats snoozedUntil as ISO 8601 string', function (): void {
        $snooze = ConversationApiContractTest::createSnooze([
            'snoozedUntil' => new DateTimeImmutable('2024-01-16T09:00:00', new DateTimeZone('UTC')),
        ]);
        $resource = new SnoozeResource($snooze);

        $result = $resource->toArray($this->request);

        \expect($result['snoozedUntil'])->toBe('2024-01-16T09:00:00+00:00');
    });

    \it('includes unsnoozeOnCustomerReply field', function (): void {
        $snooze = ConversationApiContractTest::createSnooze(['unsnoozeOnCustomerReply' => true]);
        $resource = new SnoozeResource($snooze);

        $result = $resource->toArray($this->request);

        \expect($result)->toHaveKey('unsnoozeOnCustomerReply');
        \expect($result['unsnoozeOnCustomerReply'])->toBeTrue();
    });

    \it('omits null fields from output', function (): void {
        $snooze = ConversationApiContractTest::createSnooze([
            'snoozedByUserId' => null,
            'unsnoozeOnCustomerReply' => null,
        ]);
        $resource = new SnoozeResource($snooze);

        $result = $resource->toArray($this->request);

        \expect($result)->not->toHaveKey('snoozedBy');
        \expect($result)->not->toHaveKey('unsnoozeOnCustomerReply');
        // snoozedUntil is always required
        \expect($result)->toHaveKey('snoozedUntil');
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// AssigneeResource Tests
// ─────────────────────────────────────────────────────────────────────────────

\describe('AssigneeResource', function (): void {
    \it('includes email when present', function (): void {
        $assignee = ConversationApiContractTest::createAssignee(['email' => 'agent@company.com']);
        $resource = new AssigneeResource($assignee);

        $result = $resource->toArray($this->request);

        \expect($result)->toHaveKey('email');
        \expect($result['email'])->toBe('agent@company.com');
    });

    \it('includes firstName and lastName', function (): void {
        $assignee = ConversationApiContractTest::createAssignee([
            'firstName' => 'John',
            'lastName' => 'Agent',
        ]);
        $resource = new AssigneeResource($assignee);

        $result = $resource->toArray($this->request);

        \expect($result['firstName'])->toBe('John');
        \expect($result['lastName'])->toBe('Agent');
    });

    \it('omits null fields from output', function (): void {
        $assignee = ConversationApiContractTest::createAssignee(['email' => null]);
        $resource = new AssigneeResource($assignee);

        $result = $resource->toArray($this->request);

        \expect($result)->not->toHaveKey('email');
        // firstName and lastName are always present (required by Domain)
        \expect($result)->toHaveKey('firstName');
        \expect($result)->toHaveKey('lastName');
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// CustomerResource Tests
// ─────────────────────────────────────────────────────────────────────────────

\describe('CustomerResource', function (): void {
    \it('maps firstName to first and lastName to last', function (): void {
        $customer = ConversationApiContractTest::createCustomer([
            'firstName' => 'Jane',
            'lastName' => 'Doe',
        ]);
        $resource = new CustomerResource($customer);

        $result = $resource->toArray($this->request);

        \expect($result)->toHaveKey('first');
        \expect($result)->toHaveKey('last');
        \expect($result)->not->toHaveKey('firstName');
        \expect($result)->not->toHaveKey('lastName');
        \expect($result['first'])->toBe('Jane');
        \expect($result['last'])->toBe('Doe');
    });

    \it('includes email field', function (): void {
        $customer = ConversationApiContractTest::createCustomer(['email' => 'customer@example.com']);
        $resource = new CustomerResource($customer);

        $result = $resource->toArray($this->request);

        \expect($result)->toHaveKey('email');
        \expect($result['email'])->toBe('customer@example.com');
    });

    \it('omits null fields from output', function (): void {
        $customer = ConversationApiContractTest::createCustomer([
            'firstName' => null,
            'lastName' => null,
            'email' => null,
        ]);
        $resource = new CustomerResource($customer);

        $result = $resource->toArray($this->request);

        \expect($result)->not->toHaveKey('first');
        \expect($result)->not->toHaveKey('last');
        \expect($result)->not->toHaveKey('email');
    });

    \it('handles partial null fields correctly', function (): void {
        $customer = ConversationApiContractTest::createCustomer([
            'firstName' => 'Jane',
            'lastName' => null,
            'email' => 'jane@example.com',
        ]);
        $resource = new CustomerResource($customer);

        $result = $resource->toArray($this->request);

        \expect($result)->toHaveKey('first');
        \expect($result)->not->toHaveKey('last');
        \expect($result)->toHaveKey('email');
        \expect($result['first'])->toBe('Jane');
        \expect($result['email'])->toBe('jane@example.com');
    });
});

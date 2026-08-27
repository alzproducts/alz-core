<?php

declare(strict_types=1);

use App\Domain\CustomerService\ValueObjects\Conversation;
use App\Domain\CustomerService\ValueObjects\ConversationAssignee;
use App\Domain\CustomerService\ValueObjects\ConversationCustomer;
use App\Domain\CustomerService\ValueObjects\ConversationSnooze;
use App\Domain\CustomerService\ValueObjects\ConversationTag;
use App\Domain\CustomerService\ValueObjects\EscalationsConfig;
use App\Domain\CustomerService\ValueObjects\Mailbox;
use App\Domain\CustomerService\ValueObjects\SupportAgent;
use App\Infrastructure\BingAds\BingAdsSession;
use App\Infrastructure\Linnworks\LinnworksSession;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    // The `array` store in config/cache.php sets 'serialize' => false, so unserialize() never
    // runs against it and every round-trip below would pass even with an empty allow-list.
    config(['cache.stores.serializing_array' => ['driver' => 'array', 'serialize' => true]]);

    $this->store = Cache::store('serializing_array');
});

function roundTripThroughCache(Repository $store, mixed $value): mixed
{
    $store->put('serializable-classes-round-trip', $value, 60);

    return $store->get('serializable-classes-round-trip');
}

function fullyPopulatedConversation(int $id): Conversation
{
    return new Conversation(
        id: $id,
        number: 4711,
        subject: 'Order has not arrived',
        status: 'active',
        mailboxId: 7,
        createdAt: new DateTimeImmutable('2026-01-02 03:04:05'),
        updatedAt: new DateTimeImmutable('2026-01-03 03:04:05'),
        userUpdatedAt: new DateTimeImmutable('2026-01-04 03:04:05'),
        customerWaitingSince: new DateTimeImmutable('2026-01-05 03:04:05'),
        snooze: new ConversationSnooze(
            snoozedUntil: new DateTimeImmutable('2026-01-06 03:04:05'),
            snoozedByUserId: 55,
            unsnoozeOnCustomerReply: true,
        ),
        tags: [
            new ConversationTag(id: 91, name: 'priority', color: '#ff0000'),
            new ConversationTag(id: 92, name: 'shipping', color: null),
        ],
        customer: new ConversationCustomer(
            id: 300,
            firstName: 'Ada',
            lastName: 'Lovelace',
            email: 'ada@example.test',
        ),
        assignee: new ConversationAssignee(
            id: 400,
            firstName: 'Grace',
            lastName: 'Hopper',
            photoUrl: 'https://example.test/grace.png',
            email: 'grace@example.test',
        ),
        mailboxName: 'Support',
        customerWaitingFriendly: '2 hours ago',
    );
}

it('round-trips a BingAdsSession', function (): void {
    $session = new BingAdsSession(
        accessToken: 'bing-access-token',
        expiresAt: new DateTimeImmutable('2026-02-01 10:00:00'),
    );

    $restored = roundTripThroughCache($this->store, $session);

    expect($restored)->toEqual($session)
        ->and($restored)->toBeInstanceOf(BingAdsSession::class)
        ->and($restored->expiresAt)->toBeInstanceOf(DateTimeImmutable::class);
});

it('round-trips a LinnworksSession', function (): void {
    $session = new LinnworksSession(
        token: 'linnworks-token',
        serverUrl: 'https://eu-ext.linnworks.test',
        expiresAt: new DateTimeImmutable('2026-02-02 11:00:00'),
    );

    $restored = roundTripThroughCache($this->store, $session);

    expect($restored)->toEqual($session)
        ->and($restored)->toBeInstanceOf(LinnworksSession::class)
        ->and($restored->expiresAt)->toBeInstanceOf(DateTimeImmutable::class);
});

it('round-trips a SupportAgent', function (): void {
    $agent = new SupportAgent(
        id: 12,
        email: 'agent@example.test',
        firstName: 'Alan',
        lastName: 'Turing',
        role: 'admin',
    );

    $restored = roundTripThroughCache($this->store, $agent);

    expect($restored)->toEqual($agent)
        ->and($restored)->toBeInstanceOf(SupportAgent::class);
});

it('round-trips an EscalationsConfig', function (): void {
    $config = new EscalationsConfig(
        lateThresholdHours: 24,
        latePriorityThresholdHours: 4,
        priorityTags: ['vip', 'urgent'],
        excludedTags: ['spam'],
        assignedTag: 'assigned',
    );

    $restored = roundTripThroughCache($this->store, $config);

    expect($restored)->toEqual($config)
        ->and($restored)->toBeInstanceOf(EscalationsConfig::class);
});

it('round-trips a Mailbox', function (): void {
    $mailbox = new Mailbox(
        id: 7,
        name: 'Support',
        email: 'support@example.test',
        slug: 'support',
    );

    $restored = roundTripThroughCache($this->store, $mailbox);

    expect($restored)->toEqual($mailbox)
        ->and($restored)->toBeInstanceOf(Mailbox::class);
});

it('round-trips a Conversation with every nested object intact', function (): void {
    $conversation = fullyPopulatedConversation(1001);

    $restored = roundTripThroughCache($this->store, $conversation);

    expect($restored)->toEqual($conversation)
        ->and($restored)->toBeInstanceOf(Conversation::class)
        ->and($restored->createdAt)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($restored->updatedAt)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($restored->userUpdatedAt)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($restored->customerWaitingSince)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($restored->snooze)->toBeInstanceOf(ConversationSnooze::class)
        ->and($restored->snooze->snoozedUntil)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($restored->customer)->toBeInstanceOf(ConversationCustomer::class)
        ->and($restored->assignee)->toBeInstanceOf(ConversationAssignee::class)
        ->and($restored->tags)->toHaveCount(2)
        ->and($restored->tags[0])->toBeInstanceOf(ConversationTag::class)
        ->and($restored->tags[1])->toBeInstanceOf(ConversationTag::class);
});

it('round-trips a list of Conversations', function (): void {
    $conversations = [fullyPopulatedConversation(1001), fullyPopulatedConversation(1002)];

    $restored = roundTripThroughCache($this->store, $conversations);

    expect($restored)->toEqual($conversations)
        ->and($restored)->toBeArray()
        ->and($restored)->toHaveCount(2)
        ->and($restored[0])->toBeInstanceOf(Conversation::class)
        ->and($restored[1])->toBeInstanceOf(Conversation::class)
        ->and($restored[0]->snooze)->toBeInstanceOf(ConversationSnooze::class)
        ->and($restored[0]->tags[0])->toBeInstanceOf(ConversationTag::class)
        ->and($restored[1]->customer)->toBeInstanceOf(ConversationCustomer::class)
        ->and($restored[1]->assignee)->toBeInstanceOf(ConversationAssignee::class);
});

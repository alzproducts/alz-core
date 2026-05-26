<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\CallTracking;

use App\Application\Contracts\Conversion\CallTracking\CallTrackingQueryRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Boundary contract test for the attribution-collision SQL.
 *
 * Verifies the 6-hour attribution window, the 12-hour lookback scope, and
 * the post-filter that keeps only calls with >1 matching visit. The view
 * silently excludes these rows, so this query is the only path that surfaces
 * them to operators — the SQL semantics must match the view's exclusion
 * semantics exactly or alerts will diverge from dashboard state.
 */
#[CoversNothing]
#[Group('integration')]
final class EloquentCallTrackingQueryRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    private CallTrackingQueryRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->app->make(CallTrackingQueryRepositoryInterface::class);
    }

    #[Test]
    public function returns_empty_when_call_matches_exactly_one_visit(): void
    {
        $now = CarbonImmutable::now();
        $callId = $this->insertCall(trackingNumber: '+447900000010', createdAt: $now);
        $this->insertVisit(trackingNumber: '+447900000010', createdAt: $now->subHour());

        $collisions = $this->repository->findAttributionCollisions();

        self::assertSame([], $this->collisionsForCall($collisions, $callId));
    }

    #[Test]
    public function returns_collision_with_all_visit_ids_when_call_matches_multiple_visits(): void
    {
        $now = CarbonImmutable::now();
        $callId = $this->insertCall(trackingNumber: '+447900000011', createdAt: $now);
        $visitA = $this->insertVisit(trackingNumber: '+447900000011', createdAt: $now->subHours(2));
        $visitB = $this->insertVisit(trackingNumber: '+447900000011', createdAt: $now->subHour());

        $collisions = $this->repository->findAttributionCollisions();

        $forCall = $this->collisionsForCall($collisions, $callId);
        self::assertCount(1, $forCall);
        self::assertSame('+447900000011', $forCall[0]['tracking_number']);
        \sort($forCall[0]['visit_ids']);
        $expected = [$visitA, $visitB];
        \sort($expected);
        self::assertSame($expected, $forCall[0]['visit_ids']);
    }

    #[Test]
    public function excludes_calls_older_than_lookback_window(): void
    {
        $stale = CarbonImmutable::now()->subHours(13);
        $callId = $this->insertCall(trackingNumber: '+447900000012', createdAt: $stale);
        $this->insertVisit(trackingNumber: '+447900000012', createdAt: $stale->subHour());
        $this->insertVisit(trackingNumber: '+447900000012', createdAt: $stale->subHours(2));

        $collisions = $this->repository->findAttributionCollisions();

        self::assertSame([], $this->collisionsForCall($collisions, $callId));
    }

    #[Test]
    public function excludes_visits_outside_6_hour_attribution_window(): void
    {
        $now = CarbonImmutable::now();
        $callId = $this->insertCall(trackingNumber: '+447900000013', createdAt: $now);
        $this->insertVisit(trackingNumber: '+447900000013', createdAt: $now->subHour());
        $this->insertVisit(trackingNumber: '+447900000013', createdAt: $now->subHours(7));

        $collisions = $this->repository->findAttributionCollisions();

        self::assertSame([], $this->collisionsForCall($collisions, $callId));
    }

    private function insertCall(string $trackingNumber, CarbonImmutable $createdAt): string
    {
        $id = (string) DB::connection('pgsql')->selectOne('SELECT gen_random_uuid() AS id')->id;

        DB::connection('pgsql')->table('customer_service.call_tracking_calls')->insert([
            'id' => $id,
            'call_sid' => 'CA' . \mb_substr(\str_replace('-', '', $id), 0, 32),
            'tracking_number_dialled' => $trackingNumber,
            'caller_phone_number' => '+447000000000',
            'call_status' => 'initiated',
            'created_at' => $createdAt->toIso8601String(),
        ]);

        return $id;
    }

    private function insertVisit(string $trackingNumber, CarbonImmutable $createdAt): string
    {
        $id = (string) DB::connection('pgsql')->selectOne('SELECT gen_random_uuid() AS id')->id;

        DB::connection('pgsql')->table('customer_service.call_tracking_visits')->insert([
            'id' => $id,
            'marketing_consent_granted' => true,
            'tracking_number_shown' => $trackingNumber,
            'ip_address' => '127.0.0.1',
            'created_at' => $createdAt->toIso8601String(),
        ]);

        return $id;
    }

    /**
     * @param list<array{call_id: string, visit_ids: list<string>, tracking_number: string}> $collisions
     *
     * @return list<array{call_id: string, visit_ids: list<string>, tracking_number: string}>
     */
    private function collisionsForCall(array $collisions, string $callId): array
    {
        return \array_values(\array_filter(
            $collisions,
            static fn(array $c): bool => $c['call_id'] === $callId,
        ));
    }
}

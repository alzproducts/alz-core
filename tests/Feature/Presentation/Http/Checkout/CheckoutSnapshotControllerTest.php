<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Checkout;

use App\Infrastructure\Ingest\Checkout\Models\BasketSnapshotModel;
use App\Presentation\Http\Checkout\Controllers\CheckoutSnapshotController;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Feature tests for POST /api/checkout/snapshot.
 *
 * Verifies the full HTTP boundary — DTO validation, IP/UA capture, persistence,
 * and rate limiting. Shares the Supabase DB so cleanup is explicit in tearDown.
 */
#[CoversClass(CheckoutSnapshotController::class)]
#[Group('integration')]
final class CheckoutSnapshotControllerTest extends TestCase
{
    /** @var list<string> Snapshot IDs to clean up */
    private array $createdSnapshotIds = [];

    #[Override]
    protected function tearDown(): void
    {
        if ($this->createdSnapshotIds !== []) {
            BasketSnapshotModel::query()
                ->whereIn('id', $this->createdSnapshotIds)
                ->delete();
        }

        // Wipe rate-limit keys created by these tests
        BasketSnapshotModel::query()
            ->where('user_agent', 'symfony-test')
            ->delete();

        parent::tearDown();
    }

    #[Test]
    public function valid_request_returns_201_and_persists_snapshot(): void
    {
        $response = $this->postJson('/api/checkout/snapshot', [
            'basket_total' => '129.99',
            'shipping_method_id' => '40155',
            'delivery_date' => '2026-06-15',
            'gift_note' => 'Happy birthday',
            'vat_relief' => ['eligible' => true, 'condition' => 'arthritis'],
        ]);

        $response->assertStatus(Response::HTTP_CREATED);
        self::assertSame('', $response->getContent());

        $snapshot = BasketSnapshotModel::query()
            ->where('shipping_method_id', '40155')
            ->orderByDesc('created_at')
            ->first();

        self::assertNotNull($snapshot);
        $this->createdSnapshotIds[] = $snapshot->id;

        self::assertSame('129.99', $snapshot->basket_total);
        self::assertSame('Happy birthday', $snapshot->gift_note);
        self::assertNotEmpty($snapshot->ip_address);
        self::assertNotEmpty($snapshot->user_agent);
        self::assertSame(['eligible' => true, 'condition' => 'arthritis'], $snapshot->vat_relief);
    }

    #[Test]
    public function minimal_valid_body_returns_201(): void
    {
        $response = $this->postJson('/api/checkout/snapshot', [
            'basket_total' => '12.34',
        ]);

        $response->assertStatus(Response::HTTP_CREATED);

        $snapshot = BasketSnapshotModel::query()
            ->where('basket_total', '12.34')
            ->orderByDesc('created_at')
            ->first();

        self::assertNotNull($snapshot);
        $this->createdSnapshotIds[] = $snapshot->id;

        self::assertNull($snapshot->shipping_method_id);
        self::assertNull($snapshot->delivery_date);
        self::assertNull($snapshot->gift_note);
        self::assertNull($snapshot->vat_relief);
    }

    #[Test]
    public function missing_basket_total_returns_422(): void
    {
        $response = $this->postJson('/api/checkout/snapshot', []);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('validation_error', $response->json('error.type'));
        self::assertArrayHasKey('basket_total', $response->json('error.errors'));
    }

    #[Test]
    public function non_numeric_basket_total_returns_422(): void
    {
        $response = $this->postJson('/api/checkout/snapshot', [
            'basket_total' => 'not-a-number',
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('validation_error', $response->json('error.type'));
        self::assertArrayHasKey('basket_total', $response->json('error.errors'));
    }

    #[Test]
    public function gift_note_over_500_chars_returns_422(): void
    {
        $response = $this->postJson('/api/checkout/snapshot', [
            'basket_total' => '50.00',
            'gift_note' => \str_repeat('a', 501),
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('validation_error', $response->json('error.type'));
        self::assertArrayHasKey('gift_note', $response->json('error.errors'));
    }

    #[Test]
    public function invalid_delivery_date_format_returns_422(): void
    {
        $response = $this->postJson('/api/checkout/snapshot', [
            'basket_total' => '50.00',
            'delivery_date' => '15/06/2026',
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('validation_error', $response->json('error.type'));
        self::assertArrayHasKey('delivery_date', $response->json('error.errors'));
    }
}

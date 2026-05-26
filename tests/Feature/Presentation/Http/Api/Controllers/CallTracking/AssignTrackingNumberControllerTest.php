<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\Controllers\CallTracking;

use App\Infrastructure\Conversion\CallTracking\Models\CallTrackingNumberModel;
use App\Infrastructure\Conversion\CallTracking\Models\CallTrackingVisitModel;
use App\Presentation\Http\Api\Controllers\CallTracking\AssignTrackingNumberController;
use Illuminate\Support\Facades\DB;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

#[CoversClass(AssignTrackingNumberController::class)]
#[Group('integration')]
final class AssignTrackingNumberControllerTest extends TestCase
{
    private const string DEFAULT_NUMBER = '+441234567000';

    private const string POOL_NUMBER_A = '+441234567001';

    private const string POOL_NUMBER_B = '+441234567002';

    /** @var list<string> */
    private array $seededNumberIds = [];

    /** @var list<string> */
    private array $createdVisitIds = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        \config(['call-tracking.default_business_phone_number' => self::DEFAULT_NUMBER]);

        DB::table('customer_service.call_tracking_visits')->delete();
        DB::table('customer_service.call_tracking_numbers')->delete();
        DB::table('customer_service.call_tracking_rotation_counter')->where('id', 1)->update(['counter' => 0]);
    }

    #[Override]
    protected function tearDown(): void
    {
        if ($this->createdVisitIds !== []) {
            CallTrackingVisitModel::query()->whereIn('id', $this->createdVisitIds)->delete();
        }

        if ($this->seededNumberIds !== []) {
            CallTrackingNumberModel::query()->whereIn('id', $this->seededNumberIds)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function consented_request_with_active_pool_returns_pool_number_and_visit_id(): void
    {
        $this->seedPool([self::POOL_NUMBER_A, self::POOL_NUMBER_B]);

        $response = $this->postJson('/api/display-number', [
            'marketing_consent_granted' => true,
            'gclid' => 'CjwKany-fresh-click',
        ]);

        $response->assertStatus(Response::HTTP_OK);

        $payload = $response->json();
        self::assertIsArray($payload);
        self::assertContains($payload['phone_number'], [self::POOL_NUMBER_A, self::POOL_NUMBER_B]);
        self::assertNotNull($payload['call_visit_id']);

        $this->createdVisitIds[] = $payload['call_visit_id'];

        $visit = CallTrackingVisitModel::query()->find($payload['call_visit_id']);
        self::assertNotNull($visit);
        self::assertSame('CjwKany-fresh-click', $visit->gclid);
        self::assertSame($payload['phone_number'], $visit->tracking_number_shown);
    }

    #[Test]
    public function consent_denied_returns_default_number_and_null_visit_id(): void
    {
        $this->seedPool([self::POOL_NUMBER_A]);

        $response = $this->postJson('/api/display-number', [
            'marketing_consent_granted' => false,
            'gclid' => 'CjwKany-ignored',
        ]);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJson([
            'phone_number' => self::DEFAULT_NUMBER,
            'call_visit_id' => null,
        ]);
    }

    #[Test]
    public function missing_marketing_consent_granted_returns_422(): void
    {
        $response = $this->postJson('/api/display-number', [
            'gclid' => 'CjwKany',
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param list<string> $phoneNumbers
     */
    private function seedPool(array $phoneNumbers): void
    {
        $sortOrder = 0;
        foreach ($phoneNumbers as $phoneNumber) {
            $model = new CallTrackingNumberModel();
            $model->phone_number = $phoneNumber;
            $model->active = true;
            $model->sort_order = $sortOrder++;
            $model->save();
            $this->seededNumberIds[] = $model->id;
        }
    }
}

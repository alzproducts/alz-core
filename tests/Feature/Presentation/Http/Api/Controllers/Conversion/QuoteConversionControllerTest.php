<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\Controllers\Conversion;

use App\Application\Conversion\UseCases\SubmitQuoteConversionUseCase;
use App\Presentation\Http\Api\Controllers\Conversion\QuoteConversionController;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\AuthenticatesAsApprovedUser;
use Tests\TestCase;

#[CoversClass(QuoteConversionController::class)]
final class QuoteConversionControllerTest extends TestCase
{
    use AuthenticatesAsApprovedUser;

    private const string SUBMISSION_ID = '11111111-1111-4111-8111-111111111111';

    private const string CONVERTED_AT = '2026-05-15';

    private const float VALUE = 149.99;

    private SubmitQuoteConversionUseCase&MockInterface $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->useCase = Mockery::mock(SubmitQuoteConversionUseCase::class);
        $this->app->instance(SubmitQuoteConversionUseCase::class, $this->useCase);
    }

    #[Override]
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function unauthenticated_request_returns_401(): void
    {
        $this->useCase->shouldNotReceive('execute');

        $response = $this->postJson('/api/conversions/quote', [
            'submission_id' => self::SUBMISSION_ID,
            'value' => self::VALUE,
            'converted_at' => self::CONVERTED_AT,
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function valid_request_returns_202_and_invokes_use_case(): void
    {
        $this->useCase->expects('execute')
            ->with(self::SUBMISSION_ID, self::VALUE, self::CONVERTED_AT)
            ->once();

        $response = $this->asApprovedUser()->postJson('/api/conversions/quote', [
            'submission_id' => self::SUBMISSION_ID,
            'value' => self::VALUE,
            'converted_at' => self::CONVERTED_AT,
        ]);

        $response->assertStatus(202);
        self::assertSame('Quote conversion queued', $response->json('message'));
    }

    #[Test]
    public function missing_required_fields_returns_422(): void
    {
        $this->useCase->shouldNotReceive('execute');

        $response = $this->asApprovedUser()->postJson('/api/conversions/quote', []);

        $response->assertStatus(422);
    }

    #[Test]
    public function non_numeric_value_returns_422(): void
    {
        $this->useCase->shouldNotReceive('execute');

        $response = $this->asApprovedUser()->postJson('/api/conversions/quote', [
            'submission_id' => self::SUBMISSION_ID,
            'value' => 'not-a-number',
            'converted_at' => self::CONVERTED_AT,
        ]);

        $response->assertStatus(422);
    }
}

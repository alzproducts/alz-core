<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Http\Twilio\Webhooks\Controllers;

use App\Application\Contracts\Conversion\CallTracking\InboundCallDispatcherInterface;
use App\Presentation\Http\Twilio\Webhooks\Controllers\TwilioCallStartedController;
use Illuminate\Http\Request;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

#[CoversClass(TwilioCallStartedController::class)]
final class TwilioCallStartedControllerTest extends TestCase
{
    private InboundCallDispatcherInterface&MockInterface $dispatcher;

    private TwilioCallStartedController $controller;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->dispatcher = Mockery::mock(InboundCallDispatcherInterface::class);
        $this->controller = new TwilioCallStartedController($this->dispatcher);
    }

    #[Test]
    public function it_dispatches_inbound_call_processing_with_from_to_and_sid_then_returns_empty_200(): void
    {
        $this->dispatcher
            ->shouldReceive('dispatchInboundCallProcessing')
            ->once()
            ->with('+447900123456', '+441234567890', 'CA1234567890abcdef1234567890abcdef');

        $request = Request::create('/api/webhooks/twilio/call-started', 'POST', [
            'From' => '+447900123456',
            'To' => '+441234567890',
            'CallSid' => 'CA1234567890abcdef1234567890abcdef',
        ]);

        $response = ($this->controller)($request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }
}

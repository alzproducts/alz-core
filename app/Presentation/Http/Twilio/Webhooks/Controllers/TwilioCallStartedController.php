<?php

declare(strict_types=1);

namespace App\Presentation\Http\Twilio\Webhooks\Controllers;

use App\Application\Contracts\Conversion\CallTracking\InboundCallDispatcherInterface;
use App\Presentation\Http\Twilio\Webhooks\DTOs\TwilioCallStartedDTO;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class TwilioCallStartedController
{
    public function __construct(
        private InboundCallDispatcherInterface $dispatcher,
    ) {}

    public function __invoke(Request $request): Response
    {
        $payload = TwilioCallStartedDTO::from($request);

        $this->dispatcher->dispatchInboundCallProcessing(
            callerPhoneNumber: $payload->from,
            trackingNumberDialled: $payload->to,
            callSid: $payload->callSid,
        );

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}

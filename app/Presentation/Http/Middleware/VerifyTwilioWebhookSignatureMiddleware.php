<?php

declare(strict_types=1);

namespace App\Presentation\Http\Middleware;

use App\Presentation\Http\Api\Responses\ApiErrorResponseDTO;
use App\Presentation\Http\Api\Responses\ApiErrorTypeEnum;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validate `X-Twilio-Signature` on inbound Twilio webhook requests.
 *
 * Algorithm (Twilio-specific — NOT raw-body HMAC):
 * 1. Take the full request URL.
 * 2. Append each POST parameter — keys sorted alphabetically — as `key.value`
 *    (literal concatenation, no separator).
 * 3. base64(hash_hmac('sha1', $data, $accountAuthToken, binary: true)).
 * 4. Timing-safe compare against the header.
 *
 * @see https://www.twilio.com/docs/usage/webhooks/webhooks-security
 */
final class VerifyTwilioWebhookSignatureMiddleware
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authToken = $this->resolveAuthToken();
        if ($authToken === null) {
            return new ApiErrorResponseDTO(
                type: ApiErrorTypeEnum::ServerError,
                message: 'Twilio auth token not configured',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            )->toJsonResponse();
        }

        $rejection = $this->validateSignature($request, $authToken);
        if ($rejection !== null) {
            return $rejection;
        }

        return $next($request);
    }

    private function resolveAuthToken(): ?string
    {
        $token = \config('call-tracking.twilio_auth_token');
        if (!\is_string($token) || $token === '') {
            Log::channel('security')->critical('Twilio auth token not configured', [
                'event' => 'webhook.twilio.missing_auth_token',
            ]);

            return null;
        }

        return $token;
    }

    private function validateSignature(Request $request, string $authToken): ?JsonResponse
    {
        $signature = $request->header('X-Twilio-Signature');
        if (!\is_string($signature) || $signature === '') {
            return $this->rejectMissingSignature($request);
        }

        if (!\hash_equals(self::computeSignature($request, $authToken), $signature)) {
            return $this->rejectSignatureMismatch($request);
        }

        return null;
    }

    private function rejectMissingSignature(Request $request): JsonResponse
    {
        Log::channel('security')->warning('Twilio webhook missing signature header', [
            'event' => 'webhook.twilio.missing_signature',
            'ip' => $request->ip(),
            'path' => $request->path(),
        ]);

        return new ApiErrorResponseDTO(
            type: ApiErrorTypeEnum::Forbidden,
            message: 'Missing signature',
            status: Response::HTTP_FORBIDDEN,
        )->toJsonResponse();
    }

    private function rejectSignatureMismatch(Request $request): JsonResponse
    {
        Log::channel('security')->warning('Twilio webhook signature mismatch', [
            'event' => 'webhook.twilio.signature_mismatch',
            'ip' => $request->ip(),
            'path' => $request->path(),
        ]);

        return new ApiErrorResponseDTO(
            type: ApiErrorTypeEnum::Forbidden,
            message: 'Invalid signature',
            status: Response::HTTP_FORBIDDEN,
        )->toJsonResponse();
    }

    private static function computeSignature(Request $request, string $authToken): string
    {
        $data = $request->fullUrl();

        /** @var array<string, mixed> $params */
        $params = $request->post();
        \ksort($params);

        foreach ($params as $key => $value) {
            $data .= $key . (\is_scalar($value) ? (string) $value : '');
        }

        return \base64_encode(\hash_hmac('sha1', $data, $authToken, true));
    }
}

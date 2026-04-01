<?php

declare(strict_types=1);

namespace App\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verify HMAC signature on incoming ShopWired webhook requests.
 *
 * Flow:
 * 1. Compute SHA-256 HMAC of the raw request body using the webhook secret
 * 2. Compare with the X-ShopWired-Signature header (timing-safe)
 * 3. If the payload contains a verificationToken, respond with the HMAC
 *    of that token (handshake for webhook registration) and short-circuit
 * 4. If signature is invalid, abort with 403
 */
final class VerifyShopwiredWebhookSignatureMiddleware
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = $this->resolveWebhookSecret();
        if ($secret === null) {
            return new JsonResponse(['error' => 'Webhook secret not configured'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $signatureError = $this->validateSignature($request, $secret);
        if ($signatureError !== null) {
            return $signatureError;
        }

        return $this->handleVerificationOrContinue($request, $next, $secret);
    }

    private function resolveWebhookSecret(): ?string
    {
        $secret = \config('shopwired.webhook_secret');
        if (! \is_string($secret) || $secret === '') {
            Log::critical('ShopWired webhook secret not configured');

            return null;
        }

        return $secret;
    }

    private function validateSignature(Request $request, string $secret): ?JsonResponse
    {
        $signature = $request->header('X-ShopWired-Signature');
        if (! \is_string($signature) || $signature === '') {
            Log::warning('ShopWired webhook missing signature header', ['ip' => $request->ip()]);

            return new JsonResponse(['error' => 'Missing signature'], Response::HTTP_FORBIDDEN);
        }

        $expectedSignature = \hash_hmac('sha256', $request->getContent(), $secret);
        if (! \hash_equals($expectedSignature, $signature)) {
            Log::warning('ShopWired webhook signature mismatch', ['ip' => $request->ip()]);

            return new JsonResponse(['error' => 'Invalid signature'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /**
     * Handle verification token handshake or pass to next middleware.
     *
     * @param Closure(Request): Response $next
     */
    private function handleVerificationOrContinue(Request $request, Closure $next, string $secret): Response
    {
        /** @var mixed $verificationToken */
        $verificationToken = $request->input('verificationToken');

        if (\is_string($verificationToken) && $verificationToken !== '') {
            $hashedToken = \hash_hmac('sha256', $verificationToken, $secret);

            return new Response($hashedToken, Response::HTTP_OK, ['Content-Type' => 'text/plain']);
        }

        return $next($request);
    }
}

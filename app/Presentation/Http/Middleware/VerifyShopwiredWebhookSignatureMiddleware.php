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
        $secret = \config('shopwired.webhook_secret');

        if (! \is_string($secret) || $secret === '') {
            Log::critical('ShopWired webhook secret not configured');

            return new JsonResponse(['error' => 'Webhook secret not configured'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $body = $request->getContent();
        $signature = $request->header('X-ShopWired-Signature');

        if (! \is_string($signature) || $signature === '') {
            Log::warning('ShopWired webhook missing signature header', [
                'ip' => $request->ip(),
            ]);

            return new JsonResponse(['error' => 'Missing signature'], Response::HTTP_FORBIDDEN);
        }

        $expectedSignature = \hash_hmac('sha256', $body, $secret);

        if (! \hash_equals($expectedSignature, $signature)) {
            Log::warning('ShopWired webhook signature mismatch', [
                'ip' => $request->ip(),
            ]);

            return new JsonResponse(['error' => 'Invalid signature'], Response::HTTP_FORBIDDEN);
        }

        // Verification token handshake: ShopWired sends this when registering
        // a new webhook to confirm the endpoint is valid.
        /** @var mixed $verificationToken */
        $verificationToken = $request->input('verificationToken');

        if (\is_string($verificationToken) && $verificationToken !== '') {
            $hashedToken = \hash_hmac('sha256', $verificationToken, $secret);

            return new JsonResponse(['verificationToken' => $hashedToken], Response::HTTP_OK);
        }

        return $next($request);
    }
}

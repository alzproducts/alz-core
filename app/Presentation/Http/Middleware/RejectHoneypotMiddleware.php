<?php

declare(strict_types=1);

namespace App\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Honeypot spam protection middleware.
 *
 * Checks for a honeypot field that should always be empty (hidden from users,
 * but bots fill it automatically). If filled, returns a silent 200 response
 * to prevent the bot from knowing it was detected.
 *
 * Apply to public form submission routes.
 */
final class RejectHoneypotMiddleware
{
    private const string DEFAULT_FIELD = 'spam.honeypot_value';

    /**
     * Check honeypot field and silently reject if triggered.
     *
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next, string $field = self::DEFAULT_FIELD): Response
    {
        $honeypotValue = $request->input($field);

        if ($honeypotValue !== null && $honeypotValue !== '') {
            Log::info('Honeypot triggered - spam submission blocked', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'field' => $field,
            ]);

            // Silent 200 response with fake UUID - don't reveal to bot that it was detected
            return new JsonResponse(
                ['id' => (string) Str::uuid()],
                Response::HTTP_OK,
            );
        }

        return $next($request);
    }
}

<?php

declare(strict_types=1);

namespace App\Presentation\Http\Middleware;

use App\Domain\CustomerService\Exceptions\CustomerServiceAgentNotFoundException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles HelpScout-specific exceptions for API responses.
 *
 * Converts domain exceptions to appropriate HTTP responses, keeping
 * controllers clean and centralizing HelpScout error handling.
 */
final class HandleHelpScoutExceptionsMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $next($request);
        } catch (CustomerServiceAgentNotFoundException $e) {
            Log::error('HelpScout agent not found', [
                'email' => $e->email,
                'path' => $request->path(),
            ]);

            return new JsonResponse([
                'error' => 'agent_not_found',
                'message' => 'Your account is not linked to HelpScout. Contact admin.',
            ], 403);
        }
    }
}

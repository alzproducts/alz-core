<?php

declare(strict_types=1);

namespace App\Presentation\Http\QuickBooks;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * A failed exchange may already have consumed the state, so the operator needs the reconnect hint, not an HTML 500.
 */
final class QuickBooksCallbackExceptionMapper
{
    public static function render(Throwable $e, Request $request): ?Response
    {
        if ($e instanceof HttpExceptionInterface || !$request->routeIs('quickbooks.callback')) {
            return null;
        }

        return new Response(
            'QuickBooks connection failed. Run php artisan quickbooks:connect and try again.',
            Response::HTTP_BAD_GATEWAY,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }
}

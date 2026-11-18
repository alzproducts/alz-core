<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

/**
 * The QBO environment a deployment connects to (CONTEXT.md "QBO environment").
 */
enum QuickBooksEnvironment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    public function apiBaseUrl(): string
    {
        return match ($this) {
            self::Sandbox => 'https://sandbox-quickbooks.api.intuit.com',
            self::Production => 'https://quickbooks.api.intuit.com',
        };
    }
}

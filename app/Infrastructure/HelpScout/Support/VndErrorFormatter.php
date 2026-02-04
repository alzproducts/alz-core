<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Support;

use HelpScout\Api\Http\Hal\VndError;

/**
 * Formats HelpScout VndError objects for logging and exception messages.
 *
 * VndError is HelpScout's standard validation error format. This formatter
 * extracts the structured error data for consistent logging across all
 * HelpScout clients.
 */
final readonly class VndErrorFormatter
{
    /**
     * Format VndError for logging context.
     *
     * @return array{message: string, path: ?string, errors: list<array{message: string, path: ?string}>}
     */
    public static function toLogContext(VndError $error): array
    {
        $errors = [];
        foreach ($error->getErrors() as $err) {
            $errors[] = [
                'message' => $err->getMessage(),
                'path' => $err->getPath(),
            ];
        }

        return [
            'message' => $error->getMessage(),
            'path' => $error->getPath(),
            'errors' => $errors,
        ];
    }

    /**
     * Format VndError as a human-readable message for exceptions.
     *
     * Combines the main message with individual field errors.
     */
    public static function toMessage(VndError $error): string
    {
        $message = $error->getMessage();
        if ($message === '') {
            $message = 'Validation failed';
        }

        $fieldErrors = $error->getErrors();
        if ($fieldErrors === []) {
            return $message;
        }

        $details = [];
        foreach ($fieldErrors as $err) {
            $path = $err->getPath();
            $errMessage = $err->getMessage();
            $details[] = ($path !== '' ? $path : 'unknown') . ': ' . ($errMessage !== '' ? $errMessage : 'invalid');
        }

        return $message . ' [' . \implode('; ', $details) . ']';
    }
}

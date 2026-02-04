<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Support;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use GuzzleHttp\Exception\ConnectException;
use HelpScout\Api\Exception\AuthenticationException;
use HelpScout\Api\Exception\ValidationErrorException;
use Illuminate\Support\Facades\Log;

/**
 * Translates HelpScout SDK exceptions to Domain exceptions.
 *
 * Centralizes exception handling for all SDK write operations,
 * ensuring consistent logging and translation patterns.
 *
 * @template-pattern SDK Exception Translator
 */
final class SdkExceptionTranslator
{
    private const string SERVICE_NAME = 'HelpScout';

    /**
     * Execute an SDK operation with exception translation.
     *
     * @template T
     *
     * @param-immediately-invoked-callable $operation
     * @param callable(): T $operation The SDK operation to execute
     * @param string $context Operation context for logging (e.g., 'conversation creation', 'thread creation')
     * @param array<string, mixed> $logContext Additional context for error logging
     *
     * @return T
     *
     * @throws AuthenticationExpiredException When credentials invalid/expired
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiRequestException When request parameters invalid
     */
    public static function execute(callable $operation, string $context, array $logContext = []): mixed
    {
        try {
            return $operation();
        } catch (AuthenticationException $e) {
            Log::error(self::SERVICE_NAME . " authentication failed during {$context}", [
                ...$logContext,
                'error' => $e->getMessage(),
            ]);
            throw new AuthenticationExpiredException(self::SERVICE_NAME, 'Authentication failed', $e);
        } catch (ValidationErrorException $e) {
            $vndError = $e->getError();
            Log::error(self::SERVICE_NAME . " validation error during {$context}", [
                ...$logContext,
                'error' => $e->getMessage(),
                ...VndErrorFormatter::toLogContext($vndError),
            ]);
            throw new InvalidApiRequestException(
                self::SERVICE_NAME,
                VndErrorFormatter::toMessage($vndError),
                $e,
            );
        } catch (ConnectException $e) {
            Log::error(self::SERVICE_NAME . " connection failed during {$context}", [
                ...$logContext,
                'error' => $e->getMessage(),
            ]);
            throw new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
        }
    }
}

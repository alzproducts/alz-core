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
            throw self::handleAuthError($e, $context, $logContext);
        } catch (ValidationErrorException $e) {
            throw self::handleValidationError($e, $context, $logContext);
        } catch (ConnectException $e) {
            throw self::handleConnectionError($e, $context, $logContext);
        }
    }

    /**
     * @param array<string, mixed> $logContext
     */
    private static function handleAuthError(
        AuthenticationException $e,
        string $context,
        array $logContext,
    ): AuthenticationExpiredException {
        Log::error(self::SERVICE_NAME . " authentication failed during {$context}", [
            ...$logContext,
            'error' => $e->getMessage(),
        ]);

        return new AuthenticationExpiredException(self::SERVICE_NAME, 'Authentication failed', $e);
    }

    /**
     * @param array<string, mixed> $logContext
     */
    private static function handleValidationError(
        ValidationErrorException $e,
        string $context,
        array $logContext,
    ): InvalidApiRequestException {
        $vndError = $e->getError();
        Log::error(self::SERVICE_NAME . " validation error during {$context}", [
            ...$logContext,
            'error' => $e->getMessage(),
            ...VndErrorFormatter::toLogContext($vndError),
        ]);

        return new InvalidApiRequestException(
            self::SERVICE_NAME,
            VndErrorFormatter::toMessage($vndError),
            $e,
        );
    }

    /**
     * @param array<string, mixed> $logContext
     */
    private static function handleConnectionError(
        ConnectException $e,
        string $context,
        array $logContext,
    ): ExternalServiceUnavailableException {
        Log::error(self::SERVICE_NAME . " connection failed during {$context}", [
            ...$logContext,
            'error' => $e->getMessage(),
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }
}

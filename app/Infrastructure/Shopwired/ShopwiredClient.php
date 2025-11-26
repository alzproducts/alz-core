<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired;

use App\Application\Contracts\ShopwiredClientInterface;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Domain\Order\ValueObjects\PaymentMethod;
use App\Infrastructure\Shopwired\Responses\PaymentMethod as PaymentMethodDto;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Exceptions\CannotCreateData;

/**
 * Shopwired e-commerce API Client.
 *
 * Handles business logic for Shopwired API interactions:
 * - Input validation
 * - Response parsing and DTO creation
 * - Domain exception wrapping for parse failures
 *
 * HTTP concerns (auth, retry, timeout, exception translation) are delegated
 * to ShopwiredHttpTransport, following the separation of concerns principle.
 *
 * Design Philosophy: "Thin SDK"
 * - No caching (implement in Application layer if needed)
 * - No business logic beyond validation and parsing
 * - Simple error handling (throw on failures)
 *
 * @template-pattern API Client (Template Pattern)
 * @see https://shopwired.readme.io/docs/getting-started Official API documentation
 */
final readonly class ShopwiredClient implements ShopwiredClientInterface
{
    private const string SERVICE_NAME = 'Shopwired';

    private const string ENDPOINT_BUSINESS = 'business';

    private const string ENDPOINT_PAYMENT_METHODS = 'payment-methods';

    public function __construct(
        private ShopwiredHttpTransport $transport,
    ) {}

    /**
     * Verify API connectivity and authentication.
     *
     * Calls the /business endpoint to verify credentials work.
     * Returns 200 with business info on success.
     * Retry is disabled for connectivity checks (fail fast).
     */
    public function verifyConnectivity(): void
    {
        $this->transport->get(self::ENDPOINT_BUSINESS, retry: false);
    }

    /**
     * List available payment methods.
     *
     * @return list<PaymentMethod>
     */
    public function listPaymentMethods(): array
    {
        $response = $this->transport->get(self::ENDPOINT_PAYMENT_METHODS);

        $dtos = $this->parseArrayResponse($response->json(), PaymentMethodDto::class);

        /** @var array<int, PaymentMethodDto> $dtosArray */
        $dtosArray = $dtos->all();

        return self::mapToDomainPaymentMethods($dtosArray);
    }

    /**
     * Parse API response expecting an array of DTOs.
     * @template T of Data
     *
     * @param class-string<T> $dtoClass
     *
     * @return DataCollection<int, T>
     * @throws InvalidApiResponseException When response structure is invalid
     * @noinspection PhpSameParameterValueInspection*/
    private function parseArrayResponse(mixed $data, string $dtoClass): DataCollection
    {
        if (! \is_array($data)) {
            self::logParsingFailure('Expected array response', $data);

            throw new InvalidApiResponseException(
                serviceName: self::SERVICE_NAME,
                message: 'Expected array response',
            );
        }

        try {
            return $dtoClass::collect($data, DataCollection::class);
        } /** @noinspection PhpRedundantCatchClauseInspection */ catch (CannotCreateData $e) {
            self::logParsingFailure($e->getMessage(), $data);

            throw new InvalidApiResponseException(
                serviceName: self::SERVICE_NAME,
                message: 'API returned invalid data structure',
                previous: $e,
            );
        }
    }

    /**
     * Log parsing failure with context for debugging API contract changes.
     */
    private static function logParsingFailure(string $error, mixed $data): void
    {
        Log::critical(self::SERVICE_NAME . ' API response validation failed', [
            'error' => $error,
            'raw_response' => $data,
        ]);
    }

    /**
     * Map infrastructure DTOs to domain value objects.
     *
     * @param array<int, PaymentMethodDto> $dtos
     *
     * @return list<PaymentMethod>
     */
    private static function mapToDomainPaymentMethods(array $dtos): array
    {
        return \array_values(\array_map(
            static fn(PaymentMethodDto $dto): PaymentMethod => $dto->toDomainPaymentMethod(),
            $dtos,
        ));
    }
}

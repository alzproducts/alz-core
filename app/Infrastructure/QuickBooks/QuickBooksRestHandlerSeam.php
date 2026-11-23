<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Domain\Exceptions\InvalidConfigurationException;
use Exception;
use QuickBooksOnline\API\Core\HttpClients\HttpClientInterface;
use QuickBooksOnline\API\Core\HttpClients\SyncRestHandler;
use QuickBooksOnline\API\DataService\DataService;
use ReflectionException;
use ReflectionProperty;
use Throwable;
use TypeError;

/**
 * The one reflection seam into the SDK: `DataService` builds its rest handler from a client name and
 * offers no public way to supply a client. It fails closed, so a renamed or retyped SDK property can
 * never leave the stock, host-unchecked client in place.
 */
final class QuickBooksRestHandlerSeam
{
    private const string PROPERTY = 'restHandler';

    public static function install(DataService $dataService, HttpClientInterface $httpClient): void
    {
        try {
            $property = new ReflectionProperty(DataService::class, self::PROPERTY);
            if (!$property->getValue($dataService) instanceof SyncRestHandler) {
                throw self::failure('DataService has no SyncRestHandler to replace');
            }

            $property->setValue($dataService, new SyncRestHandler($dataService->getServiceContext(), $httpClient));
        } catch (ReflectionException|TypeError $e) {
            throw self::failure('DataService rest handler property is missing or retyped', $e);
        } catch (Exception $e) { // @ignoreException - getServiceContext throws a bare SDK exception; fail closed
            throw self::failure('DataService has no service context', $e);
        }
    }

    private static function failure(string $detail, ?Throwable $previous = null): InvalidConfigurationException
    {
        return new InvalidConfigurationException('quickbooks/v3-php-sdk', $detail, $previous);
    }
}

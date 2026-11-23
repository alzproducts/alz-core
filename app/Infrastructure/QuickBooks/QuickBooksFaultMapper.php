<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Application\QuickBooks\OrderSync\Results\BatchItemOutcomeResult;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\QuickBooks\Enums\QuickBooksFaultKind;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\QuickBooks\ValueObjects\QuickBooksFault;
use QuickBooksOnline\API\Core\HttpClients\FaultHandler;
use QuickBooksOnline\API\Core\HttpClients\IntuitResponse;
use QuickBooksOnline\API\Data\IPPIntuitEntity;
use QuickBooksOnline\API\DataService\IntuitBatchResponse;
use QuickBooksOnline\API\Exception\ValidationException;
use SimpleXMLElement;

/**
 * Turns QuickBooks Faults into Domain errors. The only place Intuit fault codes are read.
 * Classifies on HTTP status first: QBO answers validation, duplicate and mapping errors alike with 400,
 * and a gateway 429 or 5xx carries no Intuit code.
 */
final readonly class QuickBooksFaultMapper
{
    private const array KIND_BY_CODE = [
        '6140' => QuickBooksFaultKind::DuplicateDocNumber,
        '6240' => QuickBooksFaultKind::DuplicateCustomerName,
        '6210' => QuickBooksFaultKind::ClosedPeriod,
        '610' => QuickBooksFaultKind::InvalidReference,
        '2500' => QuickBooksFaultKind::InvalidReference,
    ];

    private const array AUTH_FAULT_TYPES = ['AuthenticationFault', 'AuthorizationFault'];

    public static function callLevel(
        FaultHandler $fault,
        ?int $retryAfter,
    ): AuthenticationExpiredException|ExternalServiceUnavailableException|QuickBooksFaultException {
        $status = self::status($fault);

        if ($status === null || $status === 429 || $status < 400 || $status >= 500) {
            return new ExternalServiceUnavailableException(
                QuickBooksOAuthHttpTransport::SERVICE_NAME,
                $status === 429 ? $retryAfter : null,
            );
        }

        $parsed = self::parsed($fault, $status);
        if ($status === 401 || $status === 403 || \in_array($parsed->getIntuitErrorType(), self::AUTH_FAULT_TYPES, true)) {
            return self::authFailure();
        }

        return new QuickBooksFaultException(QuickBooksOAuthHttpTransport::SERVICE_NAME, self::fault($parsed));
    }

    /**
     * One batch item's outcome. Only a per-item ValidationFault is a Fault the item's own data caused; any other or
     * unreadable response leaves it unknown whether QBO created the document.
     *
     * @param class-string<IPPIntuitEntity> $expected
     */
    public static function batchItem(mixed $response, string $expected): BatchItemOutcomeResult
    {
        if (!$response instanceof IntuitBatchResponse) {
            return BatchItemOutcomeResult::ambiguous();
        }

        if ($response->isSuccess()) {
            $id = $response->entity instanceof $expected ? self::text($response->entity->Id) : null;

            return $id === null ? BatchItemOutcomeResult::ambiguous() : BatchItemOutcomeResult::created($id);
        }

        // intentional: a Service, Security or bare IdsException may follow a create QBO did commit, so it stays Ambiguous
        $exception = $response->exception;
        if (!$exception instanceof ValidationException || $exception::class !== ValidationException::class) {
            return BatchItemOutcomeResult::ambiguous();
        }

        return BatchItemOutcomeResult::faulted(self::validationFault($exception));
    }

    private static function validationFault(ValidationException $exception): QuickBooksFault
    {
        $code = self::text($exception->getCode());
        $code = $code === '0' ? null : $code;

        return new QuickBooksFault(
            kind: $code === null ? QuickBooksFaultKind::Validation : (self::KIND_BY_CODE[$code] ?? QuickBooksFaultKind::Validation),
            code: $code,
            element: null,
            message: self::text($exception->getMessage()),
        );
    }

    /**
     * Whether a 2xx body holds a Fault, which the SDK never records: directly under the root, or inside `QueryResponse`.
     */
    public static function hasFault(?string $body): bool
    {
        $xml = self::xml($body);

        return $xml !== null && (isset($xml->Fault) || isset($xml->QueryResponse->Fault));
    }

    /**
     * Whether a body the SDK read no rows from is a query response without a Fault.
     */
    public static function isZeroRows(?string $body): bool
    {
        $xml = self::xml($body);

        // intentional: isset, not truthiness, because an empty SimpleXML element casts to false
        return $xml !== null && isset($xml->QueryResponse) && !self::hasFault($body);
    }

    private static function xml(?string $body): ?SimpleXMLElement
    {
        if ($body === null) {
            return null;
        }

        $xml = QuickBooksXmlParsing::quietly(static fn(): SimpleXMLElement|false => \simplexml_load_string($body));

        return $xml === false ? null : $xml;
    }

    /**
     * Null when the SDK recorded no status: the PHPDoc says int, but a handler built without a response holds null.
     */
    public static function status(FaultHandler $fault): ?int
    {
        $status = $fault->getHttpStatusCode();

        return $status > 0 ? $status : null;
    }

    private static function fault(FaultHandler $parsed): QuickBooksFault
    {
        $code = self::code($parsed);
        $text = \array_filter(
            [self::text($parsed->getIntuitErrorMessage()), self::text($parsed->getIntuitErrorDetail())],
            static fn(?string $part): bool => $part !== null,
        );

        return new QuickBooksFault(
            kind: $code === null ? QuickBooksFaultKind::Validation : (self::KIND_BY_CODE[$code] ?? QuickBooksFaultKind::Validation),
            code: $code,
            element: self::text($parsed->getIntuitErrorElement()),
            message: $text === [] ? null : \implode(' - ', $text),
        );
    }

    /**
     * The SDK parses a Fault body only under an exact XML content type, so `application/xml; charset=utf-8`
     * arrives unparsed: parse it again here, under the exact type.
     */
    private static function parsed(FaultHandler $fault, int $status): FaultHandler
    {
        $body = $fault->getResponseBody();
        if (self::code($fault) !== null || \mb_trim($body) === '') {
            return $fault;
        }

        $reparsed = QuickBooksXmlParsing::quietly(
            static fn(): mixed => new IntuitResponse("HTTP/1.1 {$status}\r\nContent-Type: application/xml\r\n", $body, $status, true)->getFaultHandler(),
        );

        return self::faultOrNull($reparsed) ?? $fault;
    }

    /**
     * The SDK's PHPDoc promises a FaultHandler where it holds `false` when there is no fault.
     */
    public static function faultOrNull(mixed $value): ?FaultHandler
    {
        return $value instanceof FaultHandler ? $value : null;
    }

    /**
     * @return non-empty-string|null
     */
    private static function code(FaultHandler $fault): ?string
    {
        $code = self::text($fault->getIntuitErrorCode());

        return $code === '0' ? null : $code;
    }

    /**
     * @return non-empty-string|null
     */
    private static function text(mixed $value): ?string
    {
        if (!\is_string($value) && !\is_int($value)) {
            return null;
        }

        $text = \mb_trim((string) $value);

        return $text === '' ? null : $text;
    }

    private static function authFailure(): AuthenticationExpiredException
    {
        return new AuthenticationExpiredException(
            QuickBooksOAuthHttpTransport::SERVICE_NAME,
            'QuickBooks rejected the access token; reconnect with quickbooks:connect if this persists',
        );
    }
}

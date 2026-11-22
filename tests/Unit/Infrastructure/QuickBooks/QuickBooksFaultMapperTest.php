<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\QuickBooks;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\QuickBooks\Enums\QuickBooksFaultKind;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Infrastructure\QuickBooks\QuickBooksFaultMapper;
use App\Infrastructure\QuickBooks\QuickBooksXmlParsing;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QuickBooksOnline\API\Core\HttpClients\FaultHandler;
use QuickBooksOnline\API\Core\HttpClients\IntuitResponse;

#[CoversClass(QuickBooksFaultMapper::class)]
final class QuickBooksFaultMapperTest extends TestCase
{
    #[Test]
    #[DataProvider('contentTypes')]
    public function a_fault_maps_its_code_element_and_message(string $contentType): void
    {
        $failure = QuickBooksFaultMapper::callLevel(
            self::fault(400, self::faultXml('6140', 'DocNumber', 'Duplicate Document Number Error', 'Doc number in use'), $contentType),
            null,
        );

        $this->assertInstanceOf(QuickBooksFaultException::class, $failure);
        $this->assertSame(QuickBooksFaultKind::DuplicateDocNumber, $failure->fault->kind);
        $this->assertSame('6140', $failure->fault->code);
        $this->assertSame('DocNumber', $failure->fault->element);
        $this->assertSame('Duplicate Document Number Error - Doc number in use', $failure->fault->message);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function contentTypes(): array
    {
        return [
            'exact xml' => ['application/xml'],
            'xml with spaced charset' => ['application/xml; charset=utf-8'],
        ];
    }

    #[Test]
    #[DataProvider('faultCodes')]
    public function each_fault_code_maps_to_its_kind(string $code, QuickBooksFaultKind $kind): void
    {
        $failure = QuickBooksFaultMapper::callLevel(self::fault(400, self::faultXml($code)), null);

        $this->assertInstanceOf(QuickBooksFaultException::class, $failure);
        $this->assertSame($kind, $failure->fault->kind);
    }

    /**
     * @return array<string, array{string, QuickBooksFaultKind}>
     */
    public static function faultCodes(): array
    {
        return [
            '6140 duplicate doc number' => ['6140', QuickBooksFaultKind::DuplicateDocNumber],
            '6240 duplicate customer name' => ['6240', QuickBooksFaultKind::DuplicateCustomerName],
            '6210 closed period' => ['6210', QuickBooksFaultKind::ClosedPeriod],
            '610 object not found' => ['610', QuickBooksFaultKind::InvalidReference],
            '2500 invalid reference' => ['2500', QuickBooksFaultKind::InvalidReference],
            'other code' => ['2020', QuickBooksFaultKind::Validation],
        ];
    }

    #[Test]
    public function a_validation_fault_without_a_known_code_is_validation(): void
    {
        $failure = QuickBooksFaultMapper::callLevel(self::fault(400, self::faultXml('2050', type: 'ValidationFault')), null);

        $this->assertInstanceOf(QuickBooksFaultException::class, $failure);
        $this->assertSame(QuickBooksFaultKind::Validation, $failure->fault->kind);
    }

    #[Test]
    public function another_4xx_without_a_parsable_body_is_validation_with_no_detail(): void
    {
        $failure = QuickBooksFaultMapper::callLevel(self::fault(404, 'not xml at all', 'text/html'), null);

        $this->assertInstanceOf(QuickBooksFaultException::class, $failure);
        $this->assertSame(QuickBooksFaultKind::Validation, $failure->fault->kind);
        $this->assertNull($failure->fault->code);
        $this->assertNull($failure->fault->element);
        $this->assertNull($failure->fault->message);
    }

    #[Test]
    #[DataProvider('authFailures')]
    public function auth_failures_raise_authentication_expired(int $status, string $body): void
    {
        $this->assertInstanceOf(AuthenticationExpiredException::class, QuickBooksFaultMapper::callLevel(self::fault($status, $body), null));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function authFailures(): array
    {
        return [
            '401' => [401, ''],
            '403' => [403, ''],
            'authentication fault on a 400' => [400, self::faultXml('3200', type: 'AuthenticationFault')],
            'authorization fault on a 400' => [400, self::faultXml('3100', type: 'AuthorizationFault')],
        ];
    }

    #[Test]
    public function a_429_is_unavailable_and_carries_retry_after(): void
    {
        $failure = QuickBooksFaultMapper::callLevel(self::fault(429, ''), 12);

        $this->assertInstanceOf(ExternalServiceUnavailableException::class, $failure);
        $this->assertSame(12, $failure->retryAfter);
    }

    #[Test]
    public function a_5xx_is_unavailable_whatever_its_body(): void
    {
        $failure = QuickBooksFaultMapper::callLevel(self::fault(503, self::faultXml('6140')), 30);

        $this->assertInstanceOf(ExternalServiceUnavailableException::class, $failure);
        $this->assertNull($failure->retryAfter);
    }

    #[Test]
    public function the_status_reads_as_an_integer(): void
    {
        $this->assertSame(400, QuickBooksFaultMapper::status(self::fault(400, '')));
        $this->assertNull(QuickBooksFaultMapper::status(new FaultHandler()));
    }

    /**
     * Built the way the curl client builds it, so the SDK's own content-type parsing runs.
     */
    private static function fault(int $status, string $body, string $contentType = 'application/xml'): FaultHandler
    {
        $fault = QuickBooksXmlParsing::quietly(
            static fn(): mixed => new IntuitResponse("HTTP/1.1 {$status}\r\nContent-Type: {$contentType}\r\n", $body, $status, true)->getFaultHandler(),
        );
        self::assertInstanceOf(FaultHandler::class, $fault);

        return $fault;
    }

    private static function faultXml(string $code, string $element = '', string $message = '', string $detail = '', string $type = 'ValidationFault'): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <IntuitResponse xmlns="http://schema.intuit.com/finance/v3">
              <Fault type="{$type}">
                <Error code="{$code}" element="{$element}"><Message>{$message}</Message><Detail>{$detail}</Detail></Error>
              </Fault>
            </IntuitResponse>
            XML;
    }
}

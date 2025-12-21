<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Support;

use App\Application\Support\EmailAliasResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(EmailAliasResolver::class)]
final class EmailAliasResolverTest extends TestCase
{
    #[Test]
    public function resolves_aliased_email_to_service_email(): void
    {
        $resolver = new EmailAliasResolver([
            'tom.murray@alzproducts.co.uk' => 'tom@alzproducts.co.uk',
        ]);

        $result = $resolver->resolve('tom.murray@alzproducts.co.uk');

        $this->assertSame('tom@alzproducts.co.uk', $result);
    }

    #[Test]
    public function returns_normalized_original_email_when_no_alias_configured(): void
    {
        $resolver = new EmailAliasResolver([
            'tom.murray@alzproducts.co.uk' => 'tom@alzproducts.co.uk',
        ]);

        $result = $resolver->resolve('other@example.com');

        $this->assertSame('other@example.com', $result);
    }

    #[Test]
    public function returns_normalized_email_with_empty_aliases(): void
    {
        $resolver = new EmailAliasResolver([]);

        $result = $resolver->resolve('user@example.com');

        $this->assertSame('user@example.com', $result);
    }

    #[Test]
    #[DataProvider('caseInsensitiveProvider')]
    public function lookup_is_case_insensitive(string $input, string $expected): void
    {
        $resolver = new EmailAliasResolver([
            'tom.murray@alzproducts.co.uk' => 'tom@alzproducts.co.uk',
        ]);

        $result = $resolver->resolve($input);

        $this->assertSame($expected, $result);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function caseInsensitiveProvider(): array
    {
        return [
            'lowercase' => ['tom.murray@alzproducts.co.uk', 'tom@alzproducts.co.uk'],
            'uppercase' => ['TOM.MURRAY@ALZPRODUCTS.CO.UK', 'tom@alzproducts.co.uk'],
            'mixed case' => ['Tom.Murray@AlzProducts.Co.Uk', 'tom@alzproducts.co.uk'],
        ];
    }

    #[Test]
    public function normalizes_output_with_trim_and_lowercase(): void
    {
        $resolver = new EmailAliasResolver([
            'tom.murray@alzproducts.co.uk' => '  TOM@AlzProducts.Co.Uk  ',
        ]);

        $result = $resolver->resolve('tom.murray@alzproducts.co.uk');

        $this->assertSame('tom@alzproducts.co.uk', $result);
    }

    #[Test]
    public function trims_input_email_before_lookup(): void
    {
        $resolver = new EmailAliasResolver([
            'tom.murray@alzproducts.co.uk' => 'tom@alzproducts.co.uk',
        ]);

        $result = $resolver->resolve('  tom.murray@alzproducts.co.uk  ');

        $this->assertSame('tom@alzproducts.co.uk', $result);
    }

    #[Test]
    public function normalizes_unaliased_email_to_lowercase(): void
    {
        $resolver = new EmailAliasResolver([]);

        $result = $resolver->resolve('USER@EXAMPLE.COM');

        $this->assertSame('user@example.com', $result);
    }

    #[Test]
    public function handles_multiple_aliases(): void
    {
        $resolver = new EmailAliasResolver([
            'tom.murray@alzproducts.co.uk' => 'tom@alzproducts.co.uk',
            'jane.doe@company.com' => 'jane@company.com',
            'john.smith@org.net' => 'jsmith@org.net',
        ]);

        $this->assertSame('tom@alzproducts.co.uk', $resolver->resolve('tom.murray@alzproducts.co.uk'));
        $this->assertSame('jane@company.com', $resolver->resolve('jane.doe@company.com'));
        $this->assertSame('jsmith@org.net', $resolver->resolve('john.smith@org.net'));
        $this->assertSame('unknown@example.com', $resolver->resolve('unknown@example.com'));
    }
}

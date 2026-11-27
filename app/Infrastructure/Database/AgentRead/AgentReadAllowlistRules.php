<?php

declare(strict_types=1);

namespace App\Infrastructure\Database\AgentRead;

/**
 * Checks a Read allowlist against the agent-read rules: the nine business schemas only, no column whose name marks
 * a person's identifier, customer-written free text, a staff identifier or a secret, and no JSON column unless it is
 * on the JSON exception list, which may name no order, customer, basket, contact or call relation.
 */
final readonly class AgentReadAllowlistRules
{
    public const array SCHEMAS = [
        'catalog', 'linnworks', 'shopwired', 'operations', 'reviews_io', 'marketing', 'checkout', 'customer_service', 'quickbooks',
    ];

    /** Rule label => column-name pattern. */
    public const array EXCLUDED_COLUMN_PATTERNS = [
        'email' => '/email/',
        'phone' => '/phone|tel_number|mobile|fax/',
        'street, postcode or IP address' => '/address|postcode|post_code/',
        'town or region' => '/(^|_)(city|town|province|state|region)$/',
        'person name' => '/(^|_)(first|last|full|billing|delivery|buyer|contact)_name$|user_?name/',
        'company name' => '/company/',
        'date of birth' => '/date_of_birth/',
        'device or browser' => '/user_agent|device_info/',
        'ad click ID' => '/^(gclid|gclsrc|gbraid|wbraid|msclkid|fbclid)$/',
        'parcel tracking' => '/^tracking_number$|tracking_url/',
        'customer-written reference' => '/customer_reference/',
        'free text' => '/(^|_)(notes?|comments?)$/',
        'staff identifier' => '/_by$/',
        'secret or token' => '/token|secret|password|api_key|invoice_url/',
    ];

    public const array JSON_FORBIDDEN_RELATION_SUBSTRINGS = ['order', 'customer', 'basket', 'contact', 'call'];

    private const array JSON_TYPES = ['json', 'jsonb'];

    /**
     * @param array<string, string> $columnTypes type name keyed schema.relation.column, for every column that exists
     *
     * @return list<string> one message per breach, each naming the relation or column
     */
    public function violations(AgentReadAllowlist $allowlist, array $columnTypes): array
    {
        $violations = [];

        foreach ($allowlist->relations as $relation => $columns) {
            $violations = [...$violations, ...$this->relationViolations($relation, $columns, $columnTypes, $allowlist)];
        }

        foreach ($allowlist->jsonExceptions as $exception) {
            $violations = [...$violations, ...self::jsonExceptionViolations($exception)];
        }

        return $violations;
    }

    /**
     * @param list<string> $columns
     * @param array<string, string> $columnTypes
     *
     * @return list<string>
     */
    private function relationViolations(string $relation, array $columns, array $columnTypes, AgentReadAllowlist $allowlist): array
    {
        $schema = \explode('.', $relation, 2)[0];
        if (\mb_substr_count($relation, '.') !== 1 || !\in_array($schema, self::SCHEMAS, true)) {
            return ["{$relation}: relation is outside the allowlisted schemas"];
        }

        $violations = $columns === [] ? ["{$relation}: relation lists no columns"] : [];
        foreach ($columns as $column) {
            $violations = [...$violations, ...$this->columnViolations("{$relation}.{$column}", $column, $columnTypes, $allowlist)];
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private static function jsonExceptionViolations(string $exception): array
    {
        $parts = \explode('.', $exception);
        if (\count($parts) !== 3) {
            return ["{$exception}: JSON exception must be schema.relation.column"];
        }

        $violations = [];
        foreach (self::JSON_FORBIDDEN_RELATION_SUBSTRINGS as $substring) {
            if (\str_contains($parts[1], $substring)) {
                $violations[] = "{$exception}: JSON exception on a relation whose name contains '{$substring}'";
            }
        }

        return $violations;
    }

    /**
     * @param array<string, string> $columnTypes
     *
     * @return list<string>
     */
    private function columnViolations(string $qualified, string $column, array $columnTypes, AgentReadAllowlist $allowlist): array
    {
        $violations = [];

        foreach (self::EXCLUDED_COLUMN_PATTERNS as $label => $pattern) {
            if (\preg_match($pattern, $column) === 1) {
                $violations[] = "{$qualified}: column name matches the excluded {$label} rule";
            }
        }

        $type = $columnTypes[$qualified] ?? null;
        if ($type === null) {
            $violations[] = "{$qualified}: column does not exist";
        } elseif (\in_array($type, self::JSON_TYPES, true) && !\in_array($qualified, $allowlist->jsonExceptions, true)) {
            $violations[] = "{$qualified}: {$type} column is not on the JSON exception list";
        }

        return $violations;
    }
}

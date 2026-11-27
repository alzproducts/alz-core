<?php

declare(strict_types=1);

namespace App\Infrastructure\Database\AgentRead;

use LogicException;

/**
 * The Read allowlist (relation => columns) and the JSON exception list (schema.relation.column), as committed.
 */
final readonly class AgentReadAllowlist
{
    public const string READ_ALLOWLIST_FILE = 'agent-read/read-allowlist.php';

    public const string JSON_EXCEPTIONS_FILE = 'agent-read/json-exceptions.php';

    /**
     * @param array<string, list<string>> $relations keyed schema.relation
     * @param list<string> $jsonExceptions each schema.relation.column
     */
    public function __construct(
        public array $relations,
        public array $jsonExceptions,
    ) {}

    public static function committed(): self
    {
        return self::fromFiles(\database_path(self::READ_ALLOWLIST_FILE), \database_path(self::JSON_EXCEPTIONS_FILE));
    }

    public static function fromFiles(string $readAllowlist, string $jsonExceptions): self
    {
        $relations = require $readAllowlist;
        $exceptions = require $jsonExceptions;

        if (!\is_array($exceptions) || !\array_is_list($exceptions)) {
            throw new LogicException('The JSON exception list must be a list of schema.relation.column names');
        }

        return new self(self::relations($relations), \array_map(self::name(...), $exceptions));
    }

    /**
     * @return list<string> every schema holding an allowlisted relation
     */
    public function schemas(): array
    {
        $schemas = \array_map(static fn(string $relation): string => \explode('.', $relation, 2)[0], \array_keys($this->relations));

        return \array_values(\array_unique($schemas));
    }

    /**
     * @return list<string> every allowlisted column as schema.relation.column
     */
    public function columns(): array
    {
        $columns = [];
        foreach ($this->relations as $relation => $names) {
            foreach ($names as $column) {
                $columns[] = "{$relation}.{$column}";
            }
        }

        return $columns;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function relations(mixed $relations): array
    {
        if (!\is_array($relations)) {
            throw new LogicException('The Read allowlist must return an array');
        }

        $parsed = [];
        foreach ($relations as $relation => $columns) {
            if (!\is_string($relation) || !\is_array($columns) || !\array_is_list($columns)) {
                throw new LogicException('The Read allowlist must map schema.relation to a list of column names');
            }

            $parsed[$relation] = \array_map(self::name(...), $columns);
        }

        return $parsed;
    }

    private static function name(mixed $value): string
    {
        if (!\is_string($value) || $value === '') {
            throw new LogicException('Allowlist entries must be non-empty strings');
        }

        return $value;
    }
}

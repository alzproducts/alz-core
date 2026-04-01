<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Architecture;

use PhpParser\Node;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Eloquent model $table properties must include the database schema prefix.
 *
 * In Supabase/PostgreSQL with multiple schemas (shopwired, access, config, etc.),
 * table names must be schema-qualified to prevent ambiguity and ensure correct
 * routing. Uses dot notation: 'schema.table_name'.
 *
 * @implements Rule<Property>
 */
final class SchemaQualifiedTableNameRule implements Rule
{
    public function getNodeType(): string
    {
        return Property::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! self::isInInfrastructureNamespace($scope)) {
            return [];
        }

        $unqualifiedTableName = self::findUnqualifiedTableName($node, $scope);

        return $unqualifiedTableName !== null ? [self::buildError($unqualifiedTableName)] : [];
    }

    private static function buildError(string $tableName): IdentifierRuleError
    {
        return RuleErrorBuilder::message(
            \sprintf(
                'Table name \'%s\' must be schema-qualified (e.g. \'public.%s\'). '
                . 'All Eloquent models must use schema.table_name format for PostgreSQL schema routing.',
                $tableName,
                $tableName,
            ),
        )
            ->identifier('alz.schemaQualifiedTableName')
            ->build();
    }

    private static function isInInfrastructureNamespace(Scope $scope): bool
    {
        $namespace = $scope->getNamespace();

        return $namespace !== null && \str_starts_with($namespace, 'App\\Infrastructure');
    }

    private static function findUnqualifiedTableName(Property $node, Scope $scope): ?string
    {
        $tableProp = \array_find(
            $node->props,
            static fn(PropertyItem $prop): bool => $prop->name->toString() === 'table',
        );

        if ($tableProp === null || ! self::isEloquentModel($scope)) {
            return null;
        }

        $default = $tableProp->default;

        return $default instanceof String_ && ! \str_contains($default->value, '.') ? $default->value : null;
    }

    private static function isEloquentModel(Scope $scope): bool
    {
        if (! $scope->isInClass()) {
            return false;
        }

        return $scope->getClassReflection()
            ->getNativeReflection()
            ->isSubclassOf('Illuminate\\Database\\Eloquent\\Model');
    }
}

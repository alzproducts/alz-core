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
        $namespace = $scope->getNamespace();

        if ($namespace === null || ! \str_starts_with($namespace, 'App\\Infrastructure')) {
            return [];
        }

        // Find $table property in declaration
        $tableProp = \array_find(
            $node->props,
            static fn(PropertyItem $prop): bool => $prop->name->toString() === 'table',
        );

        if ($tableProp === null) {
            return [];
        }

        if (! $scope->isInClass()) {
            return [];
        }

        // Must be in an Eloquent Model subclass
        $nativeReflection = $scope->getClassReflection()->getNativeReflection();

        if (! $nativeReflection->isSubclassOf('Illuminate\\Database\\Eloquent\\Model')) {
            return [];
        }

        // Check the default value is a string literal
        $default = $tableProp->default;

        if (! $default instanceof String_) {
            return [];
        }

        // Schema-qualified names contain a dot (e.g. 'shopwired.orders')
        if (\str_contains($default->value, '.')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                \sprintf(
                    'Table name \'%s\' must be schema-qualified (e.g. \'public.%s\'). '
                    . 'All Eloquent models must use schema.table_name format for PostgreSQL schema routing.',
                    $default->value,
                    $default->value,
                ),
            )
                ->identifier('alz.schemaQualifiedTableName')
                ->build(),
        ];
    }
}

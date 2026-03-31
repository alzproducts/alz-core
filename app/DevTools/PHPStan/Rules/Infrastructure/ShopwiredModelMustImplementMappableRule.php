<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Infrastructure;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Shopwired Eloquent models must implement EloquentDomainMappableInterface.
 *
 * This ensures all Shopwired models provide toDomain() and fromDomainAttributes()
 * methods for bidirectional conversion between Eloquent and Domain objects.
 * The interface enables generic repository operations via AbstractEloquentRepository.
 *
 * @implements Rule<InClassNode>
 */
final class ShopwiredModelMustImplementMappableRule implements Rule
{
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $node->getClassReflection();
        $className = $classReflection->getName();

        // Only check Eloquent models in infrastructure model namespaces
        if (
            ! \str_starts_with($className, 'App\\Infrastructure\\Shopwired\\Models\\')
            && ! \str_contains($className, '\\Infrastructure\\Catalog\\')
        ) {
            return [];
        }

        // Models outside a Models namespace are not subject to this rule
        if (! \str_contains($className, '\\Models\\')) {
            return [];
        }

        // Skip read-only view models — they back PostgreSQL views and have no write path,
        // so implementing fromDomainAttributes() would be semantically incorrect
        if (\str_ends_with($className, 'ViewModel')) {
            return [];
        }

        // Skip interfaces and abstract classes
        if ($classReflection->isInterface() || $classReflection->isAbstract()) {
            return [];
        }

        // Must be an Eloquent Model
        $nativeReflection = $classReflection->getNativeReflection();

        if (! $nativeReflection->isSubclassOf('Illuminate\\Database\\Eloquent\\Model')) {
            return [];
        }

        // Must implement EloquentDomainMappableInterface
        if ($nativeReflection->implementsInterface('App\\Infrastructure\\Contracts\\EloquentDomainMappableInterface')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Shopwired model must implement EloquentDomainMappableInterface '
                . 'for bidirectional Domain conversion (toDomain + fromDomainAttributes).',
            )
                ->identifier('alz.shopwiredModelMustImplementMappable')
                ->build(),
        ];
    }
}

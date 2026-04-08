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
        if (! self::isApplicableModel($node)) {
            return [];
        }

        if (self::implementsMappableInterface($node)) {
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

    private static function isApplicableModel(InClassNode $node): bool
    {
        $classReflection = $node->getClassReflection();
        $className = $classReflection->getName();

        if (! self::isInShopwiredOrCatalogModels($className)) {
            return false;
        }

        if (\str_ends_with($className, 'ViewModel') || \str_ends_with($className, 'ExtraDataModel')) {
            return false;
        }

        if ($classReflection->isInterface() || $classReflection->isAbstract()) {
            return false;
        }

        return $classReflection->getNativeReflection()
            ->isSubclassOf('Illuminate\\Database\\Eloquent\\Model');
    }

    private static function isInShopwiredOrCatalogModels(string $className): bool
    {
        if (! \str_contains($className, '\\Models\\')) {
            return false;
        }

        return \str_starts_with($className, 'App\\Infrastructure\\Shopwired\\Models\\')
            || \str_contains($className, '\\Infrastructure\\Catalog\\');
    }

    private static function implementsMappableInterface(InClassNode $node): bool
    {
        return $node->getClassReflection()
            ->getNativeReflection()
            ->implementsInterface('App\\Infrastructure\\Contracts\\EloquentDomainMappableInterface');
    }
}

<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Infrastructure;

use App\Infrastructure\Contracts\DomainConvertibleInterface;
use App\Infrastructure\Contracts\EloquentDomainMappableInterface;
use App\Infrastructure\Repositories\AbstractEloquentRepository;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Shopwired / Catalog Eloquent models must expose Domain conversion.
 *
 * Every model under `App\Infrastructure\Shopwired\Models\` or
 * `App\Infrastructure\Catalog\**\Models\` must implement either:
 *   • {@see EloquentDomainMappableInterface} — bidirectional
 *     (`toDomain()` + `fromDomainAttributes()`) for models driven by
 *     {@see AbstractEloquentRepository}'s generic flow.
 *   • {@see DomainConvertibleInterface} — read-only
 *     (`toDomain()` only) for models whose write side uses an Application-layer Command
 *     instead of a Domain VO (e.g. custom-field settings partial upserts).
 *
 * The weaker read-only contract is the universal floor — if a model is persisted at all,
 * the write side has its own typed path (Command → repo inline mapping). The stronger
 * bidirectional contract is still required by the generic repository abstraction when
 * applicable.
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

        if (self::implementsConvertibleInterface($node)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Shopwired/Catalog model must implement DomainConvertibleInterface '
                . '(toDomain) or EloquentDomainMappableInterface (bidirectional) '
                . 'to expose Domain conversion.',
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

    private static function implementsConvertibleInterface(InClassNode $node): bool
    {
        return $node->getClassReflection()
            ->getNativeReflection()
            ->implementsInterface('App\\Infrastructure\\Contracts\\DomainConvertibleInterface');
    }
}

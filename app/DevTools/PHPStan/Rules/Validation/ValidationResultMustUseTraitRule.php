<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Validation;

use App\Domain\Shared\Validation\Concerns\AggregatesChildResultsTrait;
use App\Domain\Shared\Validation\Concerns\ThrowsOnValidationFailureTrait;
use App\Domain\Shared\Validation\Contracts\DescribableValidationResultInterface;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Validation results must use one of the mandated traits.
 *
 * Every concrete class implementing DescribableValidationResultInterface must use
 * either ThrowsOnValidationFailureTrait (single results) or AggregatesChildResultsTrait
 * (aggregate results). This prevents bespoke orFail() implementations from drifting
 * across the codebase.
 *
 * @implements Rule<InClassNode>
 */
final class ValidationResultMustUseTraitRule implements Rule
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
        if (! self::isConcreteValidationResult($node)) {
            return [];
        }

        if (self::usesRequiredTrait($node)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Validation result must use ThrowsOnValidationFailureTrait (single results) '
                . 'or AggregatesChildResultsTrait (aggregate results).',
            )
                ->identifier('alz.validationResultMustUseTrait')
                ->build(),
        ];
    }

    private static function isConcreteValidationResult(InClassNode $node): bool
    {
        $classReflection = $node->getClassReflection();
        $className = $classReflection->getName();

        if (! \str_starts_with($className, 'App\\Domain\\') && ! \str_starts_with($className, 'App\\Application\\')) {
            return false;
        }

        if ($classReflection->isAbstract() || $classReflection->isInterface() || $classReflection->isEnum() || $classReflection->isTrait()) {
            return false;
        }

        return $classReflection->getNativeReflection()
            ->implementsInterface(DescribableValidationResultInterface::class);
    }

    private static function usesRequiredTrait(InClassNode $node): bool
    {
        $traitNames = $node->getClassReflection()->getNativeReflection()->getTraitNames();

        return \in_array(AggregatesChildResultsTrait::class, $traitNames, true)
            || \in_array(ThrowsOnValidationFailureTrait::class, $traitNames, true);
    }
}

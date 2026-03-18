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
        $classReflection = $node->getClassReflection();
        $className = $classReflection->getName();

        // Fast path: only check Domain and Application layers
        if (! \str_starts_with($className, 'App\\Domain\\') && ! \str_starts_with($className, 'App\\Application\\')) {
            return [];
        }

        // Skip abstract classes, interfaces, traits, and enums
        if ($classReflection->isAbstract() || $classReflection->isInterface() || $classReflection->isEnum()) {
            return [];
        }

        if ($classReflection->isTrait()) {
            return [];
        }

        // Only check classes implementing the validation result interface
        $nativeReflection = $classReflection->getNativeReflection();

        if (! $nativeReflection->implementsInterface(DescribableValidationResultInterface::class)) {
            return [];
        }

        $traitNames = $nativeReflection->getTraitNames();

        // AggregatesChildResultsTrait includes ThrowsOnValidationFailureTrait,
        // so either trait satisfies the requirement
        if (\in_array(AggregatesChildResultsTrait::class, $traitNames, true)) {
            return [];
        }

        if (\in_array(ThrowsOnValidationFailureTrait::class, $traitNames, true)) {
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
}

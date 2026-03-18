<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Validation;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Validators must define a validate() method.
 *
 * Any concrete class in a Validators/ namespace ending with *Validator
 * must have a validate() method. This prevents validators from being
 * created that satisfy naming conventions but lack the core contract.
 *
 * @implements Rule<InClassNode>
 */
final class ValidatorMustHaveValidateMethodRule implements Rule
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

        // Fast path: only check classes in Validators/ namespaces
        if (! \str_contains($className, '\\Validators\\')) {
            return [];
        }

        // Only check classes ending with Validator
        if (! \str_ends_with($className, 'Validator')) {
            return [];
        }

        // Skip abstract classes, enums, and interfaces
        if ($classReflection->isAbstract() || $classReflection->isEnum() || $classReflection->isInterface()) {
            return [];
        }

        if ($classReflection->hasNativeMethod('validate')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Validator must define a validate() method returning a DescribableValidationResultInterface.',
            )
                ->identifier('alz.validatorMustHaveValidateMethod')
                ->build(),
        ];
    }
}

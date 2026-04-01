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
        if (! self::isConcreteValidator($node)) {
            return [];
        }

        if ($node->getClassReflection()->hasNativeMethod('validate')) {
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

    private static function isConcreteValidator(InClassNode $node): bool
    {
        $classReflection = $node->getClassReflection();
        $className = $classReflection->getName();

        if (! \str_contains($className, '\\Validators\\') || ! \str_ends_with($className, 'Validator')) {
            return false;
        }

        return ! $classReflection->isAbstract() && ! $classReflection->isEnum() && ! $classReflection->isInterface();
    }
}

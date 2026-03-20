<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Validation;

use App\Domain\Shared\Validation\Contracts\DescribableValidationResultInterface;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Validation results must not override orFail().
 *
 * orFail() must come from ThrowsOnValidationFailureTrait — the single source of truth
 * for converting failed validation into exceptions. Locally declaring orFail() would
 * defeat the purpose of centralised exception behaviour.
 *
 * Uses AST inspection (not reflection) because hasNativeMethod('orFail') returns true
 * even when the method comes from a trait. We need to detect only locally declared methods.
 *
 * @implements Rule<InClassNode>
 */
final class ValidationResultMustNotOverrideOrFailRule implements Rule
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

        // Check AST for locally declared orFail() method
        foreach ($node->getOriginalNode()->stmts as $stmt) {
            if ($stmt instanceof ClassMethod && $stmt->name->toString() === 'orFail') {
                return [
                    RuleErrorBuilder::message(
                        'Validation result must not override orFail() — it must come from '
                        . 'ThrowsOnValidationFailureTrait to ensure consistent exception behaviour.',
                    )
                        ->identifier('alz.validationResultMustNotOverrideOrFail')
                        ->line($stmt->getStartLine())
                        ->build(),
                ];
            }
        }

        return [];
    }
}

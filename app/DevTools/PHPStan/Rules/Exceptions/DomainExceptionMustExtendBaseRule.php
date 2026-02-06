<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Exceptions;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Domain exceptions must extend the project's DomainException base class
 * or LogicException (for programming/deployment errors).
 *
 * This prevents creating exceptions in App\Domain\Exceptions that extend
 * random base classes like \Exception or \RuntimeException directly,
 * which would bypass the structured exception hierarchy.
 *
 * Allowed bases:
 * - App\Domain\Exceptions\DomainException (business rule violations)
 * - LogicException (programming/deployment errors, e.g. InvalidConfigurationException)
 *
 * @implements Rule<InClassNode>
 */
final class DomainExceptionMustExtendBaseRule implements Rule
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

        if (! \str_starts_with($className, 'App\\Domain\\Exceptions\\')) {
            return [];
        }

        // Skip if it IS the base class itself
        if ($className === 'App\\Domain\\Exceptions\\DomainException') {
            return [];
        }

        // Skip interfaces (shouldn't exist here, but defensive)
        if ($classReflection->isInterface()) {
            return [];
        }

        // Use native reflection to check inheritance chain (accepts string class names)
        $nativeReflection = $classReflection->getNativeReflection();

        // Check: extends DomainException (directly or indirectly)
        if ($nativeReflection->isSubclassOf('App\\Domain\\Exceptions\\DomainException')) {
            return [];
        }

        // Check: extends LogicException (for programming/deployment errors)
        if ($nativeReflection->isSubclassOf('LogicException')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Domain exception must extend App\\Domain\\Exceptions\\DomainException '
                . '(for business rule violations) or LogicException (for programming errors).',
            )
                ->identifier('alz.domainExceptionMustExtendBase')
                ->build(),
        ];
    }
}

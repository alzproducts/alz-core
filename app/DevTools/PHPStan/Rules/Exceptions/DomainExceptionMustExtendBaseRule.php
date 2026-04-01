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
        if (! self::isDomainExceptionRequiringCheck($node)) {
            return [];
        }

        if (self::extendsAllowedBase($node)) {
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

    private static function isDomainExceptionRequiringCheck(InClassNode $node): bool
    {
        $classReflection = $node->getClassReflection();
        $className = $classReflection->getName();

        if (! \str_starts_with($className, 'App\\Domain\\Exceptions\\')) {
            return false;
        }

        if ($className === 'App\\Domain\\Exceptions\\DomainException') {
            return false;
        }

        return ! $classReflection->isInterface();
    }

    private static function extendsAllowedBase(InClassNode $node): bool
    {
        $nativeReflection = $node->getClassReflection()->getNativeReflection();

        return $nativeReflection->isSubclassOf('App\\Domain\\Exceptions\\DomainException')
            || $nativeReflection->isSubclassOf('LogicException');
    }
}

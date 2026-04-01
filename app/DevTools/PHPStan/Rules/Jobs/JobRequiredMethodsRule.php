<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Jobs;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Jobs must define backoff and failed() methods.
 *
 * - backoff (property or method): Controls retry delay strategy. Without it,
 *   Laravel retries immediately, which can overwhelm external APIs.
 * - failed(): Only required when the job has no HandleApiExceptions middleware.
 *   Jobs using middleware get centralised failure logging; failed() is only
 *   needed for side effects (e.g. marking a database record as failed).
 *
 * @implements Rule<InClassNode>
 */
final class JobRequiredMethodsRule implements Rule
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

        if (! self::isConcreteJobClass($classReflection)) {
            return [];
        }

        return self::findMissingMethodErrors($classReflection);
    }

    private static function isConcreteJobClass(ClassReflection $classReflection): bool
    {
        return \str_contains($classReflection->getName(), 'App\\Infrastructure\\Jobs\\')
            && ! $classReflection->isAbstract()
            && ! $classReflection->isEnum();
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private static function findMissingMethodErrors(ClassReflection $classReflection): array
    {
        $errors = [];

        if (! $classReflection->hasNativeProperty('backoff') && ! $classReflection->hasNativeMethod('backoff')) {
            $errors[] = self::buildMethodError('Job must define a $backoff property or backoff() method for retry delay strategy.');
        }

        if (! self::hasMiddleware($classReflection) && ! $classReflection->hasNativeMethod('failed')) {
            $errors[] = self::buildMethodError('Job must define a failed() method for cleanup after retries are exhausted.');
        }

        return $errors;
    }

    private static function hasMiddleware(ClassReflection $classReflection): bool
    {
        if ($classReflection->hasNativeMethod('middleware')) {
            return true;
        }

        $parentClass = $classReflection->getParentClass();

        return $parentClass !== null && $parentClass->hasNativeMethod('middleware');
    }

    private static function buildMethodError(string $message): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier('alz.jobRequiredMethod')
            ->build();
    }
}

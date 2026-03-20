<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Jobs;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Jobs must define backoff and failed() methods.
 *
 * - backoff (property or method): Controls retry delay strategy. Without it,
 *   Laravel retries immediately, which can overwhelm external APIs.
 * - failed(): Runs after all retries exhausted. Used for cleanup, alerting,
 *   and preventing infinite retry loops.
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

        if (! $this->isJobClass($classReflection->getName())) {
            return [];
        }

        if ($classReflection->isAbstract() || $classReflection->isEnum()) {
            return [];
        }

        $errors = [];

        // backoff can be a property (array) or method (returns array)
        if (! $classReflection->hasNativeProperty('backoff') && ! $classReflection->hasNativeMethod('backoff')) {
            $errors[] = RuleErrorBuilder::message(
                'Job must define a $backoff property or backoff() method for retry delay strategy.',
            )
                ->identifier('alz.jobRequiredMethod')
                ->build();
        }

        if (! $classReflection->hasNativeMethod('failed')) {
            $errors[] = RuleErrorBuilder::message(
                'Job must define a failed() method for cleanup after retries are exhausted.',
            )
                ->identifier('alz.jobRequiredMethod')
                ->build();
        }

        return $errors;
    }

    private function isJobClass(string $className): bool
    {
        return \str_contains($className, 'App\\Infrastructure\\Jobs\\');
    }
}

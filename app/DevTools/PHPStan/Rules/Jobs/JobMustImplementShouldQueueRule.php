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
 * All jobs must implement ShouldQueue.
 *
 * Synchronous jobs (without ShouldQueue) block the request and bypass
 * retry/backoff/timeout infrastructure. All jobs in this project run async.
 *
 * @implements Rule<InClassNode>
 */
final class JobMustImplementShouldQueueRule implements Rule
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

        if ($classReflection->implementsInterface('Illuminate\Contracts\Queue\ShouldQueue')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Job must implement ShouldQueue. All jobs run asynchronously.',
            )
                ->identifier('alz.jobMustImplementShouldQueue')
                ->build(),
        ];
    }

    private function isJobClass(string $className): bool
    {
        return \str_contains($className, 'App\\Infrastructure\\Jobs\\');
    }
}

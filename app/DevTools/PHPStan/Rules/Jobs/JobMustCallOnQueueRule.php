<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Jobs;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Jobs must call $this->onQueue() in the constructor.
 *
 * Without an explicit queue assignment, jobs land on the 'default' queue
 * silently. Each job should declare its queue tier (high/default/low)
 * for proper priority routing via Horizon.
 *
 * @implements Rule<ClassMethod>
 */
final class JobMustCallOnQueueRule implements Rule
{
    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->name->toString() !== '__construct') {
            return [];
        }

        if (! $scope->isInClass()) {
            return [];
        }

        $className = $scope->getClassReflection()->getName();

        if (! \str_contains($className, 'App\\Presentation\\Jobs\\')) {
            return [];
        }

        // Search constructor statements for $this->onQueue() call
        foreach ($node->stmts ?? [] as $stmt) {
            if ($this->containsOnQueueCall($stmt)) {
                return [];
            }
        }

        return [
            RuleErrorBuilder::message(
                'Job must call $this->onQueue() in the constructor to explicitly assign a queue tier.',
            )
                ->identifier('alz.jobMustCallOnQueue')
                ->build(),
        ];
    }

    private function containsOnQueueCall(Node $stmt): bool
    {
        $expr = $stmt instanceof Expression ? $stmt->expr : $stmt;

        return $expr instanceof MethodCall
            && $expr->name instanceof Identifier
            && $expr->name->toString() === 'onQueue';
    }
}

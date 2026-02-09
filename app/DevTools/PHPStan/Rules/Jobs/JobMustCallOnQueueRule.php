<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Jobs;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
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
 * Uses InClassNode (not ClassMethod) so that jobs without a constructor
 * are also caught — previously they silently defaulted to 'default'.
 *
 * @implements Rule<InClassNode>
 */
final class JobMustCallOnQueueRule implements Rule
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

        // Find __construct in the AST
        $classNode = $node->getOriginalNode();
        $constructor = null;
        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof ClassMethod && $stmt->name->toString() === '__construct') {
                $constructor = $stmt;
                break;
            }
        }

        if ($constructor === null) {
            return [
                RuleErrorBuilder::message(
                    'Job must have a constructor that calls $this->onQueue() to explicitly assign a queue tier.',
                )
                    ->identifier('alz.jobMustCallOnQueue')
                    ->build(),
            ];
        }

        // Check constructor contains onQueue() call
        foreach ($constructor->stmts ?? [] as $stmt) {
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

    private function isJobClass(string $className): bool
    {
        return \str_contains($className, 'App\\Application\\Jobs\\');
    }

    private function containsOnQueueCall(Node $stmt): bool
    {
        $expr = $stmt instanceof Expression ? $stmt->expr : $stmt;

        return $expr instanceof MethodCall
            && $expr->name instanceof Identifier
            && $expr->name->toString() === 'onQueue';
    }
}

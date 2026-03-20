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
use PHPStan\Reflection\ClassReflection;
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

        $constructor = $this->findConstructor($node);

        if ($constructor === null) {
            if ($this->parentProvidesOnQueue($classReflection)) {
                return [];
            }

            return [
                RuleErrorBuilder::message(
                    'Job must have a constructor that calls $this->onQueue() to explicitly assign a queue tier.',
                )
                    ->identifier('alz.jobMustCallOnQueue')
                    ->build(),
            ];
        }

        if ($this->constructorCallsOnQueue($constructor)) {
            return [];
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
        return \str_contains($className, 'App\\Infrastructure\\Jobs\\');
    }

    private function findConstructor(InClassNode $node): ?ClassMethod
    {
        foreach ($node->getOriginalNode()->stmts as $stmt) {
            if ($stmt instanceof ClassMethod && $stmt->name->toString() === '__construct') {
                return $stmt;
            }
        }

        return null;
    }

    private function constructorCallsOnQueue(ClassMethod $constructor): bool
    {
        foreach ($constructor->stmts ?? [] as $stmt) {
            if ($this->containsOnQueueCall($stmt)) {
                return true;
            }
        }

        return false;
    }

    private function parentProvidesOnQueue(ClassReflection $classReflection): bool
    {
        $parentClass = $classReflection->getParentClass();

        return $parentClass !== null
            && $parentClass->isAbstract()
            && \str_contains($parentClass->getName(), 'App\\Infrastructure\\Jobs\\');
    }

    private function containsOnQueueCall(Node $stmt): bool
    {
        $expr = $stmt instanceof Expression ? $stmt->expr : $stmt;

        return $expr instanceof MethodCall
            && $expr->name instanceof Identifier
            && $expr->name->toString() === 'onQueue';
    }
}

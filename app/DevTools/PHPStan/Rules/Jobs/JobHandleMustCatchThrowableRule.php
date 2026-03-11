<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Jobs;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\TryCatch;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Job handle() methods must have a catch(\Throwable) block.
 *
 * Every job must catch unexpected exceptions to:
 * 1. Log critical context (exception class, message, job name)
 * 2. Call $this->fail() to prevent silent retry loops
 * 3. Rethrow for Laravel's failure tracking
 *
 * Without this, unexpected exceptions cause retries with no logging,
 * making production debugging impossible.
 *
 * @implements Rule<ClassMethod>
 */
final class JobHandleMustCatchThrowableRule implements Rule
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
        if ($node->name->toString() !== 'handle') {
            return [];
        }

        if (! $scope->isInClass()) {
            return [];
        }

        $className = $scope->getClassReflection()->getName();

        if (! \str_contains($className, 'App\\Application\\Jobs\\')) {
            return [];
        }

        $catchesThrowable = \array_any(
            $node->stmts ?? [],
            static fn(Node\Stmt $stmt): bool => $stmt instanceof TryCatch && self::tryCatchHasThrowable($stmt),
        );

        if ($catchesThrowable) {
            return [];
        }

        // Abstract parent in the same namespace may provide the catch(Throwable) via a shared method
        $classReflection = $scope->getClassReflection();
        $parentClass = $classReflection->getParentClass();

        if ($parentClass !== null
            && $parentClass->isAbstract()
            && \str_contains($parentClass->getName(), 'App\\Application\\Jobs\\')
        ) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Job handle() method must contain a catch(\\Throwable) block '
                . 'to log unexpected exceptions and call $this->fail().',
            )
                ->identifier('alz.jobHandleMustCatchThrowable')
                ->build(),
        ];
    }

    private static function tryCatchHasThrowable(TryCatch $tryCatch): bool
    {
        foreach ($tryCatch->catches as $catch) {
            foreach ($catch->types as $type) {
                if ($type->toString() === 'Throwable') {
                    return true;
                }
            }
        }

        return false;
    }
}

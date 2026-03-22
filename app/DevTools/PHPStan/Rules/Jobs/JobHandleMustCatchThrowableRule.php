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
 * Job handle() methods must have error handling for API exceptions.
 *
 * Accepted patterns:
 * 1. Inline catch(\Throwable) in handle()
 * 2. Abstract parent class providing error handling via shared method
 * 3. HandleApiExceptions middleware (declared via middleware() method)
 *
 * Jobs using middleware delegate TransientApiFailure release logic and
 * PermanentApiFailure fail-fast to the middleware. Unexpected exceptions
 * bubble to the Worker naturally and are logged/captured via Queue::failing.
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

        if (! \str_contains($className, 'App\\Infrastructure\\Jobs\\')
            || \str_contains($className, 'App\\Infrastructure\\Jobs\\Middleware\\')
        ) {
            return [];
        }

        $catchesThrowable = \array_any(
            $node->stmts ?? [],
            static fn(Node\Stmt $stmt): bool => $stmt instanceof TryCatch && self::tryCatchHasThrowable($stmt),
        );

        if ($catchesThrowable) {
            return [];
        }

        $classReflection = $scope->getClassReflection();

        // Abstract parent in the same namespace may provide the catch(Throwable) via a shared method
        $parentClass = $classReflection->getParentClass();

        if ($parentClass !== null
            && $parentClass->isAbstract()
            && \str_contains($parentClass->getName(), 'App\\Infrastructure\\Jobs\\')
        ) {
            return [];
        }

        // Jobs with a middleware() method delegate error handling to HandleApiExceptions middleware
        if ($classReflection->hasMethod('middleware') || ($parentClass !== null && $parentClass->hasMethod('middleware'))) {
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

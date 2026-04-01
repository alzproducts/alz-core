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
        if (! self::isJobHandleMethod($node, $scope)) {
            return [];
        }

        if (self::hasErrorHandling($node, $scope)) {
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

    private static function isJobHandleMethod(ClassMethod $node, Scope $scope): bool
    {
        if ($node->name->toString() !== 'handle' || ! $scope->isInClass()) {
            return false;
        }

        $className = $scope->getClassReflection()->getName();

        return \str_contains($className, 'App\\Infrastructure\\Jobs\\')
            && ! \str_contains($className, 'App\\Infrastructure\\Jobs\\Middleware\\');
    }

    private static function hasErrorHandling(ClassMethod $node, Scope $scope): bool
    {
        $catchesThrowable = \array_any(
            $node->stmts ?? [],
            static fn(Node\Stmt $stmt): bool => $stmt instanceof TryCatch && self::tryCatchHasThrowable($stmt),
        );

        if ($catchesThrowable) {
            return true;
        }

        return self::hasParentOrMiddlewareHandling($scope);
    }

    private static function hasParentOrMiddlewareHandling(Scope $scope): bool
    {
        $classReflection = $scope->getClassReflection();

        if ($classReflection === null) {
            return false;
        }

        $parentClass = $classReflection->getParentClass();

        if ($parentClass !== null
            && $parentClass->isAbstract()
            && \str_contains($parentClass->getName(), 'App\\Infrastructure\\Jobs\\')
        ) {
            return true;
        }

        return $classReflection->hasMethod('middleware')
            || ($parentClass !== null && $parentClass->hasMethod('middleware'));
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

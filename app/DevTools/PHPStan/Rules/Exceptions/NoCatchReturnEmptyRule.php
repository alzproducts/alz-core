<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Exceptions;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\TryCatch;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Catch blocks must not return empty values to hide failures.
 *
 * Returning [], null, '', or collect() in a catch block silently swallows
 * exceptions, making production debugging impossible. Exceptions should
 * be rethrown or translated to Domain exceptions.
 *
 * @implements Rule<TryCatch>
 */
final class NoCatchReturnEmptyRule implements Rule
{
    public function getNodeType(): string
    {
        return TryCatch::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! \str_starts_with($scope->getNamespace() ?? '', 'App\\')) {
            return [];
        }

        $errors = [];

        foreach ($node->catches as $catch) {
            foreach ($catch->stmts as $stmt) {
                if ($stmt instanceof Return_ && self::isEmptyReturn($stmt->expr)) {
                    $errors[] = RuleErrorBuilder::message(
                        'Catch block returns empty value ([], null, \'\', collect()) — '
                        . 'exceptions should be thrown or translated, not silently swallowed.',
                    )
                        ->identifier('alz.noCatchReturnEmpty')
                        ->line($stmt->getStartLine())
                        ->build();
                }
            }
        }

        return $errors;
    }

    private static function isEmptyReturn(?Expr $expr): bool
    {
        if ($expr === null) {
            return false; // bare `return;` in void methods is fine
        }

        if ($expr instanceof Array_) {
            return $expr->items === [];
        }

        if ($expr instanceof ConstFetch) {
            return $expr->name->toLowerString() === 'null';
        }

        if ($expr instanceof String_) {
            return $expr->value === '';
        }

        return self::isEmptyCollect($expr);
    }

    private static function isEmptyCollect(Expr $expr): bool
    {
        return $expr instanceof FuncCall
            && $expr->name instanceof Name
            && $expr->name->toString() === 'collect'
            && $expr->args === [];
    }
}

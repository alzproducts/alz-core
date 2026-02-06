<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Architecture;

use PhpParser\Node;
use PhpParser\Node\Stmt\TryCatch;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Controllers should not contain try-catch blocks.
 *
 * Exception-to-HTTP mapping belongs in the global exception handler
 * (bootstrap/app.php), not in individual controllers. This keeps
 * controllers thin and exception handling consistent.
 *
 * @implements Rule<TryCatch>
 */
final class NoTryCatchInControllerRule implements Rule
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
        if (! $scope->isInClass()) {
            return [];
        }

        $className = $scope->getClassReflection()->getName();

        if (! \str_ends_with($className, 'Controller')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Try-catch blocks are not allowed in Controllers. '
                . 'Use the global exception handler in bootstrap/app.php instead.',
            )
                ->identifier('alz.noTryCatchInController')
                ->build(),
        ];
    }
}

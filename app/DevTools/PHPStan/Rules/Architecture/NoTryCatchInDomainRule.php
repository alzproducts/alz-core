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
 * Domain layer must only throw exceptions, never catch them.
 *
 * Catching exceptions in Domain indicates infrastructure concerns leaking in.
 * Domain code should declare what CAN go wrong (@throws), and let outer layers
 * decide how to handle it.
 *
 * @implements Rule<TryCatch>
 */
final class NoTryCatchInDomainRule implements Rule
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
        $namespace = $scope->getNamespace();

        if ($namespace === null || ! \str_starts_with($namespace, 'App\\Domain')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Try-catch blocks are not allowed in the Domain layer. '
                . 'Domain should only throw exceptions, never catch them.',
            )
                ->identifier('alz.noTryCatchInDomain')
                ->build(),
        ];
    }
}

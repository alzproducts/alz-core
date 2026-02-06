<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Ban assertEquals() — use assertSame() for strict type comparison.
 *
 * assertEquals() uses loose comparison (==), allowing type juggling.
 * assertSame() uses strict comparison (===).
 *
 * Note: tests/ is excluded from PHPStan analysis. This rule catches
 * any assertEquals() usage in production code (e.g. Assert::assertEquals()).
 *
 * @implements Rule<StaticCall>
 */
final class NoAssertEqualsRule implements Rule
{
    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->class instanceof Name) {
            return [];
        }

        // Check method name is assertEquals
        if (! $node->name instanceof Identifier || $node->name->toString() !== 'assertEquals') {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Use assertSame() for strict type comparison — '
                . 'assertEquals() uses loose == comparison.',
            )
                ->identifier('alz.noAssertEquals')
                ->build(),
        ];
    }
}

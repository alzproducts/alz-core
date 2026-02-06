<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Infrastructure;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Row classes from Queries/ must not be imported outside their Queries namespace.
 *
 * Row DTOs are internal implementation details of their Query classes, co-located
 * in the same file. Importing them outside Queries indicates a layer boundary
 * violation — use the Query's public API instead.
 *
 * @implements Rule<InClassNode>
 */
final class RowClassNotImportedOutsideQueriesRule implements Rule
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
        $namespace = $scope->getNamespace();

        // Only check files OUTSIDE Queries namespaces
        if ($namespace === null || \str_contains($namespace, '\\Queries')) {
            return [];
        }

        // Read file to find use statements importing Row classes from Queries
        $content = \file_get_contents($scope->getFile());

        if ($content === false) {
            return [];
        }

        \preg_match_all(
            '/^use\s+(App\\\\Infrastructure\\\\[^;]*\\\\Queries\\\\(\w*Row))\s*;/m',
            $content,
            $matches,
            PREG_SET_ORDER,
        );

        if (\count($matches) === 0) {
            return [];
        }

        $errors = [];

        foreach ($matches as $match) {
            $errors[] = RuleErrorBuilder::message(
                \sprintf(
                    'Row class %s must not be imported outside its Queries namespace. '
                    . 'Row DTOs are internal implementation details — use the Query\'s public API instead.',
                    $match[2],
                ),
            )
                ->identifier('alz.rowClassNotImportedOutsideQueries')
                ->build();
        }

        return $errors;
    }
}

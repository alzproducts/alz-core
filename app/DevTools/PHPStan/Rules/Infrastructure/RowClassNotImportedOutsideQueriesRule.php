<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Infrastructure;

use PhpParser\Node;
use PhpParser\Node\Stmt\Use_;
use PHPStan\Analyser\Scope;
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
 * @implements Rule<Use_>
 */
final class RowClassNotImportedOutsideQueriesRule implements Rule
{
    public function getNodeType(): string
    {
        return Use_::class;
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

        $errors = [];

        foreach ($node->uses as $use) {
            $name = $use->name->toString();

            if (! \str_starts_with($name, 'App\\Infrastructure\\')
                || ! \str_contains($name, '\\Queries\\')
            ) {
                continue;
            }

            $parts = \explode('\\', $name);
            $className = $parts[\array_key_last($parts)];

            if (! \str_ends_with($className, 'Row')) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(
                \sprintf(
                    'Row class %s must not be imported outside its Queries namespace. '
                    . 'Row DTOs are internal implementation details — use the Query\'s public API instead.',
                    $className,
                ),
            )
                ->identifier('alz.rowClassNotImportedOutsideQueries')
                ->build();
        }

        return $errors;
    }
}

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

        if ($namespace === null || \str_contains($namespace, '\\Queries')) {
            return [];
        }

        return self::findRowImportViolations($node);
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private static function findRowImportViolations(Use_ $node): array
    {
        $errors = [];

        foreach ($node->uses as $use) {
            $name = $use->name->toString();

            if (self::isQueriesRowClass($name)) {
                $parts = \explode('\\', $name);
                $errors[] = self::buildRowImportError($parts[\array_key_last($parts)]);
            }
        }

        return $errors;
    }

    private static function isQueriesRowClass(string $name): bool
    {
        return \str_starts_with($name, 'App\\Infrastructure\\')
            && \str_contains($name, '\\Queries\\')
            && \str_ends_with($name, 'Row');
    }

    private static function buildRowImportError(string $className): IdentifierRuleError
    {
        return RuleErrorBuilder::message(
            \sprintf(
                'Row class %s must not be imported outside its Queries namespace. '
                . 'Row DTOs are internal implementation details — use the Query\'s public API instead.',
                $className,
            ),
        )
            ->identifier('alz.rowClassNotImportedOutsideQueries')
            ->build();
    }
}

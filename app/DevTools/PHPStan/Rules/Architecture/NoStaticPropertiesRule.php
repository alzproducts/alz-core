<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Architecture;

use PhpParser\Node;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Static properties are forbidden in application code.
 *
 * Laravel Octane runs as a long-lived process — static properties persist
 * across requests, causing data leaks between users and stale state bugs.
 * Use constructor injection or request-scoped singletons instead.
 *
 * @implements Rule<Property>
 */
final class NoStaticPropertiesRule implements Rule
{
    public function getNodeType(): string
    {
        return Property::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->isStatic()) {
            return [];
        }

        $namespace = $scope->getNamespace();

        if ($namespace === null || ! \str_starts_with($namespace, 'App\\')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Static properties are forbidden — Octane persists them across requests. '
                . 'Use constructor injection or request-scoped singletons instead.',
            )
                ->identifier('alz.noStaticProperties')
                ->build(),
        ];
    }
}

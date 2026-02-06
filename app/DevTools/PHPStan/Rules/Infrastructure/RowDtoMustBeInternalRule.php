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
 * Row DTOs in Query files must be marked @internal.
 *
 * Row classes (e.g. StockItemBySkuRow) are co-located with their Query classes
 * and are internal implementation details. The @internal tag signals this intent
 * and prevents IDE auto-suggestions for external use.
 *
 * @implements Rule<InClassNode>
 */
final class RowDtoMustBeInternalRule implements Rule
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
        $classReflection = $node->getClassReflection();
        $className = $classReflection->getName();

        // Only check classes ending in 'Row' within Queries namespaces
        if (\preg_match('/^App\\\\Infrastructure\\\\[^\\\\]+\\\\Queries\\\\.*Row$/', $className) !== 1) {
            return [];
        }

        // Check the class docblock for @internal
        $docComment = $node->getOriginalNode()->getDocComment();

        if ($docComment !== null && \str_contains($docComment->getText(), '@internal')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Row DTO must be marked @internal — it is an implementation detail of its Query class.',
            )
                ->identifier('alz.rowDtoMustBeInternal')
                ->build(),
        ];
    }
}

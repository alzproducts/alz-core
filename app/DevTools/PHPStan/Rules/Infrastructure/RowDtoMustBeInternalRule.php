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
        if (! self::isQueriesRowDto($node)) {
            return [];
        }

        if (self::hasInternalAnnotation($node)) {
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

    private static function isQueriesRowDto(InClassNode $node): bool
    {
        $className = $node->getClassReflection()->getName();

        return \preg_match('/^App\\\\Infrastructure\\\\[^\\\\]+\\\\Queries\\\\.*Row$/', $className) === 1;
    }

    private static function hasInternalAnnotation(InClassNode $node): bool
    {
        $docComment = $node->getOriginalNode()->getDocComment();

        return $docComment !== null && \str_contains($docComment->getText(), '@internal');
    }
}

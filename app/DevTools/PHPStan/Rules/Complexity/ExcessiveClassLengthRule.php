<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Complexity;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Classes must not exceed 250 lines.
 *
 * Counts all lines in the class body (including blanks and comments), matching
 * PHPMD and ESLint conventions. Large classes are a signal of too many
 * responsibilities and should be decomposed.
 *
 * @implements Rule<Class_>
 */
final class ExcessiveClassLengthRule implements Rule
{
    private const int THRESHOLD = 250;

    public function getNodeType(): string
    {
        return Class_::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! self::isInAppNamespace($scope) || $node->name === null) {
            return [];
        }

        $length = self::measureLength($node);

        if ($length === null || $length <= self::THRESHOLD) {
            return [];
        }

        return [self::buildError($node->name->name, $length)];
    }

    private static function isInAppNamespace(Scope $scope): bool
    {
        $namespace = $scope->getNamespace();

        return $namespace !== null && \str_starts_with($namespace, 'App\\');
    }

    private static function measureLength(Class_ $node): ?int
    {
        $startLine = $node->getStartLine();
        $endLine = $node->getEndLine();

        if ($startLine === -1 || $endLine === -1) {
            return null;
        }

        return $endLine - $startLine;
    }

    private static function buildError(string $className, int $length): IdentifierRuleError
    {
        return RuleErrorBuilder::message(
            \sprintf(
                'Class %s is %d lines long — exceeds the %d-line limit. Consider decomposing into smaller, focused classes.',
                $className,
                $length,
                self::THRESHOLD,
            ),
        )
            ->identifier('alz.excessiveClassLength')
            ->tip('Check whether this class has multiple responsibilities. Look for groups of methods that operate on distinct subsets of dependencies — these are natural split points.')
            ->build();
    }
}

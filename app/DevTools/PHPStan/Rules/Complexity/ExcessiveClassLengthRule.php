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
 * Classes must not exceed a namespace-specific line limit.
 *
 * Counts all lines in the class body (including blanks and comments), matching
 * PHPMD and ESLint conventions. Large classes are a signal of too many
 * responsibilities and should be decomposed.
 *
 * Infrastructure API clients, repositories, and transport classes get a higher
 * threshold (500 lines) — they hold a cluster of cohesive operations against a
 * single external system or persistence store, and splitting them typically
 * fragments rather than clarifies.
 *
 * @implements Rule<Class_>
 */
final class ExcessiveClassLengthRule implements Rule
{
    private const int DEFAULT_THRESHOLD = 250;
    private const int INFRASTRUCTURE_CLUSTER_THRESHOLD = 500;

    /**
     * Class-name suffixes whose Infrastructure classes cluster cohesive
     * operations against one external system and get the higher threshold.
     * Detected by suffix (not namespace) because transport classes often
     * sit at the service-integration root (e.g. ShopwiredHttpTransport
     * in App\Infrastructure\Shopwired), not a dedicated subnamespace.
     */
    private const array INFRASTRUCTURE_CLUSTER_SUFFIXES = [
        'Client',
        'Repository',
        'Transport',
    ];

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

        $className = $node->name->name;
        $threshold = self::resolveThreshold($className, $scope);
        $length = self::measureLength($node);

        if ($length === null || $length <= $threshold) {
            return [];
        }

        return [self::buildError($className, $length, $threshold)];
    }

    private static function resolveThreshold(string $className, Scope $scope): int
    {
        return self::isInfrastructureCluster($className, $scope)
            ? self::INFRASTRUCTURE_CLUSTER_THRESHOLD
            : self::DEFAULT_THRESHOLD;
    }

    private static function isInfrastructureCluster(string $className, Scope $scope): bool
    {
        $namespace = $scope->getNamespace();

        if ($namespace === null || ! \str_starts_with($namespace, 'App\\Infrastructure\\')) {
            return false;
        }

        foreach (self::INFRASTRUCTURE_CLUSTER_SUFFIXES as $suffix) {
            if (\str_ends_with($className, $suffix)) {
                return true;
            }
        }

        return false;
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

    private static function buildError(string $className, int $length, int $threshold): IdentifierRuleError
    {
        return RuleErrorBuilder::message(
            \sprintf(
                'Class %s is %d lines long — exceeds the %d-line limit. Consider decomposing into smaller, focused classes.',
                $className,
                $length,
                $threshold,
            ),
        )
            ->identifier('alz.excessiveClassLength')
            ->tip('Check whether this class has multiple responsibilities. Look for groups of methods that operate on distinct subsets of dependencies — these are natural split points.')
            ->build();
    }
}

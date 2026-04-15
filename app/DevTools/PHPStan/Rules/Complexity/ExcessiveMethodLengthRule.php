<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Complexity;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Methods must not exceed a layer-specific line limit.
 *
 * Counts all lines between opening and closing braces (including blanks and
 * comments), matching PHPMD and ESLint conventions. Long methods are a signal
 * that a method is doing too much and should be broken into smaller, focused units.
 *
 * Infrastructure repositories get a higher threshold (30 lines) because their
 * methods are inherently denser — gateway wrapping, query building, and result
 * mapping are a single coherent operation that doesn't compress well.
 *
 * @implements Rule<ClassMethod>
 */
final class ExcessiveMethodLengthRule implements Rule
{
    private const int DEFAULT_THRESHOLD = 20;
    private const int REPOSITORY_THRESHOLD = 30;

    /**
     * Methods that are structural listings (field mappings, service arrays)
     * whose length grows linearly with entry count. They have no extractable
     * logic and are exempt from the method-length rule.
     */
    private const array EXCLUDED_METHODS = [
        'toDomain',
        'fromModel',
        'toModelAttributes',
        'toSdk',
        'fromDomain',
        'provides',
    ];

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! self::isInAppNamespace($scope) || self::isExcludedMethod($node) || self::isDtoConstructor($node, $scope)) {
            return [];
        }

        $threshold = self::resolveThreshold($scope);
        $length = self::measureLength($node);

        if ($length === null || $length <= $threshold) {
            return [];
        }

        return [self::buildError($node->name->name, $length, $threshold)];
    }

    private static function resolveThreshold(Scope $scope): int
    {
        return self::isRepository($scope) ? self::REPOSITORY_THRESHOLD : self::DEFAULT_THRESHOLD;
    }

    private static function isRepository(Scope $scope): bool
    {
        $namespace = $scope->getNamespace();

        return $namespace !== null
            && \str_starts_with($namespace, 'App\\Infrastructure\\')
            && \str_contains($namespace, '\\Repositories');
    }

    private static function isInAppNamespace(Scope $scope): bool
    {
        $namespace = $scope->getNamespace();

        return $namespace !== null && \str_starts_with($namespace, 'App\\');
    }

    private static function isExcludedMethod(ClassMethod $node): bool
    {
        return \in_array($node->name->name, self::EXCLUDED_METHODS, true);
    }

    /**
     * DTO constructors are structural field listings (validation attributes +
     * promoted properties) whose length grows linearly with field count.
     * They have no extractable logic — same rationale as EXCLUDED_METHODS.
     */
    private static function isDtoConstructor(ClassMethod $node, Scope $scope): bool
    {
        if ($node->name->name !== '__construct') {
            return false;
        }

        $namespace = $scope->getNamespace();

        return $namespace !== null && \str_contains($namespace, '\\DTOs');
    }

    private static function measureLength(ClassMethod $node): ?int
    {
        $startLine = $node->getStartLine();
        $endLine = $node->getEndLine();

        if ($startLine === -1 || $endLine === -1) {
            return null;
        }

        return $endLine - $startLine;
    }

    private static function buildError(string $methodName, int $length, int $threshold): IdentifierRuleError
    {
        return RuleErrorBuilder::message(
            \sprintf(
                'Method %s() is %d lines long — exceeds the %d-line limit. Break it into smaller, focused methods.',
                $methodName,
                $length,
                $threshold,
            ),
        )
            ->identifier('alz.excessiveMethodLength')
            ->tip('Extract logical sections into well-named private methods, each with a single responsibility. Do not split arbitrarily at a line count — each extracted method should represent a coherent operation.')
            ->build();
    }
}

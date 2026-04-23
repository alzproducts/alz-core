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
 * Infrastructure "cluster" classes (Client/Repository/Transport suffixes) get a
 * higher threshold (30 lines) because their methods are inherently denser —
 * gateway wrapping, query building, and result mapping (repos) or build-request
 * → transport call → validate-response → return-typed-result (clients) are each
 * a single coherent operation that doesn't compress well. Same 3 suffixes that
 * ExcessiveClassLengthRule treats as the Infrastructure cluster.
 *
 * Non-DTO constructors get a 40-line threshold because pure property promotion
 * grows linearly with field count and has no extractable logic — the rule still
 * fires on genuinely overweight constructors that mix assignment with transform
 * logic (e.g. ProductView).
 *
 * @implements Rule<ClassMethod>
 */
final class ExcessiveMethodLengthRule implements Rule
{
    private const int DEFAULT_THRESHOLD = 20;
    private const int INFRASTRUCTURE_CLUSTER_THRESHOLD = 30;
    private const int CONSTRUCTOR_THRESHOLD = 40;

    /** Class-name suffixes that identify the Infrastructure cluster. */
    private const array INFRASTRUCTURE_CLUSTER_SUFFIXES = ['Client', 'Repository', 'Transport'];

    /**
     * Methods that are structural listings (field mappings, service arrays,
     * Eloquent cast declarations, domain→model attribute conversion) whose
     * length grows linearly with entry count. They have no extractable logic
     * and are exempt from the method-length rule.
     */
    private const array EXCLUDED_METHODS = [
        'toDomain',
        'toViewDomain',
        'fromModel',
        'toModelAttributes',
        'toSdk',
        'fromDomain',
        'provides',
        'casts',
        'attributesFromDomain',
        'toArray',
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

        $threshold = self::resolveThreshold($node, $scope);
        $length = self::measureLength($node);

        if ($length === null || $length <= $threshold) {
            return [];
        }

        return [self::buildError($node->name->name, $length, $threshold)];
    }

    private static function resolveThreshold(ClassMethod $node, Scope $scope): int
    {
        if ($node->name->name === '__construct') {
            return self::CONSTRUCTOR_THRESHOLD;
        }

        return self::isInfrastructureCluster($scope) ? self::INFRASTRUCTURE_CLUSTER_THRESHOLD : self::DEFAULT_THRESHOLD;
    }

    /**
     * Infrastructure cluster = class under App\Infrastructure\ that either
     * (a) lives in a \Repositories\ namespace (SQL builders, query objects, etc.)
     * or (b) has a class name ending in Client, Repository, or Transport.
     *
     * The namespace check preserves the 30-line threshold for non-Repository-suffix
     * helpers inside the Repositories directory (e.g. RelatedProductsAlgorithmSql).
     * The suffix check matches ExcessiveClassLengthRule's Infrastructure cluster.
     */
    private static function isInfrastructureCluster(Scope $scope): bool
    {
        $namespace = $scope->getNamespace();

        if ($namespace === null || !\str_starts_with($namespace, 'App\\Infrastructure\\')) {
            return false;
        }

        if (\str_contains($namespace, '\\Repositories')) {
            return true;
        }

        $name = $scope->getClassReflection()?->getName() ?? '';

        return \array_any(
            self::INFRASTRUCTURE_CLUSTER_SUFFIXES,
            static fn(string $suffix): bool => \str_ends_with($name, $suffix),
        );
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

        // Attributes count toward the node's start line but are metadata, not body.
        // Skip past the last attribute group so length reflects the method body only.
        foreach ($node->attrGroups as $attrGroup) {
            $attrEndLine = $attrGroup->getEndLine();
            if ($attrEndLine !== -1 && $attrEndLine + 1 > $startLine) {
                $startLine = $attrEndLine + 1;
            }
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

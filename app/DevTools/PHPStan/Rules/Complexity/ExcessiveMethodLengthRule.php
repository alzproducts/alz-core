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
 * Methods must not exceed 20 lines.
 *
 * Counts all lines between opening and closing braces (including blanks and
 * comments), matching PHPMD and ESLint conventions. Long methods are a signal
 * that a method is doing too much and should be broken into smaller, focused units.
 *
 * @implements Rule<ClassMethod>
 */
final class ExcessiveMethodLengthRule implements Rule
{
    private const int THRESHOLD = 20;

    /**
     * Mapper methods are structural field-mapping whose length grows linearly
     * with field count. They have no extractable logic and are exempt from the
     * method-length rule.
     */
    private const array EXCLUDED_METHODS = [
        'toDomain',
        'fromModel',
        'toModelAttributes',
        'toSdk',
        'fromDomain',
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
        $namespace = $scope->getNamespace();

        if ($namespace === null || ! \str_starts_with($namespace, 'App\\')) {
            return [];
        }

        if (\in_array($node->name->name, self::EXCLUDED_METHODS, true)) {
            return [];
        }

        $startLine = $node->getStartLine();
        $endLine = $node->getEndLine();

        if ($startLine === -1 || $endLine === -1) {
            return [];
        }

        $length = $endLine - $startLine;

        if ($length <= self::THRESHOLD) {
            return [];
        }

        $methodName = $node->name->name;

        return [
            RuleErrorBuilder::message(
                \sprintf(
                    'Method %s() is %d lines long — exceeds the %d-line limit. Break it into smaller, focused methods.',
                    $methodName,
                    $length,
                    self::THRESHOLD,
                ),
            )
                ->identifier('alz.excessiveMethodLength')
                ->tip('Extract logical sections into well-named private methods, each with a single responsibility. Do not split arbitrarily at a line count — each extracted method should represent a coherent operation.')
                ->build(),
        ];
    }
}

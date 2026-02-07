<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Infrastructure;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Infrastructure @throws must not reference vendor/SDK exceptions.
 *
 * Infrastructure catches SDK exceptions and translates them to Domain exceptions.
 * The @throws docblock should only declare Domain exceptions (or base PHP exceptions
 * for defensive programming errors). Vendor exceptions appearing in @throws indicate
 * the translation layer is missing.
 *
 * Allowed in @throws:
 * - App\Domain\* (domain exceptions)
 * - App\Infrastructure\* (internal infrastructure exceptions)
 * - Base PHP exceptions (RuntimeException, LogicException, etc.)
 * - Throwable
 *
 * Flagged:
 * - Any vendor/SDK exception (e.g. Google\Ads\*, HelpScout\Api\*, etc.)
 *
 * @implements Rule<ClassMethod>
 */
final class NoSdkExceptionsInThrowsRule implements Rule
{
    /** @var array<string, array<string, string>> Per-file use-map cache to avoid duplicate file reads */
    private static array $useMapCache = [];

    /** @var array<string, true> Base PHP exception classes allowed in @throws */
    private const array ALLOWED_PHP_EXCEPTIONS = [
        'Throwable' => true,
        'Exception' => true,
        'Error' => true,
        'RuntimeException' => true,
        'LogicException' => true,
        'InvalidArgumentException' => true,
        'BadMethodCallException' => true,
        'DomainException' => true,
        'RangeException' => true,
        'OverflowException' => true,
        'UnderflowException' => true,
        'UnexpectedValueException' => true,
        'LengthException' => true,
        'OutOfRangeException' => true,
        'OutOfBoundsException' => true,
        'TypeError' => true,
        'ValueError' => true,
        'JsonException' => true,
        'DateMalformedStringException' => true,
        'SoapFault' => true,
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

        if ($namespace === null || ! \str_starts_with($namespace, 'App\\Infrastructure')) {
            return [];
        }

        // Skip private methods — their @throws are internal documentation
        // for the checked exception system, not the public API contract.
        if ($node->isPrivate()) {
            return [];
        }

        $docComment = $node->getDocComment();
        if ($docComment === null) {
            return [];
        }

        \preg_match_all('/@throws\s+([\w\\\\]+)/', $docComment->getText(), $matches);
        if (\count($matches[1]) === 0) {
            return [];
        }

        $useMap = self::buildUseMap($scope->getFile());
        $errors = [];

        foreach ($matches[1] as $throwsClass) {
            $fqcn = self::resolveClassName($throwsClass, $namespace, $useMap);

            if (! self::isAllowedExceptionClass($fqcn)) {
                $errors[] = RuleErrorBuilder::message(
                    \sprintf(
                        '@throws %s references a vendor/SDK exception. '
                        . 'Infrastructure must translate SDK exceptions to Domain exceptions.',
                        $throwsClass,
                    ),
                )
                    ->identifier('alz.noSdkExceptionsInThrows')
                    ->build();
            }
        }

        return $errors;
    }

    private static function isAllowedExceptionClass(string $fqcn): bool
    {
        // App\Domain\* — domain exceptions
        if (\str_starts_with($fqcn, 'App\\Domain\\')) {
            return true;
        }

        // App\Infrastructure\* — internal infrastructure exceptions
        if (\str_starts_with($fqcn, 'App\\Infrastructure\\')) {
            return true;
        }

        // Base PHP exceptions (no namespace)
        return ! \str_contains($fqcn, '\\') && isset(self::ALLOWED_PHP_EXCEPTIONS[$fqcn]);
    }

    /**
     * Build a map of short class names → FQCNs from use statements in the file.
     *
     * @return array<string, string>
     */
    private static function buildUseMap(string $filePath): array
    {
        if (isset(self::$useMapCache[$filePath])) {
            return self::$useMapCache[$filePath];
        }

        $content = \file_get_contents($filePath);
        if ($content === false) {
            return [];
        }

        \preg_match_all('/^use\s+([\w\\\\]+?)(?:\s+as\s+(\w+))?\s*;/m', $content, $matches, PREG_SET_ORDER);

        $map = [];
        foreach ($matches as $match) {
            $fqcn = $match[1];
            $parts = \explode('\\', $fqcn);
            $alias = $match[2] ?? $parts[\array_key_last($parts)];
            $map[$alias] = $fqcn;
        }

        self::$useMapCache[$filePath] = $map;

        return $map;
    }

    /**
     * Resolve a class name from @throws to its FQCN using the file's use map.
     *
     * @param array<string, string> $useMap
     */
    private static function resolveClassName(string $name, string $namespace, array $useMap): string
    {
        // Already FQCN (starts with backslash)
        $trimmed = \mb_ltrim($name, '\\');
        if ($name !== $trimmed) {
            return $trimmed;
        }

        // Check use map for the first segment
        $parts = \explode('\\', $name);
        $firstPart = $parts[0];

        if (isset($useMap[$firstPart])) {
            if (\count($parts) === 1) {
                return $useMap[$firstPart];
            }

            \array_shift($parts);

            return $useMap[$firstPart] . '\\' . \implode('\\', $parts);
        }

        // Unresolved — relative to current namespace
        return $namespace . '\\' . $name;
    }
}

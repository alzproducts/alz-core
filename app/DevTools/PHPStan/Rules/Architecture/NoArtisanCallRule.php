<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Ban Artisan::call() and Artisan::queue() in application code.
 *
 * Programmatic artisan calls bypass queue retry, logging, and error handling.
 * Use dedicated jobs or scheduled commands instead.
 * Allowed in: Console commands, Providers.
 *
 * @implements Rule<StaticCall>
 */
final class NoArtisanCallRule implements Rule
{
    private const string FACADE_FQCN = 'Illuminate\\Support\\Facades\\Artisan';

    /** @var array<string, true> Namespaces where Artisan::call() is allowed */
    private const array ALLOWED_NAMESPACES = [
        'App\\Presentation\\Console' => true,
        'App\\Providers' => true,
    ];

    /** @var array<string, true> Methods that are banned */
    private const array BANNED_METHODS = [
        'call' => true,
        'queue' => true,
    ];

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! self::isBannedArtisanCall($node, $scope->getFile())) {
            return [];
        }

        if (self::isInAllowedNamespace($scope->getNamespace())) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Do not call artisan commands programmatically — '
                . 'use dedicated jobs or scheduled commands instead.',
            )
                ->identifier('alz.noArtisanCall')
                ->build(),
        ];
    }

    private static function isBannedArtisanCall(StaticCall $node, string $filePath): bool
    {
        if (! $node->class instanceof Name) {
            return false;
        }

        if ($node->class->getLast() !== 'Artisan') {
            return false;
        }

        if (! $node->name instanceof Identifier || ! isset(self::BANNED_METHODS[$node->name->toString()])) {
            return false;
        }

        return self::isArtisanFacade($node->class, $filePath);
    }

    private static function isInAllowedNamespace(?string $namespace): bool
    {
        if ($namespace === null) {
            return false;
        }

        foreach (self::ALLOWED_NAMESPACES as $allowed => $_) {
            if (\str_starts_with($namespace, $allowed)) {
                return true;
            }
        }

        return false;
    }

    private static function isArtisanFacade(Name $name, string $filePath): bool
    {
        if ($name->isFullyQualified()) {
            return $name->toString() === self::FACADE_FQCN;
        }

        $content = \file_get_contents($filePath);

        return $content !== false && \str_contains($content, self::FACADE_FQCN);
    }
}

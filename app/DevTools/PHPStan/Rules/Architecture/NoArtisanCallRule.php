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
        if (! $node->class instanceof Name) {
            return [];
        }

        // Fast path: skip if the class short name isn't 'Artisan'
        if ($node->class->getLast() !== 'Artisan') {
            return [];
        }

        // Check the method name is call() or queue()
        if (! $node->name instanceof Identifier || ! isset(self::BANNED_METHODS[$node->name->toString()])) {
            return [];
        }

        // Verify it's the actual facade
        if (! self::isArtisanFacade($node->class, $scope->getFile())) {
            return [];
        }

        // Check if current namespace is in the allow list
        $namespace = $scope->getNamespace();

        if ($namespace !== null) {
            foreach (self::ALLOWED_NAMESPACES as $allowed => $_) {
                if (\str_starts_with($namespace, $allowed)) {
                    return [];
                }
            }
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

    private static function isArtisanFacade(Name $name, string $filePath): bool
    {
        if ($name->isFullyQualified()) {
            return $name->toString() === self::FACADE_FQCN;
        }

        $content = \file_get_contents($filePath);

        return $content !== false && \str_contains($content, self::FACADE_FQCN);
    }
}

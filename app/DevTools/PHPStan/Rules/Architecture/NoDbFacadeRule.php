<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Ban DB:: facade — use DatabaseGateway instead.
 *
 * DB:: bypasses exception translation and connection management.
 * Allowed in: Providers (RLS setup), Middleware (RLS context).
 *
 * Replaces spaze/disallowed-calls config which doesn't work with Larastan
 * facade resolution.
 *
 * @implements Rule<StaticCall>
 */
final class NoDbFacadeRule implements Rule
{
    private const string FACADE_FQCN = 'Illuminate\\Support\\Facades\\DB';

    /** @var array<string, true> Namespaces where DB:: is allowed */
    private const array ALLOWED_NAMESPACES = [
        'App\\Providers' => true,
        'App\\Presentation\\Http\\Middleware' => true,
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
        if (! self::isDbFacadeCall($node, $scope->getFile())) {
            return [];
        }

        if (self::isInAllowedNamespace($scope->getNamespace())) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Use DatabaseGateway instead of the DB facade — '
                . 'it provides exception translation and connection management.',
            )
                ->identifier('alz.noDbFacade')
                ->build(),
        ];
    }

    private static function isDbFacadeCall(StaticCall $node, string $filePath): bool
    {
        if (! $node->class instanceof Name) {
            return false;
        }

        if ($node->class->getLast() !== 'DB') {
            return false;
        }

        return self::isDbFacade($node->class, $filePath);
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

    private static function isDbFacade(Name $name, string $filePath): bool
    {
        // Fully qualified: \Illuminate\Support\Facades\DB::method()
        if ($name->isFullyQualified()) {
            return $name->toString() === self::FACADE_FQCN;
        }

        // Short name: check if the file imports the facade
        $content = \file_get_contents($filePath);

        return $content !== false && \str_contains($content, self::FACADE_FQCN);
    }
}

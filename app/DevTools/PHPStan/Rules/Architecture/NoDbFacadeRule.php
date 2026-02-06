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
        if (! $node->class instanceof Name) {
            return [];
        }

        // Fast path: skip if the class short name isn't 'DB'
        if ($node->class->getLast() !== 'DB') {
            return [];
        }

        // Verify it's the actual facade (not some other class named DB)
        if (! self::isDbFacade($node->class, $scope->getFile())) {
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
                'Use DatabaseGateway instead of the DB facade — '
                . 'it provides exception translation and connection management.',
            )
                ->identifier('alz.noDbFacade')
                ->build(),
        ];
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

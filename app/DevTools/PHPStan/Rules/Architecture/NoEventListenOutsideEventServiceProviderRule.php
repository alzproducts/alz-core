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
 * Event::listen() must only be called from EventServiceProvider.
 *
 * All event → listener wiring should be centralised in EventServiceProvider
 * so that feature providers can remain deferred. Deferred providers only call
 * boot() when their services are first resolved, which means Event::listen()
 * calls in boot() may never execute — a latent bug.
 *
 * @implements Rule<StaticCall>
 */
final class NoEventListenOutsideEventServiceProviderRule implements Rule
{
    private const string EVENT_FACADE_FQCN = 'Illuminate\\Support\\Facades\\Event';

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! self::isEventListenCall($node, $scope->getFile())) {
            return [];
        }

        if (! self::isNonEventServiceProvider($scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Event::listen() must be called from EventServiceProvider, not individual providers. '
                . 'Move this listener registration to App\\Providers\\EventServiceProvider::boot().',
            )
                ->identifier('alz.noEventListenOutsideEventServiceProvider')
                ->build(),
        ];
    }

    private static function isEventListenCall(StaticCall $node, string $filePath): bool
    {
        if (! $node->class instanceof Name || $node->class->getLast() !== 'Event') {
            return false;
        }

        if (! $node->name instanceof Node\Identifier || $node->name->toString() !== 'listen') {
            return false;
        }

        return self::isEventFacade($node->class, $filePath);
    }

    private static function isNonEventServiceProvider(Scope $scope): bool
    {
        $namespace = $scope->getNamespace();

        if ($namespace === null || ! \str_starts_with($namespace, 'App\\Providers')) {
            return false;
        }

        if (! $scope->isInClass()) {
            return false;
        }

        return $scope->getClassReflection()->getName() !== 'App\\Providers\\EventServiceProvider';
    }

    private static function isEventFacade(Name $name, string $filePath): bool
    {
        if ($name->isFullyQualified()) {
            return $name->toString() === self::EVENT_FACADE_FQCN;
        }

        $content = \file_get_contents($filePath);

        return $content !== false && \str_contains($content, self::EVENT_FACADE_FQCN);
    }
}

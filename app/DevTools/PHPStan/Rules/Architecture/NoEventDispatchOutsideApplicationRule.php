<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Ban event dispatching from Presentation and Infrastructure layers.
 *
 * Events should be dispatched from the Application layer so they fire
 * regardless of entry point (HTTP, Console, Queue).
 *
 * Detects: event(), ::dispatch(), ->dispatch()
 *
 * @implements Rule<CallLike>
 */
final class NoEventDispatchOutsideApplicationRule implements Rule
{
    /** @var array<string, true> */
    private const array BANNED_NAMESPACES = [
        'App\\Presentation' => true,
        'App\\Infrastructure' => true,
    ];

    /**
     * Namespace segments that indicate a class is an explicit bridge for Job::dispatch().
     *
     * @var array<string, true>
     */
    private const array EXEMPT_NAMESPACE_SEGMENTS = [
        'Dispatchers' => true,
        'Listeners' => true,
    ];

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! self::isEventDispatch($node)) {
            return [];
        }

        $namespace = $scope->getNamespace();

        if ($namespace === null) {
            return [];
        }

        foreach (self::BANNED_NAMESPACES as $banned => $_) {
            if (\str_starts_with($namespace, $banned)) {
                // Dispatcher classes are explicit bridges for Job::dispatch()
                if (self::isExemptNamespace($namespace)) {
                    return [];
                }

                return [
                    RuleErrorBuilder::message(
                        'Dispatch events from the Application layer, not '
                        . self::layerName($banned)
                        . ' — events should fire regardless of entry point.',
                    )
                        ->identifier('alz.noEventDispatchOutsideApplication')
                        ->build(),
                ];
            }
        }

        return [];
    }

    private static function isEventDispatch(Node $node): bool
    {
        // event() helper
        if ($node instanceof FuncCall
            && $node->name instanceof Name
            && $node->name->toString() === 'event'
        ) {
            return true;
        }

        // SomeEvent::dispatch() or Event::dispatch()
        if ($node instanceof StaticCall
            && $node->name instanceof Identifier
            && $node->name->toString() === 'dispatch'
        ) {
            return ! self::isSelfDispatch($node);
        }

        // $event->dispatch()
        return $node instanceof MethodCall
            && $node->name instanceof Identifier
            && $node->name->toString() === 'dispatch';
    }

    /**
     * Check if a static call is self::dispatch() — a queue job self-retry pattern, not event dispatching.
     */
    private static function isSelfDispatch(StaticCall $node): bool
    {
        return $node->class instanceof Name && $node->class->toString() === 'self';
    }

    private static function isExemptNamespace(string $namespace): bool
    {
        foreach (\explode('\\', $namespace) as $segment) {
            if (isset(self::EXEMPT_NAMESPACE_SEGMENTS[$segment])) {
                return true;
            }
        }

        return false;
    }

    private static function layerName(string $namespace): string
    {
        return match ($namespace) {
            'App\\Presentation' => 'Presentation',
            'App\\Infrastructure' => 'Infrastructure',
            default => 'this layer',
        };
    }
}

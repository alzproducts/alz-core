<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr;
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
 * @implements Rule<Expr>
 */
final class NoEventDispatchOutsideApplicationRule implements Rule
{
    /** @var array<string, true> */
    private const array BANNED_NAMESPACES = [
        'App\\Presentation' => true,
        'App\\Infrastructure' => true,
    ];

    public function getNodeType(): string
    {
        return Expr::class;
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
            return true;
        }

        // $event->dispatch()
        return $node instanceof MethodCall
            && $node->name instanceof Identifier
            && $node->name->toString() === 'dispatch';
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

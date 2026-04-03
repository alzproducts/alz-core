<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Jobs;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Job class names must start with Sync, Process, Reconcile, Set, Update, Cleanup, or Record.
 *
 * Consistent naming makes it immediately clear what a job does:
 * - Sync*: Data synchronization (e.g., SyncShopwiredProductsJob)
 * - Process*: Transform or process data (e.g., ProcessContactSubmissionJob)
 * - Reconcile*: Compare and fix discrepancies (e.g., ReconcileShopwiredProductsJob)
 * - Set*: Apply a specific state change (e.g., SetProductFreeDeliveryJob)
 * - Update*: Modify existing records (e.g., UpdateSkuJob)
 * - Cleanup*: Remove stale/expired data (e.g., CleanupStaleContactActionsJob)
 * - Record*: Persist historical/audit records (e.g., RecordPricePeriodJob)
 *
 * @implements Rule<InClassNode>
 */
final class JobNamingPrefixRule implements Rule
{
    private const array ALLOWED_PREFIXES = [
        'Sync',
        'Process',
        'Reconcile',
        'Set',
        'Update',
        'Cleanup',
        'Record',
    ];

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $node->getClassReflection();

        if (! self::isConcreteJobClass($classReflection)) {
            return [];
        }

        $shortName = $classReflection->getNativeReflection()->getShortName();

        return self::hasValidPrefix($shortName) ? [] : [self::buildError($shortName)];
    }

    private static function isConcreteJobClass(ClassReflection $classReflection): bool
    {
        return \str_contains($classReflection->getName(), 'App\\Infrastructure\\Jobs\\')
            && ! $classReflection->isAbstract()
            && ! $classReflection->isEnum();
    }

    private static function hasValidPrefix(string $shortName): bool
    {
        return \array_any(
            self::ALLOWED_PREFIXES,
            static fn(string $prefix): bool => \str_starts_with($shortName, $prefix),
        );
    }

    private static function buildError(string $shortName): IdentifierRuleError
    {
        return RuleErrorBuilder::message(
            \sprintf(
                'Job class name "%s" must start with one of: %s.',
                $shortName,
                \implode(', ', self::ALLOWED_PREFIXES),
            ),
        )
            ->identifier('alz.jobNamingPrefix')
            ->build();
    }
}

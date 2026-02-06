<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Jobs;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Job class names must start with Sync, Process, Reconcile, Set, Update, or Cleanup.
 *
 * Consistent naming makes it immediately clear what a job does:
 * - Sync*: Data synchronization (e.g., SyncShopwiredProductsJob)
 * - Process*: Transform or process data (e.g., ProcessContactSubmissionJob)
 * - Reconcile*: Compare and fix discrepancies (e.g., ReconcileShopwiredProductsJob)
 * - Set*: Apply a specific state change (e.g., SetProductFreeDeliveryJob)
 * - Update*: Modify existing records (e.g., UpdateSkuJob)
 * - Cleanup*: Remove stale/expired data (e.g., CleanupStaleContactActionsJob)
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
        $fullName = $classReflection->getName();

        if (! \str_contains($fullName, 'App\\Presentation\\Jobs\\')) {
            return [];
        }

        if ($classReflection->isAbstract()) {
            return [];
        }

        $shortName = $classReflection->getNativeReflection()->getShortName();

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (\str_starts_with($shortName, $prefix)) {
                return [];
            }
        }

        return [
            RuleErrorBuilder::message(
                \sprintf(
                    'Job class name "%s" must start with one of: %s.',
                    $shortName,
                    \implode(', ', self::ALLOWED_PREFIXES),
                ),
            )
                ->identifier('alz.jobNamingPrefix')
                ->build(),
        ];
    }
}

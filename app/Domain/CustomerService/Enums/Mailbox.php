<?php

declare(strict_types=1);

namespace App\Domain\CustomerService\Enums;

/**
 * Business mailboxes for customer service operations.
 *
 * These represent the logical mailboxes the business operates,
 * independent of any specific helpdesk tool. External service
 * IDs are configured separately in the relevant config files.
 */
enum Mailbox: string
{
    case Support = 'support';
    case PurchaseOrders = 'purchase_orders';
    case SuppliersPurchasing = 'suppliers_purchasing';
    case Accounts = 'accounts';

    /**
     * Human-readable label for display purposes.
     */
    public function label(): string
    {
        return match ($this) {
            self::Support => 'Support',
            self::PurchaseOrders => 'Purchase Orders',
            self::SuppliersPurchasing => 'Suppliers & Purchasing',
            self::Accounts => 'Accounts',
        };
    }
}

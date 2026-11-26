<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

/**
 * Names a QuickBooks call in failed-call logs and sets its curl timeout. Order-chunk calls run on a queued job with a
 * 600 s deadline, so a slow batch create can finish instead of becoming an unknown outcome; every other call keeps a
 * short timeout, because the transport refuses a call with less than its own timeout left before the deadline.
 */
enum QuickBooksCall: string
{
    private const int ORDER_CHUNK_TIMEOUT_SECONDS = 60;

    private const int DEFAULT_TIMEOUT_SECONDS = 15;

    case DocumentLookup = 'document_lookup';
    case ItemLookup = 'item_lookup';
    case CustomerLookup = 'customer_lookup';
    case CustomerCreate = 'customer_create';
    case BatchCreate = 'batch_create';
    case CompanyInfo = 'company_info';
    case MappingCandidates = 'mapping_candidates';
    case Other = 'other';

    public function timeoutSeconds(): int
    {
        return match ($this) {
            self::DocumentLookup, self::ItemLookup, self::CustomerLookup, self::CustomerCreate, self::BatchCreate => self::ORDER_CHUNK_TIMEOUT_SECONDS,
            self::CompanyInfo, self::MappingCandidates, self::Other => self::DEFAULT_TIMEOUT_SECONDS,
        };
    }
}

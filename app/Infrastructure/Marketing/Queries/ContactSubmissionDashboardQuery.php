<?php

declare(strict_types=1);

namespace App\Infrastructure\Marketing\Queries;

use App\Application\ContactSubmission\Queries\ContactSubmissionDashboardFiltersParams;
use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\ConversionStatus;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Applies dashboard filters to a marketing.contact_submission_dashboard_view query.
 *
 * All predicates operate on view columns (no joins, no correlated subqueries) — the
 * view has already pre-joined annotations and per-type latest action statuses.
 *
 * `isPotentialQuote` uses literal-match semantics: rows with `is_potential_quote IS NULL`
 * are excluded from both `true` and `false` matches. "Untriaged" remains a third state
 * reachable only by omitting the filter.
 */
final readonly class ContactSubmissionDashboardQuery
{
    /**
     * @param Builder<covariant Model> $q
     */
    public static function apply(Builder $q, ContactSubmissionDashboardFiltersParams $filters): void
    {
        self::applyHasGclid($q, $filters->hasGclid);
        self::applyIsPotentialQuote($q, $filters->isPotentialQuote);
        self::applyDateRange($q, $filters->dateFrom, $filters->dateTo);
        self::applyConversionStatus($q, $filters->conversionStatus);
    }

    /**
     * @param Builder<covariant Model> $q
     */
    private static function applyHasGclid(Builder $q, ?bool $hasGclid): void
    {
        if ($hasGclid === null) {
            return;
        }

        $hasGclid ? $q->whereNotNull('gclid') : $q->whereNull('gclid');
    }

    /**
     * @param Builder<covariant Model> $q
     */
    private static function applyIsPotentialQuote(Builder $q, ?bool $isPotentialQuote): void
    {
        if ($isPotentialQuote === null) {
            return;
        }

        $q->where('is_potential_quote', $isPotentialQuote);
    }

    /**
     * @param Builder<covariant Model> $q
     */
    private static function applyDateRange(Builder $q, ?DateTimeImmutable $from, ?DateTimeImmutable $to): void
    {
        if ($from !== null) {
            $q->where('created_at', '>=', $from);
        }

        if ($to !== null) {
            $q->where('created_at', '<', $to);
        }
    }

    /**
     * @param Builder<covariant Model> $q
     */
    private static function applyConversionStatus(Builder $q, ?ConversionStatus $status): void
    {
        if ($status === null) {
            return;
        }

        if ($status === ConversionStatus::None) {
            $q->whereNull('lead_status');
            $q->whereNull('quote_status');

            return;
        }

        [$column, $statuses] = self::predicateForStatus($status);
        $q->whereIn($column, $statuses);
    }

    /**
     * @return array{0: 'lead_status'|'quote_status', 1: list<string>}
     */
    private static function predicateForStatus(ConversionStatus $status): array
    {
        return match ($status) {
            ConversionStatus::LeadPending => ['lead_status', [ActionStatus::Pending->value, ActionStatus::Processing->value]],
            ConversionStatus::LeadSent => ['lead_status', [ActionStatus::Completed->value]],
            ConversionStatus::QuotePending => ['quote_status', [ActionStatus::Pending->value, ActionStatus::Processing->value]],
            ConversionStatus::QuoteSent => ['quote_status', [ActionStatus::Completed->value]],
            ConversionStatus::None => throw new LogicException('ConversionStatus::None handled in caller, not predicateForStatus'),
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Marketing\Queries;

use App\Application\ContactSubmission\Queries\ContactSubmissionDashboardFiltersParams;
use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\ContactSubmissionView;
use App\Domain\ContactSubmission\Enums\ConversionStatus;
use Carbon\CarbonImmutable;
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

    /**
     * Apply the named-view WHERE + ORDER BY to a dashboard-view query.
     *
     * Every view requires `has_ad_id = true` (only paid-ad-driven leads ever appear).
     * Sort order is per-view: Triage shows oldest first (work-the-queue), all others
     * show newest first.
     *
     * @param Builder<covariant Model> $q
     */
    public static function applyView(Builder $q, ContactSubmissionView $view): void
    {
        match ($view) {
            ContactSubmissionView::Triage => self::applyTriage($q),
            ContactSubmissionView::AwaitingQuote => self::applyAwaitingQuote($q),
            ContactSubmissionView::Failed => self::applyFailed($q),
            ContactSubmissionView::Completed => self::applyCompleted($q),
        };
    }

    /**
     * @param Builder<covariant Model> $q
     */
    private static function applyTriage(Builder $q): void
    {
        $q->where('has_ad_id', true)
            ->whereNull('lead_status')
            ->whereNull('dismissed_at')
            ->orderBy('created_at');
    }

    /**
     * @param Builder<covariant Model> $q
     */
    private static function applyAwaitingQuote(Builder $q): void
    {
        $q->where('has_ad_id', true)
            ->where('lead_status', ActionStatus::Completed->value)
            ->whereNull('quote_status')
            ->where('is_potential_quote', true)
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at');
    }

    /**
     * @param Builder<covariant Model> $q
     */
    private static function applyFailed(Builder $q): void
    {
        $q->where('has_ad_id', true)
            ->where(static function (Builder $q): void {
                $q->where('lead_status', ActionStatus::Failed->value)
                    ->orWhere('quote_status', ActionStatus::Failed->value);
            })
            ->orderByDesc('created_at');
    }

    /**
     * @param Builder<covariant Model> $q
     */
    private static function applyCompleted(Builder $q): void
    {
        // CarbonImmutable::now() is resolved at query time, not at class-load time —
        // required on Octane workers where static state would otherwise carry stale
        // timestamps. startOfMonth() before subMonths() avoids the month-boundary gap
        // documented in docs/guides/critical-pitfalls.md.
        $cutoff = CarbonImmutable::now()->startOfMonth()->subMonths(4);

        $q->where('has_ad_id', true)
            ->where(static function (Builder $q): void {
                $q->where('quote_status', ActionStatus::Completed->value)
                    ->orWhere(static function (Builder $q): void {
                        $q->where('lead_status', ActionStatus::Completed->value)
                            ->where('is_potential_quote', false);
                    });
            })
            ->where('created_at', '>=', $cutoff)
            ->orderByDesc('created_at');
    }
}

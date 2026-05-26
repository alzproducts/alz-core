<?php

declare(strict_types=1);

namespace App\Infrastructure\Marketing\Queries;

use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\ContactSubmissionView;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Applies the per-view WHERE + ORDER BY to a marketing.potential_conversions_view query.
 *
 * Every view enforces `has_ad_id = true` — only paid-ad-driven leads ever appear on the
 * dashboard. Call rows have NULL lead/quote status so they currently only appear in Triage.
 *
 * Sort order is per-view: Triage shows oldest first (work-the-queue), all others show newest first.
 */
final readonly class ContactSubmissionDashboardQuery
{
    /**
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

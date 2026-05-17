<?php

declare(strict_types=1);

namespace App\Infrastructure\Marketing\Repositories;

use App\Application\ContactSubmission\Queries\ContactSubmissionListQueryParams;
use App\Application\Contracts\ContactSubmission\ContactSubmissionDashboardQueryRepositoryInterface;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmissionListItem;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\PaginatedList;
use App\Infrastructure\Marketing\Models\ContactSubmissionDashboardViewModel;
use App\Infrastructure\Marketing\Queries\ContactSubmissionDashboardQuery;
use App\Infrastructure\Persistence\EloquentGateway;
use Illuminate\Database\Eloquent\Builder;
use Override;

/**
 * Pagination + mapping over marketing.contact_submission_dashboard_view.
 *
 * All join shape and column derivation lives in the view. All filter predicates
 * live in {@see ContactSubmissionDashboardQuery}. This repository is the thin
 * orchestration shim between them.
 */
final readonly class EloquentContactSubmissionDashboardQueryRepository implements ContactSubmissionDashboardQueryRepositoryInterface
{
    public function __construct(
        private EloquentGateway $eloquentGateway,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function paginate(ContactSubmissionListQueryParams $query): PaginatedList
    {
        $filters = $query->filters;

        return $this->eloquentGateway->paginate(
            modelClass: ContactSubmissionDashboardViewModel::class,
            scope: static function (Builder $q) use ($filters): void {
                ContactSubmissionDashboardQuery::apply($q, $filters);
                $q->orderByDesc('created_at');
            },
            relations: [],
            mapper: self::fromModel(...),
            perPage: $query->pagination->perPage,
            page: $query->pagination->page,
        );
    }

    private static function fromModel(ContactSubmissionDashboardViewModel $model): ContactSubmissionListItem
    {
        return new ContactSubmissionListItem(
            id: Guid::fromTrusted($model->id),
            name: $model->name,
            email: $model->email,
            reason: $model->reason,
            customerType: $model->customer_type,
            orderNumber: $model->order_number,
            quantity: $model->quantity,
            product: $model->product,
            shopwiredCustomerId: $model->shopwired_customer_id,
            gclid: $model->gclid,
            msclkid: $model->msclkid,
            fbclid: $model->fbclid,
            utmSource: $model->utm_source,
            utmMedium: $model->utm_medium,
            utmCampaign: $model->utm_campaign,
            pageUrl: $model->page_url,
            createdAt: $model->created_at->toDateTimeImmutable(),
            helpscoutExternalId: $model->helpscout_external_id,
            leadStatus: $model->lead_status,
            quoteStatus: $model->quote_status,
            isPotentialQuote: $model->is_potential_quote,
            notes: $model->notes,
            quotedAt: $model->quoted_at?->toDateTimeImmutable(),
        );
    }
}

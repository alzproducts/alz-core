<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Application\ContactSubmission\DTOs\ContactSubmissionListItemDTO;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * @mixin ContactSubmissionListItemDTO
 */
final class ContactSubmissionListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var ContactSubmissionListItemDTO $item */
        $item = $this->resource;

        return [
            'id' => $item->id->value,
            'source' => $item->source->value,
            'name' => $item->name,
            'email' => $item->email,
            'reason' => $item->reason?->value,
            'customer_type' => $item->customerType?->value,
            'order_number' => $item->orderNumber,
            'quantity' => $item->quantity,
            'product' => $item->product,
            'shopwired_customer_id' => $item->shopwiredCustomerId,
            'gclid' => $item->gclid,
            'msclkid' => $item->msclkid,
            'fbclid' => $item->fbclid,
            'utm_source' => $item->utmSource,
            'utm_medium' => $item->utmMedium,
            'utm_campaign' => $item->utmCampaign,
            'page_url' => $item->pageUrl,
            'created_at' => $item->createdAt->format(DateTimeInterface::ATOM),
            'helpscout_external_id' => $item->helpscoutExternalId,
            'lead_status' => $item->leadStatus?->value,
            'quote_status' => $item->quoteStatus?->value,
            'is_potential_quote' => $item->isPotentialQuote,
            'notes' => $item->notes,
            'quoted_at' => $item->quotedAt?->format(DateTimeInterface::ATOM),
            'caller_phone_number' => $item->callerPhoneNumber,
        ];
    }
}

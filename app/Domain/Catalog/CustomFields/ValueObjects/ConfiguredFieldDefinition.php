<?php

declare(strict_types=1);

namespace App\Domain\Catalog\CustomFields\ValueObjects;

use App\Domain\ValueObjects\Uuid;
use Webmozart\Assert\Assert;

/**
 * Read-path composition: a ShopWired custom field definition paired with local settings.
 *
 * Carries both identifiers for the row:
 *   • `$internalId` — the catalog-schema UUID we own. This is the canonical key for any
 *     write targeting the settings rows (those FK the UUID, not the ShopWired id).
 *   • `$base->id` — the ShopWired external integer id. Kept for lookups that originate
 *     from upstream contexts (e.g. a product exposing customField references by int id).
 *
 * Local presentation/behaviour overrides live in two settings VOs; when no row exists in
 * the corresponding settings table the field is null on the wrapper.
 *
 * The inner ShopWired definition is exposed as `$base` (not `$definition`) so callers
 * inside the value hierarchy read `$this->definition->base->name` instead of the noisier
 * `$this->definition->definition->name`. No delegation accessors — callers reach through
 * `->base` directly.
 */
final readonly class ConfiguredFieldDefinition
{
    public function __construct(
        public Uuid $internalId,
        public CustomFieldDefinition $base,
        public ?CustomFieldGeneralSettings $generalSettings,
        public ?ProductFieldSettings $productSettings,
    ) {
        Assert::true(
            $productSettings === null || $base->isProductField(),
            'ProductFieldSettings can only be attached to Product-type custom fields',
        );
    }
}

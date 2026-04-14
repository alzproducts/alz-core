<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired;

final class ShopwiredAdminUrlResolver
{
    private const string BASE = 'https://admin.myshopwired.uk/business';

    private function __construct() {}

    public static function productEditUrl(int $externalId): string
    {
        return self::BASE . '/manage-ecommerce-add-product/' . $externalId;
    }

    public static function categoryEditUrl(int $externalId): string
    {
        return self::BASE . '/manage-ecommerce-add-category/' . $externalId;
    }

    public static function brandEditUrl(int $externalId): string
    {
        return self::BASE . '/manage-ecommerce-add-brand/' . $externalId;
    }

    public static function customerEditUrl(int $externalId, bool $isTrade): string
    {
        $path = $isTrade
            ? '/manage-ecommerce-trade-account/'
            : '/manage-ecommerce-customer/';

        return self::BASE . $path . $externalId;
    }
}

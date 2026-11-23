<?php

declare(strict_types=1);

namespace App\Application\Contracts\QuickBooks;

interface QuickBooksRealmInterface
{
    public function realmId(): string;
}

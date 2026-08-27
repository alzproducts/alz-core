<?php

declare(strict_types=1);

namespace App\Presentation\Console\Traits;

use Throwable;

trait FormatsExceptionContextTrait
{
    /** Domain exceptions expose Sentry-grouping data via context(); native Throwables do not — hence the duck-type probe. */
    protected static function formatError(Throwable $e): string
    {
        $message = $e->getMessage();

        if (\method_exists($e, 'context')) {
            $ctx = $e->context();

            if ($ctx !== []) {
                $message .= ' — ' . \json_encode($ctx);
            }
        }

        return $message;
    }
}

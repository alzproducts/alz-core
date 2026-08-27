<?php

declare(strict_types=1);

namespace App\Presentation\Console\Traits;

use Throwable;

/**
 * Shared exception rendering for verification commands.
 */
trait FormatsExceptionContextTrait
{
    /**
     * Format an exception message with structured context for operator debugging.
     */
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

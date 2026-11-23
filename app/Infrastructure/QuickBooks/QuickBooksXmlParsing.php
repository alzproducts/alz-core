<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use Closure;

/**
 * Runs SDK code that parses a response body as XML with libxml errors collected, not raised. The SDK parses every
 * non-2xx body sent with an XML content type, and a gateway's empty or non-XML body raises a PHP warning, which
 * Laravel turns into an ErrorException before the Fault can be classified by status.
 */
final readonly class QuickBooksXmlParsing
{
    /**
     * @template T
     *
     * @param-immediately-invoked-callable $parse
     * @param Closure(): T $parse
     *
     * @return T
     */
    public static function quietly(Closure $parse): mixed
    {
        $useInternalErrors = \libxml_use_internal_errors(true);

        try {
            return $parse();
        } finally {
            \libxml_clear_errors();
            \libxml_use_internal_errors($useInternalErrors);
        }
    }
}

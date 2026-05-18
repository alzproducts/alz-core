<?php

declare(strict_types=1);

namespace App\Application\Support;

/**
 * Resolves authenticated user emails to service-specific emails.
 *
 * Handles cases where authentication email differs from external service
 * registration (e.g., JWT uses user.alias@ but HelpScout has user@).
 *
 * Each external service can have its own resolver instance configured
 * with mappings pointing toward that service's expected email format.
 */
final readonly class EmailAliasResolver
{
    /**
     * @param array<string, string> $aliases Map of auth_email => service_email
     */
    public function __construct(
        private array $aliases,
    ) {}

    /**
     * Resolve email to service-specific equivalent.
     *
     * Performs case-insensitive alias lookup and normalizes the result.
     * Returns the original email (normalized) if no alias is configured.
     */
    public function resolve(string $email): string
    {
        $normalized = \mb_strtolower(\mb_trim($email));

        foreach ($this->aliases as $from => $to) {
            if (\strcasecmp($from, $normalized) === 0) {
                return \mb_strtolower(\mb_trim($to));
            }
        }

        return $normalized;
    }
}

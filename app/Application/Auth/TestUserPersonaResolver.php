<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\Access\ValueObjects\AuthenticatedUser;
use RuntimeException;

/**
 * Resolves test user personas for local development.
 *
 * Maps Supabase test emails (e.g., dev@alzadmin.test) to real developer
 * credentials that external services like HelpScout require.
 *
 * Security:
 * - Only allow-listed test emails can be resolved
 * - Real email comes from env var (not hardcoded)
 * - Roles/departments are fixed per persona
 *
 * @see config/local-development.php
 */
final readonly class TestUserPersonaResolver
{
    /**
     * @param array<string, array{email: string|null, user_id: string, is_approved: bool, role_name: string|null, departments: list<string>|null}> $personas
     */
    public function __construct(
        private array $personas,
    ) {}

    /**
     * Create resolver from Laravel config.
     *
     * Returns empty resolver if config is not present, allowing graceful
     * fallback to legacy behavior.
     */
    public static function fromConfig(): self
    {
        /** @var array<string, array{email: string|null, user_id: string, is_approved: bool, role_name: string|null, departments: list<string>|null}>|null $personas */
        $personas = \config('local-development.test_user_personas');

        if (!\is_array($personas)) {
            return new self([]);
        }

        // Normalize all keys to lowercase for case-insensitive lookup
        $normalizedPersonas = [];
        foreach ($personas as $testEmail => $persona) {
            $normalizedPersonas[\mb_strtolower($testEmail)] = $persona;
        }

        return new self($normalizedPersonas);
    }

    /**
     * Resolve a test email to an AuthenticatedUser.
     *
     * @throws RuntimeException If test email is not in allow-list
     * @throws RuntimeException If resolved email is empty (env var not configured)
     */
    public function resolve(string $testEmail): AuthenticatedUser
    {
        $persona = $this->findPersonaOrFail($testEmail);
        $resolvedEmail = self::validatePersonaEmail($persona['email'], $testEmail);

        // AuthenticatedUser validates email format on construction
        return new AuthenticatedUser(
            id: $persona['user_id'],
            email: $resolvedEmail,
            isApproved: $persona['is_approved'],
            roleName: $persona['role_name'],
            departments: $persona['departments'],
        );
    }

    /**
     * @return array{email: string|null, user_id: string, is_approved: bool, role_name: string|null, departments: list<string>|null}
     *
     * @throws RuntimeException If test email is not in allow-list
     */
    private function findPersonaOrFail(string $testEmail): array
    {
        $normalizedEmail = \mb_strtolower($testEmail);

        if (!isset($this->personas[$normalizedEmail])) {
            throw new RuntimeException(
                "Test email '{$testEmail}' is not in the allow-list. "
                . 'Add it to config/local-development.php to use persona resolution.',
            );
        }

        return $this->personas[$normalizedEmail];
    }

    /**
     * @throws RuntimeException If resolved email is empty (env var not configured)
     */
    private static function validatePersonaEmail(?string $email, string $testEmail): string
    {
        if ($email === null || $email === '') {
            throw new RuntimeException(
                "Persona email not configured for test user '{$testEmail}'. "
                . 'Check the env var in config/local-development.php is set.',
            );
        }

        return $email;
    }
}

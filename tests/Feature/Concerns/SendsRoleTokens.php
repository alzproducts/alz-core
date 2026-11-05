<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use Illuminate\Support\Facades\Http;

/**
 * Real aal2 Supabase JWTs for an approved user with a chosen `role_name`, run through the
 * real JWT, approval and role middleware (unlike AuthenticatesAsApprovedUser, which stubs them).
 */
trait SendsRoleTokens
{
    use SignsSupabaseTokens;

    private const string ROLE_TOKEN_KID = 'role-test-kid';

    private const string ROLE_TOKEN_JWKS_URL = 'https://test.supabase.co/auth/v1/.well-known/jwks.json';

    private string $roleTokenPrivatePem = '';

    protected function fakeSupabaseJwks(): void
    {
        $keyPair = $this->generateSupabaseKeyPair(self::ROLE_TOKEN_KID);
        $this->roleTokenPrivatePem = $keyPair['private_pem'];

        Http::fake([self::ROLE_TOKEN_JWKS_URL => Http::response($this->supabaseJwksBody($keyPair['jwk']))]);
    }

    /**
     * Claims nest under `app_metadata` at aal2, so the request clears JWT and approval checks
     * and only the role decides the outcome.
     */
    protected function asRole(?string $roleName, bool $omitRoleName = false): static
    {
        $appMetadata = ['is_approved' => true];
        if (!$omitRoleName) {
            $appMetadata['role_name'] = $roleName;
        }

        $token = $this->signSupabaseToken(
            $this->supabaseClaims(['app_metadata' => $appMetadata]),
            $this->roleTokenPrivatePem,
            self::ROLE_TOKEN_KID,
        );

        return $this->withToken($token);
    }
}

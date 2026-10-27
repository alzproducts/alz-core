<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use Firebase\JWT\JWT;
use RuntimeException;

/**
 * P-256 key pairs, JWKS bodies and ES256 tokens shaped like Supabase Auth's.
 */
trait SignsSupabaseTokens
{
    private const int P256_COORDINATE_BYTES = 32;

    /**
     * @return array{private_pem: string, jwk: array<string, string>}
     */
    protected function generateSupabaseKeyPair(string $kid): array
    {
        $key = \openssl_pkey_new([
            'private_key_type' => \OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($key === false || !\openssl_pkey_export($key, $privatePem)) {
            throw new RuntimeException('Failed to generate a P-256 key pair');
        }

        $details = \openssl_pkey_get_details($key);
        if ($details === false) {
            throw new RuntimeException('Failed to read P-256 key details');
        }

        return [
            'private_pem' => $privatePem,
            'jwk' => [
                'kty' => 'EC',
                'crv' => 'P-256',
                'alg' => 'ES256',
                'use' => 'sig',
                'kid' => $kid,
                'x' => self::encodeCoordinate($details['ec']['x']),
                'y' => self::encodeCoordinate($details['ec']['y']),
            ],
        ];
    }

    /**
     * @param array<mixed> ...$keys
     *
     * @return array{keys: list<array<mixed>>}
     */
    protected function supabaseJwksBody(array ...$keys): array
    {
        return ['keys' => \array_values($keys)];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected function supabaseClaims(array $overrides = []): array
    {
        return \array_merge([
            'iss' => 'https://test.supabase.co/auth/v1',
            'aud' => 'authenticated',
            'iat' => \time(),
            'exp' => \time() + 3600,
            'role' => 'authenticated',
            'sub' => 'd9dd22a9-c3ab-413b-8a93-25b462231a98',
            'email' => 'test@example.com',
            'aal' => 'aal2',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $claims
     */
    protected function signSupabaseToken(array $claims, string $privatePem, string $kid): string
    {
        return JWT::encode($claims, $privatePem, 'ES256', $kid);
    }

    private static function encodeCoordinate(string $coordinate): string
    {
        return JWT::urlsafeB64Encode(\mb_str_pad($coordinate, self::P256_COORDINATE_BYTES, "\0", \STR_PAD_LEFT, '8bit'));
    }
}

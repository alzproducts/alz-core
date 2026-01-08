<?php

declare(strict_types=1);

use App\Application\Auth\TestUserPersonaResolver;

beforeEach(function (): void {
    $this->validPersonas = [
        'tom@alzadmin.test' => [
            'email' => 'tom@real.com',
            'user_id' => '550e8400-e29b-41d4-a716-446655440000',
            'is_approved' => true,
            'role_name' => 'admin',
            'departments' => ['support', 'sales'],
        ],
    ];
});

it('resolves known test email to authenticated user', function (): void {
    $resolver = new TestUserPersonaResolver($this->validPersonas);

    $user = $resolver->resolve('tom@alzadmin.test');

    expect($user->id)->toBe('550e8400-e29b-41d4-a716-446655440000')
        ->and($user->email)->toBe('tom@real.com')
        ->and($user->isApproved)->toBeTrue()
        ->and($user->roleName)->toBe('admin')
        ->and($user->departments)->toBe(['support', 'sales']);
});

it('performs case-insensitive email lookup', function (): void {
    $resolver = new TestUserPersonaResolver($this->validPersonas);

    $user = $resolver->resolve('TOM@ALZADMIN.TEST');

    expect($user->email)->toBe('tom@real.com');
});

it('throws when test email not in allow list', function (): void {
    $resolver = new TestUserPersonaResolver($this->validPersonas);

    expect(static fn() => $resolver->resolve('unknown@test.com'))
        ->toThrow(RuntimeException::class, 'not in the allow-list');
});

it('throws when resolved email is null', static function (): void {
    $personas = [
        'nullemail@test.com' => [
            'email' => null,
            'user_id' => '550e8400-e29b-41d4-a716-446655440000',
            'is_approved' => true,
            'role_name' => null,
            'departments' => null,
        ],
    ];
    $resolver = new TestUserPersonaResolver($personas);

    expect(static fn() => $resolver->resolve('nullemail@test.com'))
        ->toThrow(RuntimeException::class, 'not configured');
});

it('throws when resolved email is empty string', static function (): void {
    $personas = [
        'emptyemail@test.com' => [
            'email' => '',
            'user_id' => '550e8400-e29b-41d4-a716-446655440000',
            'is_approved' => true,
            'role_name' => null,
            'departments' => null,
        ],
    ];
    $resolver = new TestUserPersonaResolver($personas);

    expect(static fn() => $resolver->resolve('emptyemail@test.com'))
        ->toThrow(RuntimeException::class, 'not configured');
});

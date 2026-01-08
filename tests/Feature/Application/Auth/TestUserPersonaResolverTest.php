<?php

declare(strict_types=1);

use App\Application\Auth\TestUserPersonaResolver;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->configKey = 'local-development.test_user_personas';

    $this->uppercasePersona = [
        'TOM@ALZADMIN.TEST' => [
            'email' => 'tom@real.com',
            'user_id' => '550e8400-e29b-41d4-a716-446655440000',
            'is_approved' => true,
            'role_name' => 'admin',
            'departments' => ['support'],
        ],
    ];

    $this->simplePersona = [
        'test@example.com' => [
            'email' => 'real@example.com',
            'user_id' => '550e8400-e29b-41d4-a716-446655440000',
            'is_approved' => false,
            'role_name' => null,
            'departments' => null,
        ],
    ];
});

it('returns empty resolver when config is null', function (): void {
    Config::set($this->configKey, null);

    $resolver = TestUserPersonaResolver::fromConfig();

    expect(static fn() => $resolver->resolve('any@test.com'))
        ->toThrow(RuntimeException::class, 'not in the allow-list');
});

it('normalizes config keys to lowercase', function (): void {
    Config::set($this->configKey, $this->uppercasePersona);

    $resolver = TestUserPersonaResolver::fromConfig();
    $user = $resolver->resolve('tom@alzadmin.test');

    expect($user->email)->toBe('tom@real.com');
});

it('returns resolver with personas when config is valid array', function (): void {
    Config::set($this->configKey, $this->simplePersona);

    $resolver = TestUserPersonaResolver::fromConfig();
    $user = $resolver->resolve('test@example.com');

    expect($user->email)->toBe('real@example.com')
        ->and($user->isApproved)->toBeFalse();
});

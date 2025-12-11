<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Clients;

use App\Infrastructure\HelpScout\HelpScoutHttpTransport;
use App\Infrastructure\HelpScout\HelpScoutResponseParser;
use App\Infrastructure\HelpScout\Responses\User;

/**
 * HelpScout Users API Client.
 *
 * Handles user queries for the account.
 *
 * @see https://developer.helpscout.com/mailbox-api/endpoints/users/
 */
final readonly class UsersClient
{
    use HelpScoutResponseParser;

    private const string ENDPOINT = '/users';

    public function __construct(
        private HelpScoutHttpTransport $transport,
    ) {}

    /**
     * Find a HelpScout user by email address.
     *
     * Searches through all users to find one with matching email (case-insensitive).
     * Returns null if no user is found with the given email.
     *
     * @see https://developer.helpscout.com/mailbox-api/endpoints/users/list/
     */
    public function findByEmail(string $email): ?User
    {
        $response = $this->transport->get(self::ENDPOINT);

        /** @var array<User> $users */
        $users = $this->parseEmbeddedCollection($response->json(), 'users', User::class);

        return \array_find($users, static fn(User $user): bool => $user->matchesEmail($email));
    }

    /**
     * Get all users for the account.
     *
     * @return array<User>
     *
     * @see https://developer.helpscout.com/mailbox-api/endpoints/users/list/
     */
    public function list(): array
    {
        $response = $this->transport->get(self::ENDPOINT);

        /** @var array<User> */
        return $this->parseEmbeddedCollection($response->json(), 'users', User::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Clients;

use App\Application\Contracts\HelpScout\AgentsClientInterface;
use App\Domain\CustomerService\ValueObjects\SupportAgent;
use App\Infrastructure\HelpScout\HelpScoutHttpTransport;
use App\Infrastructure\HelpScout\HelpScoutResponseParser;
use App\Infrastructure\HelpScout\Responses\User;

/**
 * HelpScout Users API Client.
 *
 * Handles user queries for the account. Transforms Infrastructure DTOs
 * to Domain value objects at the boundary.
 *
 * @see https://developer.helpscout.com/mailbox-api/endpoints/users/
 */
final readonly class UsersClient implements AgentsClientInterface
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
    public function findByEmail(string $email): ?SupportAgent
    {
        return $this->findDomainInEmbeddedCollection(
            $this->transport->get(self::ENDPOINT),
            'users',
            User::class,
            static fn(User $user): bool => $user->matchesEmail($email),
            self::toDomain(...),
        );
    }

    /**
     * Get all users for the account.
     *
     * @return list<SupportAgent>
     *
     * @see https://developer.helpscout.com/mailbox-api/endpoints/users/list/
     */
    public function list(): array
    {
        return $this->parseEmbeddedCollectionToDomain(
            $this->transport->get(self::ENDPOINT),
            'users',
            User::class,
            self::toDomain(...),
        );
    }

    /**
     * Transform Infrastructure DTO to Domain value object.
     */
    private static function toDomain(User $user): SupportAgent
    {
        return new SupportAgent(
            id: $user->id,
            email: $user->email ?? '',
            firstName: $user->firstName ?? '',
            lastName: $user->lastName ?? '',
        );
    }
}

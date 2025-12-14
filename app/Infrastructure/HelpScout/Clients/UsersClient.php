<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Clients;

use App\Application\Contracts\HelpScout\AgentsClientInterface;
use App\Domain\CustomerService\ValueObjects\SupportAgent;
use App\Infrastructure\HelpScout\HelpScoutHttpTransport;
use App\Infrastructure\HelpScout\HelpScoutResponseParser;
use App\Infrastructure\HelpScout\Responses\UserResponse;

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
        /** @var SupportAgent|null */
        return HelpScoutResponseParser::findDomainInEmbeddedCollection(
            $this->transport->get(self::ENDPOINT),
            'users',
            UserResponse::class,
            static fn(UserResponse $user): bool => $user->matchesEmail($email),
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
        /** @var list<SupportAgent> */
        return HelpScoutResponseParser::parseEmbeddedCollectionToDomain(
            $this->transport->get(self::ENDPOINT),
            'users',
            UserResponse::class,
        );
    }
}

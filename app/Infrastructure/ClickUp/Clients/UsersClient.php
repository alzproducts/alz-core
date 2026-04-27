<?php

declare(strict_types=1);

namespace App\Infrastructure\ClickUp\Clients;

use App\Application\ClickUp\DTOs\ClickUpUserDataDTO;
use App\Application\Contracts\ClickUp\UsersClientInterface;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Infrastructure\ClickUp\ClickUpErrorHandler;
use App\Infrastructure\ClickUp\ClickUpHttpTransport;
use App\Infrastructure\ClickUp\Responses\AuthenticatedClickUpUserResponse;
use Exception;

final readonly class UsersClient implements UsersClientInterface
{
    public function __construct(
        private ClickUpHttpTransport $transport,
    ) {}

    /**
     * @throws AuthenticationExpiredException
     * @throws InvalidApiRequestException
     * @throws InvalidApiResponseException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     */
    public function getUser(ApiKeyToken $token): ClickUpUserDataDTO
    {
        $response = $this->transport->get($token, '/user');

        try {
            $data = $response->json('user');
            /** @var array<string, mixed> $userArray */
            $userArray = \is_array($data) ? $data : [];

            return AuthenticatedClickUpUserResponse::from($userArray)->toDto();
        } catch (Exception $e) {
            throw ClickUpErrorHandler::handleUnparseableResponse($e);
        }
    }
}

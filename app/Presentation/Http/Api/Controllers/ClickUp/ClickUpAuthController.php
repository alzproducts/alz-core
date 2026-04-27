<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers\ClickUp;

use App\Application\ClickUp\UseCases\DeleteClickUpApiKeyUseCase;
use App\Application\ClickUp\UseCases\GetClickUpApiKeyInfoUseCase;
use App\Application\ClickUp\UseCases\SaveClickUpApiKeyUseCase;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Access\ValueObjects\AuthenticatedUser;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Guid;
use App\Presentation\Http\Api\DTOs\ClickUp\SaveClickUpApiKeyRequestDTO;
use App\Presentation\Http\Api\Resources\ClickUp\ClickUpApiKeyInfoResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manages the ClickUp API key for the authenticated user.
 *
 * POST   /api/clickup/api-key          — validate (+ optional ?dry_run) and save
 * GET    /api/clickup/api-key          — return metadata (no plaintext)
 * DELETE /api/clickup/api-key          — remove key and clear cache
 *
 * @throws AuthenticationExpiredException
 * @throws InvalidApiRequestException
 * @throws InvalidApiResponseException
 * @throws ResourceNotFoundException
 * @throws ExternalServiceUnavailableException
 * @throws DatabaseOperationFailedException
 * @throws DuplicateRecordException
 * @throws KeyEncryptionFailedException
 * @throws CorruptApiKeyException
 */
final readonly class ClickUpAuthController
{
    public function __construct(
        private SaveClickUpApiKeyUseCase $saveUseCase,
        private GetClickUpApiKeyInfoUseCase $infoUseCase,
        private DeleteClickUpApiKeyUseCase $deleteUseCase,
    ) {}

    /**
     * Validate (and optionally persist) a ClickUp API key.
     *
     * Pass `?dry_run=true` to validate without writing to the database.
     *
     * @throws AuthenticationExpiredException When the key is rejected by ClickUp
     * @throws InvalidApiRequestException
     * @throws InvalidApiResponseException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws KeyEncryptionFailedException
     */
    public function save(Request $request, SaveClickUpApiKeyRequestDTO $data, AuthenticatedUser $user): JsonResponse
    {
        $this->saveUseCase->execute(
            userId: new Guid($user->id),
            token: new ApiKeyToken($data->api_key),
            dryRun: $request->boolean('dry_run'),
        );

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Return lightweight metadata about the stored API key.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws CorruptApiKeyException
     */
    public function info(AuthenticatedUser $user): ClickUpApiKeyInfoResource
    {
        $meta = $this->infoUseCase->execute(new Guid($user->id));

        return new ClickUpApiKeyInfoResource($meta);
    }

    /**
     * Delete the stored API key and clear related caches.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function delete(AuthenticatedUser $user): JsonResponse
    {
        $this->deleteUseCase->execute(new Guid($user->id));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}

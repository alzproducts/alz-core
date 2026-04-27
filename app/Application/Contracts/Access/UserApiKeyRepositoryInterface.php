<?php

declare(strict_types=1);

namespace App\Application\Contracts\Access;

use App\Application\ClickUp\Results\UserApiKeyMetaResult;
use App\Domain\Access\Enums\ThirdPartyService;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Guid;

interface UserApiKeyRepositoryInterface
{
    /**
     * Retrieve the plaintext token for a user/service pair.
     *
     * Returns null when no key has been configured.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws CorruptApiKeyException When the stored ciphertext cannot be decrypted
     */
    public function tokenForUser(Guid $userId, ThirdPartyService $service): ?ApiKeyToken;

    /**
     * Return lightweight metadata (existence, masked plaintext, last used).
     *
     * The masked value is derived from the decrypted plaintext, so a corrupt
     * ciphertext surfaces here too — frontend recovery UX is the same as a
     * missing key (re-paste in Settings).
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws CorruptApiKeyException When the stored ciphertext cannot be decrypted
     */
    public function metaForUser(Guid $userId, ThirdPartyService $service): UserApiKeyMetaResult;

    /**
     * Persist an encrypted key, creating or replacing any existing row for (user, service).
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws KeyEncryptionFailedException When local encryption fails
     */
    public function save(Guid $userId, ThirdPartyService $service, ApiKeyToken $token): void;

    /**
     * Remove the key for (user, service). No-op if no row exists.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function delete(Guid $userId, ThirdPartyService $service): void;
}

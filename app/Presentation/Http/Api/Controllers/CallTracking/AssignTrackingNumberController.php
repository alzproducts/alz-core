<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers\CallTracking;

use App\Application\Conversion\CallTracking\Commands\AssignTrackingNumberCommand;
use App\Application\Conversion\CallTracking\UseCases\AssignTrackingNumberUseCase;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidFormatException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IpAddress;
use App\Presentation\Http\Api\DTOs\AssignTrackingNumberRequestDTO;
use DateMalformedStringException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final readonly class AssignTrackingNumberController
{
    public function __construct(
        private AssignTrackingNumberUseCase $useCase,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DateMalformedStringException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidFormatException
     * @throws RuntimeException
     */
    public function __invoke(AssignTrackingNumberRequestDTO $dto, Request $request): JsonResponse
    {
        $ip = $request->ip();
        if (! \is_string($ip)) {
            throw new RuntimeException('Request IP could not be resolved');
        }

        $command = new AssignTrackingNumberCommand(
            attribution: self::buildAttribution($dto),
            marketingConsentGranted: $dto->marketingConsentGranted,
            ipAddress: IpAddress::from($ip),
            userAgent: $request->userAgent(),
        );

        $result = $this->useCase->execute($command);

        return new JsonResponse([
            'phone_number' => $result->phoneNumber->value,
            'call_visit_id' => $result->callVisitId?->value,
        ], Response::HTTP_OK);
    }

    private static function buildAttribution(AssignTrackingNumberRequestDTO $dto): MarketingAttribution
    {
        return new MarketingAttribution(
            gclid: $dto->gclid,
            gclsrc: $dto->gclsrc,
            wbraid: $dto->wbraid,
            gbraid: $dto->gbraid,
            msclkid: $dto->msclkid,
            fbclid: $dto->fbclid,
            utmSource: $dto->utmSource,
            utmMedium: $dto->utmMedium,
            utmCampaign: $dto->utmCampaign,
            utmContent: $dto->utmContent,
            utmTerm: $dto->utmTerm,
        );
    }
}

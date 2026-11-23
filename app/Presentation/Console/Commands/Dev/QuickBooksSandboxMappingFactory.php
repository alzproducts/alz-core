<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands\Dev;

use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\QuickBooks\Mappings\DTOs\MappingCandidateDTO;
use App\Application\QuickBooks\Mappings\DTOs\MappingDTO;
use App\Application\QuickBooks\Mappings\Services\MappingCandidateService;
use App\Application\QuickBooks\Mappings\UseCases\ListUnresolvedMappingsUseCase;
use App\Application\QuickBooks\Mappings\UseCases\RecordMappingPickUseCase;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\DomainException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\QuickBooks\QuickBooksTransport;
use DateTimeImmutable;
use QuickBooksOnline\API\Data\IPPIntuitEntity;

/**
 * Auto resolve: points each unresolved key at an existing tax code or custom field, or at a synthetic entity of its own.
 */
final readonly class QuickBooksSandboxMappingFactory
{
    private const int CALL_SECONDS = 60;

    public function __construct(
        private ListUnresolvedMappingsUseCase $unresolved,
        private RecordMappingPickUseCase $record,
        private MappingCandidateService $candidates,
        private QuickBooksMappingRepositoryInterface $mappings,
        private QuickBooksTransport $transport,
        private QuickBooksTokenService $tokens,
    ) {}

    /**
     * @return list<array{MappingKey, string}> each key still unresolved, with why
     *
     * @throws DomainException
     */
    public function resolveAll(): array
    {
        $left = $this->withoutRow();
        $candidates = [];
        foreach ($this->unresolved->execute() as $key) {
            $entity = $key->entity();
            $candidates[$entity->value] ??= $this->candidates->candidates($entity);
            $target = $this->target($key, $candidates[$entity->value]);
            if (\is_string($target)) {
                $left[] = [$key, $target];
            } else {
                $this->record->execute($key, $target);
            }
        }

        return $left;
    }

    /**
     * @return list<array{MappingKey, string}>
     *
     * @throws DomainException
     */
    public function pending(): array
    {
        $unresolved = \array_map(static fn(MappingKey $key): array => [$key, 'unresolved'], $this->unresolved->execute());

        return [...$this->withoutRow(), ...$unresolved];
    }

    /**
     * @param list<MappingCandidateDTO> $candidates active and inactive, as an inactive entity still holds its name
     *
     * @return MappingCandidateDTO|string the entity, or why there is none
     *
     * @throws DomainException
     */
    private function target(MappingKey $key, array $candidates): MappingCandidateDTO|string
    {
        $name = QuickBooksSandboxMapper::nameFor($key);
        $matches = \array_filter($candidates, static fn(MappingCandidateDTO $candidate): bool => $candidate->name === $name);
        $active = \array_values(\array_filter($matches, static fn(MappingCandidateDTO $candidate): bool => $candidate->active));

        return match (true) {
            \count($active) === 1 => $active[0],
            $active !== [] => "several active matches for \"{$name}\"",
            $matches !== [] => "the match for \"{$name}\" is inactive",
            QuickBooksSandboxMapper::isMatchOnly($key) => "no active match for \"{$name}\"",
            default => $this->create($key, $name),
        };
    }

    /**
     * @throws DomainException
     */
    private function create(MappingKey $key, string $name): MappingCandidateDTO|string
    {
        try {
            $created = $this->transport->add(
                $this->tokens->accessToken(),
                QuickBooksSandboxMapper::mappingEntity($key->entity(), $name),
                IPPIntuitEntity::class,
                Deadline::after(new DateTimeImmutable(), self::CALL_SECONDS),
            );
        } catch (QuickBooksFaultException $e) {
            return 'QuickBooks refused the create: ' . QuickBooksSandboxMapper::faultText($e->fault);
        }

        $id = QuickBooksSandboxRowParser::text($created, 'Id')
            ?? throw new UnexpectedApiResultException('QuickBooks', 'Created entity id blank');

        return new MappingCandidateDTO($id, $name, true);
    }

    /**
     * @return list<array{MappingKey, string}>
     *
     * @throws DomainException
     */
    private function withoutRow(): array
    {
        $stored = \array_map(static fn(MappingDTO $row): MappingKey => $row->key, $this->mappings->all());
        $missing = \array_filter(MappingKey::cases(), static fn(MappingKey $key): bool => !\in_array($key, $stored, true));

        return \array_map(
            static fn(MappingKey $key): array => [$key, 'it has no row in quickbooks.mappings'],
            \array_values($missing),
        );
    }
}

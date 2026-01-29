<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories;

use App\Application\Contracts\EscalationsConfigRepositoryInterface;
use App\Domain\CustomerService\ValueObjects\EscalationsConfig;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\ConfigurationNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Database\DatabaseGateway;
use JsonException;

/**
 * Loads escalation configuration from the database.
 *
 * Configuration is stored in the `config.dashboard` table with
 * `table_name = 'hs_escalations'`. The settings JSON contains
 * threshold and tag configuration for the HelpScout dashboard widgets.
 *
 * NOTE: Injects concrete DatabaseGateway (not interface) because
 * query-builder access requires connection(). See DatabaseGateway
 * class docblock for injection pattern guidance.
 */
final readonly class EscalationsConfigRepository implements EscalationsConfigRepositoryInterface
{
    private const string TABLE = 'config.dashboard';

    private const string CONFIG_NAME = 'hs_escalations';

    public function __construct(
        private DatabaseGateway $gateway,
    ) {}

    /**
     * @throws ConfigurationNotFoundException When config missing or disabled
     * @throws JsonException When settings JSON is invalid
     * @throws DatabaseOperationFailedException When query fails permanently
     * @throws DuplicateRecordException When unique constraint violated (defensive - shouldn't occur in reads)
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     *
     * @noinspection UnknownColumnInspection
     */
    public function get(): EscalationsConfig
    {
        /** @var object{settings: string}|null $row @phpstan-ignore varTag.type (stdClass with known shape) */
        $row = $this->gateway->query(
            fn(): ?object => $this->gateway->connection()->table(self::TABLE)
                ->where('table_name', self::CONFIG_NAME)
                ->where('enabled', true)
                ->first(),
        );

        if ($row === null) {
            throw new ConfigurationNotFoundException(self::CONFIG_NAME);
        }

        /** @var array{lateThresholdHours: int, latePriorityThresholdHours: int, priorityTags: list<string>, excludedTags: list<string>, assignedTag: string} $settings */
        $settings = \json_decode($row->settings, true, 512, JSON_THROW_ON_ERROR);

        return new EscalationsConfig(
            lateThresholdHours: $settings['lateThresholdHours'],
            latePriorityThresholdHours: $settings['latePriorityThresholdHours'],
            priorityTags: $settings['priorityTags'],
            excludedTags: $settings['excludedTags'],
            assignedTag: $settings['assignedTag'],
        );
    }
}

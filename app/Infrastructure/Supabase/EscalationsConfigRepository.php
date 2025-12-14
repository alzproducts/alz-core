<?php

declare(strict_types=1);

namespace App\Infrastructure\Supabase;

use App\Application\Contracts\DatabaseClientInterface;
use App\Application\Contracts\EscalationsConfigRepositoryInterface;
use App\Domain\CustomerService\ValueObjects\EscalationsConfig;
use App\Domain\Exceptions\ConfigurationNotFoundException;
use Illuminate\Database\ConnectionInterface;
use JsonException;

/**
 * Loads escalation configuration from Supabase.
 *
 * Configuration is stored in the `config.dashboard` table with
 * `table_name = 'hs_escalations'`. The settings JSON contains
 * threshold and tag configuration for the HelpScout dashboard widgets.
 */
final readonly class EscalationsConfigRepository implements EscalationsConfigRepositoryInterface
{
    private const string TABLE = 'config.dashboard';

    private const string CONFIG_NAME = 'hs_escalations';

    public function __construct(
        private DatabaseClientInterface $database,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @throws ConfigurationNotFoundException|JsonException When config missing or disabled
     * @noinspection UnknownColumnInspection
     */
    public function get(): EscalationsConfig
    {
        $connection = $this->connection;

        /** @var object{settings: string}|null $row */
        $row = $this->database->execute(
            static fn(): ?object => $connection->table(self::TABLE)
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

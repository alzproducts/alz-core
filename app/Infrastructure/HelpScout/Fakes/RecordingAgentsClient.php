<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Fakes;

use App\Application\Contracts\HelpScout\AgentsClientInterface;
use App\Domain\CustomerService\ValueObjects\SupportAgent;
use App\Infrastructure\Integrations\LiveIntegration;
use App\Infrastructure\Integrations\OutboundCallRecorder;

final readonly class RecordingAgentsClient implements AgentsClientInterface
{
    public function __construct(private OutboundCallRecorder $recorder) {}

    public function findByEmail(string $email): ?SupportAgent
    {
        $this->recorder->record(LiveIntegration::HelpScout, 'findAgentByEmail');

        return null;
    }

    public function list(): array
    {
        $this->recorder->record(LiveIntegration::HelpScout, 'listAgents');

        return [];
    }
}

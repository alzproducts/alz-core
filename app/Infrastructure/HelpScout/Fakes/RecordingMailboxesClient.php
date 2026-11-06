<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Fakes;

use App\Application\Contracts\HelpScout\MailboxesClientInterface;
use App\Infrastructure\Integrations\LiveIntegration;
use App\Infrastructure\Integrations\OutboundCallRecorder;

final readonly class RecordingMailboxesClient implements MailboxesClientInterface
{
    public function __construct(private OutboundCallRecorder $recorder) {}

    public function list(): array
    {
        $this->recorder->record(LiveIntegration::HelpScout, 'listMailboxes');

        return [];
    }
}

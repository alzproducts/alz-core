<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Jobs\Fixtures;

use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;

final class SlowDefaultQueueJob extends AbstractJob
{
    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue(QueueName::Default->value);
    }

    public function handle(): void {}
}

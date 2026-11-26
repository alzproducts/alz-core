<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Sentry;

use LogicException;
use Sentry\Event;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

/**
 * Keeps each event after every hook has run, and sends nothing.
 */
final class CapturingSentryTransport implements TransportInterface
{
    /** @var list<Event> */
    private array $events = [];

    public function send(Event $event): Result
    {
        $this->events[] = $event;

        return new Result(ResultStatus::success(), $event);
    }

    public function close(?int $timeout = null): Result
    {
        return new Result(ResultStatus::success());
    }

    public function lastEvent(): Event
    {
        return $this->events[\array_key_last($this->events) ?? throw new LogicException('Sentry sent nothing')];
    }
}

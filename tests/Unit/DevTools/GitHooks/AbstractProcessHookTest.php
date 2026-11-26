<?php

declare(strict_types=1);

namespace Tests\Unit\DevTools\GitHooks;

use App\DevTools\GitHooks\AbstractProcessHook;
use Igorsgm\GitHooks\Exceptions\HookFailException;
use Igorsgm\GitHooks\Git\Log;
use Illuminate\Console\Command;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Tests\TestCase;

#[CoversClass(AbstractProcessHook::class)]
final class AbstractProcessHookTest extends TestCase
{
    private string $pidFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pidFile = \sys_get_temp_dir() . '/hook-grandchild-' . \bin2hex(\random_bytes(4));
    }

    protected function tearDown(): void
    {
        $pid = $this->grandchildPid();
        if ($pid !== null) {
            \posix_kill($pid, \SIGKILL);
        }
        if (\is_file($this->pidFile)) {
            \unlink($this->pidFile);
        }

        parent::tearDown();
    }

    public function test_a_timeout_kills_a_grandchild_that_ignores_termination(): void
    {
        // Three levels, like make -> pest -> worker. The deepest one ignores SIGTERM.
        $worker = "trap '' TERM; echo \$\$ > {$this->pidFile}; exec sleep 30";
        $hook = $this->hook(['sh', '-c', 'sh -c "sh -c \"$0\" & wait" & wait', $worker], timeout: 3, command: $this->command());

        try {
            $hook->handle(new Log(''), static fn(): null => null);
            self::fail('The hook did not time out.');
        } catch (ProcessTimedOutException) {
            $pid = $this->grandchildPid();
            self::assertNotNull($pid, 'The grandchild never started.');
            self::assertTrue($this->exits($pid), "Grandchild {$pid} is still running.");
        }
    }

    public function test_a_passing_command_reports_success_and_calls_the_next_hook(): void
    {
        $command = $this->command();
        $log = new Log('');

        $result = $this->hook(['true'], timeout: 30, command: $command)
            ->handle($log, static fn(Log $passed): string => $passed === $log ? 'next' : 'wrong log');

        self::assertSame('next', $result);
        $command->shouldHaveReceived('info')->with('✓ Probe passed')->once();
        $command->shouldNotHaveReceived('error');
    }

    public function test_a_failing_command_prints_its_output_and_fails_the_hook(): void
    {
        $printed = [];
        $command = $this->command();
        foreach (['error', 'line'] as $method) {
            $command->shouldReceive($method)->andReturnUsing(static function (string $text) use (&$printed, $method): void {
                $printed[] = [$method, $text];
            });
        }

        try {
            $this->hook(['sh', '-c', 'echo to-stdout; echo to-stderr >&2; exit 1'], timeout: 30, command: $command)
                ->handle(new Log(''), static fn(): never => self::fail('The next hook ran.'));
            self::fail('The hook did not fail.');
        } catch (HookFailException $e) {
            self::assertSame('Probe hook failed.', $e->getMessage());
        }

        self::assertSame([['error', 'Probe failed'], ['line', "to-stdout\n"], ['line', "to-stderr\n"]], $printed);
    }

    private function command(): Command&MockInterface
    {
        return Mockery::spy(Command::class);
    }

    /**
     * @param list<string> $processCommand
     */
    private function hook(array $processCommand, int $timeout, Command $command): AbstractProcessHook
    {
        $hook = new class ($processCommand, $timeout) extends AbstractProcessHook {
            /**
             * @param list<string> $processCommand
             */
            public function __construct(private readonly array $processCommand, private readonly int $timeout) {}

            public function getName(): string
            {
                return 'Probe hook';
            }

            protected function getProcessCommand(): array
            {
                return $this->processCommand;
            }

            protected function getTimeout(): int
            {
                return $this->timeout;
            }

            protected function getSuccessMessage(): string
            {
                return 'Probe passed';
            }

            protected function getFailureMessage(): string
            {
                return 'Probe failed';
            }
        };
        $hook->setCommand($command);

        return $hook;
    }

    private function grandchildPid(): ?int
    {
        $pid = \is_file($this->pidFile) ? (int) \file_get_contents($this->pidFile) : 0;

        return $pid > 0 ? $pid : null;
    }

    /**
     * A killed process stays signallable until init reaps it, so allow a moment for that.
     */
    private function exits(int $pid): bool
    {
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            if (! \posix_kill($pid, 0)) {
                return true;
            }
            \usleep(10_000);
        }

        return false;
    }
}

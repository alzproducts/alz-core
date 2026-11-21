<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncRepositoryInterface;
use App\Infrastructure\QuickBooks\EloquentOrderSyncRepository;
use Illuminate\Support\Facades\DB;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * A forked child and this process claim the same unclaimed reference at once, each on its own
 * session. The rows are committed, so this test cleans up its realm instead of rolling back.
 */
#[CoversClass(EloquentOrderSyncRepository::class)]
final class OrderSyncConcurrentClaimTest extends TestCase
{
    private const string REALM = 'realm-concurrent-claim-test';

    private const int REFERENCE = 2147450501;

    private const int ROUNDS = 5;

    private const float DEADLINE_SECONDS = 30.0;

    private ?int $childPid = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        self::cleanUp();
    }

    #[Override]
    protected function tearDown(): void
    {
        if ($this->childPid !== null) {
            \posix_kill($this->childPid, \SIGKILL);
            \pcntl_waitpid($this->childPid, $status);
        }

        self::cleanUp();

        parent::tearDown();
    }

    #[Test]
    public function two_concurrent_claims_on_one_unclaimed_reference_give_exactly_one_token(): void
    {
        $repository = $this->app->make(OrderSyncRepositoryInterface::class);
        $sockets = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($sockets);
        [$parentSocket, $childSocket] = $sockets;

        // intentional: the child must open its own sessions, never reuse the parent's sockets
        foreach (\array_keys(DB::getConnections()) as $name) {
            DB::disconnect($name);
        }

        $pid = \pcntl_fork();
        self::assertNotSame(-1, $pid, 'fork failed');
        if ($pid === 0) {
            \fclose($parentSocket);
            self::runChild($repository, $childSocket);
        }
        $this->childPid = $pid;
        \fclose($childSocket);

        $parentWins = [];
        for ($round = 0; $round < self::ROUNDS; $round++) {
            self::assertSame("ready:{$round}\n", \fgets($parentSocket), 'The child did not reach the barrier');
            \fwrite($parentSocket, "go:{$round}\n");
            $parentWins[] = $repository->claim(self::REALM, self::REFERENCE + $round, self::REFERENCE + $round, false) !== null;
        }

        $report = \stream_get_contents($parentSocket);
        $this->awaitExit($pid);
        $this->childPid = null;

        self::assertIsString($report);
        self::assertSame(1, \preg_match('/^ok:([01]{' . self::ROUNDS . '})$/', $report, $match), "The child failed: {$report}");
        foreach (\mb_str_split($match[1]) as $round => $childWon) {
            self::assertNotSame($parentWins[$round], $childWon === '1', "Round {$round} did not have exactly one winner");
        }

        self::assertSame(self::ROUNDS, DB::table('quickbooks.order_syncs')->where('realm_id', self::REALM)->where('attempts', 1)->count());
    }

    private function awaitExit(int $pid): void
    {
        $deadline = \microtime(true) + self::DEADLINE_SECONDS;
        while (\pcntl_waitpid($pid, $status, \WNOHANG) === 0) {
            self::assertLessThan($deadline, \microtime(true), 'The child did not exit');
            \usleep(10_000);
        }
    }

    /**
     * @param resource $socket
     */
    private static function runChild(OrderSyncRepositoryInterface $repository, $socket): never
    {
        try {
            DB::purge('pgsql');
            DB::reconnect('pgsql');
            $wins = '';
            for ($round = 0; $round < self::ROUNDS; $round++) {
                \fwrite($socket, "ready:{$round}\n");
                if (\fgets($socket) !== "go:{$round}\n") {
                    throw new RuntimeException("No go signal for round {$round}");
                }
                $wins .= $repository->claim(self::REALM, self::REFERENCE + $round, self::REFERENCE + $round, false) !== null ? '1' : '0';
            }
            \fwrite($socket, "ok:{$wins}");
        } catch (Throwable $e) {
            \fwrite($socket, $e::class . ': ' . $e->getMessage());
        }

        // intentional: SIGKILL skips shutdown handlers and destructors that would tear down the parent's test state
        \posix_kill(\getmypid(), \SIGKILL);

        exit(1);
    }

    private static function cleanUp(): void
    {
        DB::table('quickbooks.order_syncs')->where('realm_id', self::REALM)->delete();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\ProductListFreshness;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

/**
 * A forked writer commits successive versions of one product through the webhook repository while
 * this process lists it over HTTP. Before each request the committed version is read on a separate
 * session; the listed version must be at least that floor, must never go backwards, and the
 * variation must belong to the same version as its product.
 */
#[CoversNothing]
final class ConcurrentWriterFreshnessTest extends ProductListFreshnessTestCase
{
    private const int VERSIONS = 30;

    private const int WRITE_INTERVAL_MICROSECONDS = 15_000;

    private const float DEADLINE_SECONDS = 60.0;

    private ?int $childPid = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $productId = $this->insertProduct(0, ['title' => self::title(0)]);
        $this->insertVariation($productId, 0, 0);
    }

    #[Override]
    protected function tearDown(): void
    {
        if ($this->childPid !== null) {
            \posix_kill($this->childPid, \SIGKILL);
            \pcntl_waitpid($this->childPid, $status);
        }

        parent::tearDown();
    }

    #[Test]
    public function concurrent_reads_never_predate_a_prior_commit_or_go_backwards(): void
    {
        $this->warm(0);
        $repository = $this->app->make(ProductRepositoryInterface::class);
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
            self::runWriter($repository, $childSocket);
        }
        $this->childPid = $pid;
        \fclose($childSocket);

        $seen = $this->readWhileWriting($pid);
        $this->childPid = null;

        self::assertSame('ok', \stream_get_contents($parentSocket), 'The writer child failed');
        self::assertSame(self::VERSIONS, $this->listedVersion($this->listedProduct(0)));
        self::assertGreaterThan(1, \count(\array_unique($seen)), 'The reads never overlapped the writes');
    }

    /**
     * @return list<int> every listed version, in read order
     */
    private function readWhileWriting(int $pid): array
    {
        $seen = [];
        $previous = 0;
        $deadline = \microtime(true) + self::DEADLINE_SECONDS;

        while (\pcntl_waitpid($pid, $status, \WNOHANG) === 0) {
            self::assertLessThan($deadline, \microtime(true), 'The writer child did not finish');

            $floor = self::versionOf(DB::table('shopwired.products')->where('external_id', self::productExternalId(0))->value('title'));
            $version = $this->listedVersion($this->listedProduct(0));

            self::assertGreaterThanOrEqual($floor, $version, 'A response predates a commit made before its request began');
            self::assertGreaterThanOrEqual($previous, $version, 'A later read saw an older version');
            $seen[] = $previous = $version;
        }

        return $seen;
    }

    /**
     * @param array<string, mixed> $product
     */
    private function listedVersion(array $product): int
    {
        $version = self::versionOf($product['title']);
        self::assertEquals(self::price($version), $product['price']);
        self::assertEquals(self::price($version), self::listedVariation($product, 0)['price'], 'The variation is from another version');

        return $version;
    }

    /**
     * @param resource $socket
     */
    private static function runWriter(ProductRepositoryInterface $repository, $socket): never
    {
        try {
            DB::purge('pgsql');
            DB::reconnect('pgsql');
            for ($n = 1; $n <= self::VERSIONS; $n++) {
                \usleep(self::WRITE_INTERVAL_MICROSECONDS);
                $repository->saveFromWebhook(
                    self::domainProduct(0, self::price($n), [self::domainVariation(0, 0, self::price($n))], self::title($n)),
                    ['variations'],
                );
            }
            \fwrite($socket, 'ok');
        } catch (Throwable $e) {
            \fwrite($socket, $e::class . ': ' . $e->getMessage());
        }

        // intentional: SIGKILL skips shutdown handlers and destructors that would tear down the parent's test state
        \posix_kill(\getmypid(), \SIGKILL);

        exit(1);
    }

    private static function title(int $version): string
    {
        return "v{$version}";
    }

    private static function price(int $version): float
    {
        return $version === 0 ? 50.0 : 100.25 + $version;
    }

    private static function versionOf(mixed $title): int
    {
        self::assertIsString($title);
        self::assertSame(1, \preg_match('/^v(\d+)$/', $title, $match), "Unexpected title {$title}");

        return (int) $match[1];
    }

    #[Override]
    protected static function idBase(): int
    {
        return 2147480800;
    }

    #[Override]
    protected static function skuPrefix(): string
    {
        return 'FRESH-CON-';
    }
}

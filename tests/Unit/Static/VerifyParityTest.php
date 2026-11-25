<?php

declare(strict_types=1);

namespace Tests\Unit\Static;

use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Static\Support\VerifyStubRun;

/**
 * `verify parity` compares the head run's and the base run's responses to the same GET requests.
 */
#[CoversNothing]
#[Group('repo-static')]
final class VerifyParityTest extends TestCase
{
    private VerifyStubRun $run;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->run = new VerifyStubRun();
        $this->run->commit();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->run->remove();

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string, list<string>, string}> head response, base response, flags, the one verdict line's start
     */
    public static function responsePairs(): iterable
    {
        $pair = static fn(string $head, string $base, string $verdict, string ...$flags): array => [self::response($head), self::response($base), \array_values($flags), $verdict];

        yield 'equal bodies' => $pair('{"a":[1,"x"]}', '{"a":[1,"x"]}', 'match GET /x');
        yield 'object key order' => $pair('{"a":1,"b":{"c":1,"d":2}}', '{"b":{"d":2,"c":1},"a":1}', 'match GET /x');
        yield 'a changed value' => $pair('{"data":{"title":"a"}}', '{"data":{"title":"b"}}', 'difference GET /x .data.title');
        yield 'a missing field' => $pair('{"data":[{"a":1}]}', '{"data":[{"a":1,"b":2}]}', 'difference GET /x .data[0].b');
        yield 'a reordered array' => $pair('{"a":[1,2,2]}', '{"a":[2,1,2]}', 'difference GET /x .a[0]');
        yield 'number form' => $pair('{"a":1}', '{"a":1.0}', 'difference GET /x .a');
        yield 'a number against a string' => $pair('{"a":1}', '{"a":"1"}', 'difference GET /x .a');
        yield 'null against absent' => $pair('{"a":null}', '{}', 'difference GET /x .a');
        yield 'an empty object against an empty array' => $pair('{"a":{}}', '{"a":[]}', 'difference GET /x .a');
        yield 'integers beyond double precision' => $pair('{"a":12345678901234567890123}', '{"a":12345678901234567890124}', 'difference GET /x .a');
        yield 'an unequal status' => [self::response('{}', 200), self::response('{}', 201), [], 'difference GET /x status code'];
        yield 'one byte of a body that is not JSON' => $pair('<p>a</p>', '<p>b</p>', 'difference GET /x body bytes');
        yield 'an ignored field' => $pair('{"data":[{"t":1,"u":1}]}', '{"data":[{"t":1,"u":2}]}', 'match GET /x', '--ignore', '.data[].u');
        yield 'a field beside an ignored one' => $pair('{"data":[{"t":1,"u":1}]}', '{"data":[{"t":2,"u":2}]}', 'difference GET /x .data[0].t', '--ignore', '.data[].u');
        yield 'a key that needs quoting, ignored' => $pair('{"a b":{"c":1}}', '{"a b":{"c":2}}', 'match GET /x', '--ignore', '.["a b"].c');
        yield 'equal unexpected errors' => [self::response('{}', 500), self::response('{}', 500), [], 'failure GET /x'];
        yield 'equal errors other than the expected one' => [self::response('{}', 400), self::response('{}', 400), ['--expect', '404'], 'failure GET /x'];
        yield 'equal expected errors' => [self::response('{}', 404), self::response('{}', 404), ['--expect', '404'], 'match GET /x'];
        yield 'equal 200s where 404 is expected' => [self::response('{}'), self::response('{}'), ['--expect', '404'], 'failure GET /x'];
        yield 'no HTTP status' => ["curl: (7)\n\nVERIFY_STATUS=000\n", "curl: (7)\n\nVERIFY_STATUS=000\n", [], 'failure GET /x'];
    }

    /**
     * @param list<string> $flags
     */
    #[Test]
    #[DataProvider('responsePairs')]
    public function each_response_pair_gets_its_verdict(string $head, string $base, array $flags, string $verdict): void
    {
        $this->bothUp();
        $this->run->respond($this->run->project, $head);
        $this->run->respond("{$this->run->project}-base", $base);

        $parity = $this->run->verify('parity', ...[...$flags, 'GET', '/x']);

        $lines = \explode("\n", \mb_trim($parity->getOutput()));
        $this->assertMatchesRegularExpression('/^' . \preg_quote($verdict, '/') . '(?=[\s:]|$)/', $lines[0], $parity->getErrorOutput());
        $this->assertCount(\str_starts_with($verdict, 'difference GET /x .a[0]') ? 2 : 1, $lines);
        $this->assertSame(\str_starts_with($verdict, 'match') ? 0 : 1, $parity->getExitCode());
    }

    #[Test]
    public function it_compares_every_request_and_keeps_the_captures_of_both_runs(): void
    {
        $this->bothUp();
        $this->run->respond($this->run->project, self::response('{}'));
        $this->run->respond("{$this->run->project}-base", self::response('{}'));

        $parity = $this->run->verify('parity', 'GET', '/x', 'GET', '/y');

        $this->assertSame("match GET /x status 200\nmatch GET /y status 200\n", $parity->getOutput());
        $this->assertCount(2, \glob("{$this->run->evidence}/http/*.txt") ?: []);
        $this->assertCount(2, \glob("{$this->run->evidence}/baseline/http/*.txt") ?: []);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherMethods(): iterable
    {
        foreach (['HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            yield $method => [$method];
        }
    }

    #[Test]
    #[DataProvider('otherMethods')]
    public function a_method_other_than_get_sends_no_request(string $method): void
    {
        $this->bothUp();

        $parity = $this->run->verify('parity', 'GET', '/x', $method, '/y');

        $this->assertStringContainsString('only GET requests are compared', $parity->getErrorOutput());
        $this->assertSame(64, $parity->getExitCode());
        $this->assertSame('', $this->run->calls());
    }

    #[Test]
    public function it_exits_69_on_a_caller_supplied_supabase_run(): void
    {
        $this->run->headUp('caller-supabase');

        $parity = $this->run->verify('parity', 'GET', '/x');

        $this->assertStringContainsString('parity is not supported in caller-supabase mode', $parity->getErrorOutput());
        $this->assertSame(69, $parity->getExitCode());
    }

    #[Test]
    public function it_exits_75_without_a_base_run(): void
    {
        $this->run->headUp();

        $parity = $this->run->verify('parity', 'GET', '/x');

        $this->assertStringContainsString('no base run is up', $parity->getErrorOutput());
        $this->assertSame(75, $parity->getExitCode());
    }

    private static function response(string $body, int $status = 200): string
    {
        return "HTTP/1.1 {$status} X\r\nContent-Type: application/json\r\n\r\n{$body}\nVERIFY_STATUS={$status}\n";
    }

    private function bothUp(): void
    {
        $base = $this->run->git('rev-parse', 'HEAD');
        $this->run->commit(['change' => 'x']);
        $this->run->headUp();
        $this->run->baseUp($base);
    }
}

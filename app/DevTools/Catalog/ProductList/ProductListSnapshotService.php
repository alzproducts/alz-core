<?php

declare(strict_types=1);

namespace App\DevTools\Catalog\ProductList;

use Carbon\CarbonImmutable;
use Illuminate\Process\Factory as ProcessFactory;
use JsonException;
use RuntimeException;

/**
 * Captures every list shape body and every other-reader result into one labelled directory with a manifest.
 */
final readonly class ProductListSnapshotService
{
    private const int JSON_FLAGS = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;

    public function __construct(
        private InProcessApiClient $api,
        private ReaderSnapshotCapture $readers,
        private SnapshotStore $store,
        private ProcessFactory $process,
    ) {}

    /**
     * @return list<string> Names of the files written, manifest excluded
     *
     * @throws RuntimeException
     * @throws JsonException
     */
    public function capture(string $label): array
    {
        $this->store->reset($label);

        $uris = [...ProductListRequestShapes::PRODUCT_LIST, ProductListRequestShapes::VARIATIONS_SHAPE => ProductListRequestShapes::VARIATIONS_URI];

        foreach ($uris as $name => $uri) {
            $this->store->put($label, $name, $this->api->get($uri));
        }

        $readers = $this->readers->capture();

        foreach ($readers as $name => $result) {
            $this->store->put($label, $name, \json_encode($result, self::JSON_FLAGS));
        }

        $this->store->put($label, SnapshotStore::MANIFEST, \json_encode($this->manifest($label, $uris, \array_keys($readers)), self::JSON_FLAGS));

        return $this->store->names($label);
    }

    /**
     * @param array<string, string> $uris
     * @param list<string> $readers
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    private function manifest(string $label, array $uris, array $readers): array
    {
        return [
            'label' => $label,
            'git_sha' => $this->git(['rev-parse', 'HEAD']),
            'working_tree_dirty' => $this->git(['status', '--porcelain']) !== '',
            'captured_at' => CarbonImmutable::now()->toIso8601String(),
            'shapes' => $uris,
            'readers' => $readers,
            'detail_product_ids' => ProductListRequestShapes::DETAIL_PRODUCT_IDS,
        ];
    }

    /**
     * @param list<string> $arguments
     *
     * @throws RuntimeException When git fails
     */
    private function git(array $arguments): string
    {
        return \mb_trim($this->process->newPendingProcess()->path(\base_path())->run(['git', ...$arguments])->throw()->output());
    }
}

<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers;

use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serve product feeds via signed S3 URLs.
 *
 * Provides branded, permanent URLs for feed consumers (like DooFinder).
 * Validates prefix + GUID against config, then redirects to time-limited
 * signed S3 URL. This keeps actual S3 paths internal while providing
 * a stable public endpoint.
 */
final readonly class FeedController
{
    public function __construct(
        private FilesystemManager $filesystemManager,
    ) {}

    /**
     * Redirect to signed S3 URL for the requested feed.
     *
     * URL format: /feeds/{prefix}-{guid}.xml
     * Example: /feeds/doofinder-a1b2c3d4e5f6789012345678abcdef12.xml
     *
     * @throws NotFoundHttpException When feed config not found or file doesn't exist
     * @throws \RuntimeException When storage driver doesn't support temporary URLs (config error)
     */
    public function show(string $prefix, string $guid): RedirectResponse
    {
        // Find feed config matching both prefix and GUID
        $feedConfig = self::findFeedConfig($prefix, $guid);

        if ($feedConfig === null) {
            throw new NotFoundHttpException('Feed not found');
        }

        /** @var string $storageDisk */
        $storageDisk = $feedConfig['storage_disk'];

        /** @var string $storagePath */
        $storagePath = $feedConfig['storage_path'];

        $disk = $this->filesystemManager->disk($storageDisk);

        if (!$disk->exists($storagePath)) {
            throw new NotFoundHttpException('Feed not yet generated');
        }

        // Generate temporary signed URL (24 hours default)
        /** @var int $expiryMinutes */
        $expiryMinutes = $feedConfig['signed_url_expiry_minutes'] ?? 1440;

        // @phpstan-ignore staticMethod.dynamicCall (FilesystemAdapter has temporaryUrl but contract doesn't declare it)
        $signedUrl = $disk->temporaryUrl(
            $storagePath,
            \now()->addMinutes($expiryMinutes),
        );

        return new RedirectResponse($signedUrl);
    }

    /**
     * Find feed configuration matching prefix and GUID.
     *
     * @return array{storage_disk: string, storage_path: string, signed_url_expiry_minutes?: int}|null
     */
    private static function findFeedConfig(string $prefix, string $guid): ?array
    {
        /** @var array<string, mixed> $feeds */
        $feeds = \config('feeds', []);

        foreach ($feeds as $feed) {
            if (!\is_array($feed)) {
                continue;
            }

            $configPrefix = $feed['public_prefix'] ?? null;
            $configGuid = $feed['public_guid'] ?? null;

            if (($configPrefix === $prefix) && ($configGuid === $guid)) {
                // Validate required keys exist
                if (!isset($feed['storage_disk'], $feed['storage_path'])) {
                    continue;
                }

                /** @var array{storage_disk: string, storage_path: string, signed_url_expiry_minutes?: int} */
                return [
                    'storage_disk' => $feed['storage_disk'],
                    'storage_path' => $feed['storage_path'],
                    'signed_url_expiry_minutes' => $feed['signed_url_expiry_minutes'] ?? null,
                ];
            }
        }

        return null;
    }
}

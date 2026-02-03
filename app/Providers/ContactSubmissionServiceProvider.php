<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\HelpScout\ConversationWriteClientInterface;
use App\Infrastructure\HelpScout\HelpScoutClientFactory;
use App\Infrastructure\Ingest\ContactSubmission\Repositories\EloquentContactSubmissionActionRepository;
use App\Infrastructure\Ingest\ContactSubmission\Repositories\EloquentContactSubmissionRepository;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Contact Submission Service Provider.
 *
 * Deferred provider for contact form submission handling.
 * Binds repositories and the HelpScout conversation write client.
 */
final class ContactSubmissionServiceProvider extends ServiceProvider implements DeferrableProvider
{
    #[Override]
    public function register(): void
    {
        // Repository bindings
        $this->app->singleton(
            ContactSubmissionRepositoryInterface::class,
            EloquentContactSubmissionRepository::class,
        );

        $this->app->singleton(
            ContactSubmissionActionRepositoryInterface::class,
            EloquentContactSubmissionActionRepository::class,
        );

        // HelpScout write client for conversation creation
        $this->app->singleton(
            ConversationWriteClientInterface::class,
            static fn(): ConversationWriteClientInterface => HelpScoutClientFactory::createConversationWriteClient(),
        );
    }

    /**
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [
            ContactSubmissionRepositoryInterface::class,
            ContactSubmissionActionRepositoryInterface::class,
            ConversationWriteClientInterface::class,
        ];
    }
}

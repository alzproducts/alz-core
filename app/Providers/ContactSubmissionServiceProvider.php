<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\ContactSubmission\Transformers\ContactSubmissionToConversationCommandTransformer;
use App\Application\ContactSubmission\UseCases\ProcessContactSubmissionUseCase;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\EmailValidationServiceInterface;
use App\Application\Contracts\HelpScout\ConversationWriteClientInterface;
use App\Domain\ContactSubmission\Events\ContactFormProcessedEvent;
use App\Domain\ContactSubmission\Events\ContactFormProcessingFailedEvent;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\HelpScout\HelpScoutClientFactory;
use App\Infrastructure\Ingest\ContactSubmission\Repositories\EloquentContactSubmissionActionRepository;
use App\Infrastructure\Ingest\ContactSubmission\Repositories\EloquentContactSubmissionRepository;
use App\Infrastructure\Notifications\Listeners\ContactFormFailedSlackListener;
use App\Infrastructure\Notifications\Listeners\ContactFormProcessedSlackListener;
use App\Infrastructure\Validation\EmailValidationService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Override;
use Psr\Log\LoggerInterface;
use RuntimeException;

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

        // Email validation service
        $this->app->singleton(
            EmailValidationServiceInterface::class,
            EmailValidationService::class,
        );

        // UseCase with HelpScout system user ID for email validation notes
        $this->app->singleton(
            ProcessContactSubmissionUseCase::class,
            static function (Application $app): ProcessContactSubmissionUseCase {
                $configValue = \config('helpscout.system_user_id');
                $systemUserId = \is_numeric($configValue) ? (int) $configValue : 0;

                if ($systemUserId <= 0) {
                    throw new RuntimeException(
                        'HELPSCOUT_SYSTEM_USER_ID not configured. Get ID from: Help Scout → Manage → Users → Click user → ID in URL',
                    );
                }

                return new ProcessContactSubmissionUseCase(
                    submissionRepository: $app->make(ContactSubmissionRepositoryInterface::class),
                    actionRepository: $app->make(ContactSubmissionActionRepositoryInterface::class),
                    helpScoutClient: $app->make(ConversationWriteClientInterface::class),
                    transformer: $app->make(ContactSubmissionToConversationCommandTransformer::class),
                    emailValidator: $app->make(EmailValidationServiceInterface::class),
                    logger: $app->make(LoggerInterface::class),
                    helpScoutSystemUserId: IntId::from($systemUserId),
                );
            },
        );
    }

    /**
     * @throws RuntimeException If event registration fails
     */
    public function boot(): void
    {
        Event::listen(ContactFormProcessedEvent::class, ContactFormProcessedSlackListener::class);
        Event::listen(ContactFormProcessingFailedEvent::class, ContactFormFailedSlackListener::class);
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
            EmailValidationServiceInterface::class,
            ProcessContactSubmissionUseCase::class,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Clients;

use App\Application\Contracts\HelpScout\ConversationWriteClientInterface;
use App\Application\HelpScout\Commands\CreateConversationCommand;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Infrastructure\HelpScout\HelpScoutConfig;
use App\Infrastructure\HelpScout\Mappers\TagMapper;
use GuzzleHttp\Exception\ConnectException;
use HelpScout\Api\ApiClient;
use HelpScout\Api\Conversations\Conversation;
use HelpScout\Api\Conversations\Threads\CustomerThread;
use HelpScout\Api\Customers\Customer;
use HelpScout\Api\Exception\AuthenticationException;
use HelpScout\Api\Exception\ValidationErrorException;
use Illuminate\Support\Facades\Log;
use Override;
use RuntimeException;
use Throwable;

/**
 * HelpScout Conversations write operations client.
 *
 * Uses the SDK for conversation creation because:
 * - SDK serialization is clean for writes
 * - No hydration issues (we only get back a conversation ID)
 * - SDK handles OAuth2 token refresh
 *
 * @see https://developer.helpscout.com/mailbox-api/endpoints/conversations/create/
 */
final readonly class ConversationWriteClient implements ConversationWriteClientInterface
{
    private const string SERVICE_NAME = 'HelpScout';

    public function __construct(
        private ApiClient $sdkClient,
        private HelpScoutConfig $config,
    ) {}

    /**
     * @throws AuthenticationExpiredException When credentials invalid/expired
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiRequestException When request parameters invalid
     */
    #[Override]
    public function createConversation(CreateConversationCommand $command): int
    {
        $customer = $this->buildCustomer($command);
        $thread = $this->buildThread($customer, $command->body);
        $conversation = $this->buildConversation($command, $customer, $thread);

        try {
            $conversationId = $this->sdkClient->conversations()->create($conversation);

            if ($conversationId === null) {
                Log::error(self::SERVICE_NAME . ' create returned null conversation ID');
                throw new RuntimeException('HelpScout create returned null conversation ID');
            }

            return $conversationId;
        } catch (AuthenticationException $e) {
            Log::error(self::SERVICE_NAME . ' authentication failed during conversation creation', [
                'error' => $e->getMessage(),
            ]);
            throw new AuthenticationExpiredException(self::SERVICE_NAME, 'Authentication failed', $e);
        } catch (ValidationErrorException $e) {
            Log::error(self::SERVICE_NAME . ' validation error during conversation creation', [
                'error' => $e->getMessage(),
                'email' => $command->email,
            ]);
            throw new InvalidApiRequestException(self::SERVICE_NAME, $e->getMessage(), $e);
        } catch (ConnectException $e) {
            Log::error(self::SERVICE_NAME . ' connection failed during conversation creation', [
                'error' => $e->getMessage(),
            ]);
            throw new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
        } catch (Throwable $e) {
            Log::error(self::SERVICE_NAME . ' unexpected error during conversation creation', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
            throw new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
        }
    }

    /**
     * Build SDK Customer object from command.
     *
     * HelpScout auto-creates customers by email if they don't exist.
     */
    private function buildCustomer(CreateConversationCommand $command): Customer
    {
        [$firstName, $lastName] = self::splitName($command->name);

        $customer = new Customer();
        $customer->addEmail($command->email);
        $customer->setFirstName($firstName);
        $customer->setLastName($lastName);

        return $customer;
    }

    /**
     * Build SDK CustomerThread with the message body.
     */
    private function buildThread(Customer $customer, string $body): CustomerThread
    {
        $thread = new CustomerThread();
        $thread->setCustomer($customer);
        $thread->setText($body);

        return $thread;
    }

    /**
     * Build SDK Conversation with all components.
     */
    private function buildConversation(
        CreateConversationCommand $command,
        Customer $customer,
        CustomerThread $thread,
    ): Conversation {
        $conversation = new Conversation();
        $conversation->setMailboxId($this->config->getMailboxId($command->mailbox->value));
        $conversation->setType($command->type->value);
        $conversation->setSubject($command->subject);
        $conversation->setStatus($command->status->value);
        $conversation->setCustomer($customer);
        $conversation->addThread($thread);

        foreach (TagMapper::toSdkCollection($command->tags) as $sdkTag) {
            $conversation->addTag($sdkTag);
        }

        return $conversation;
    }

    /**
     * Split full name into first and last name.
     *
     * @return array{0: string, 1: string} [firstName, lastName]
     */
    private static function splitName(string $fullName): array
    {
        $parts = \explode(' ', $fullName, 2);
        $firstName = $parts[0];
        $lastName = $parts[1] ?? '';

        return [$firstName, $lastName];
    }
}

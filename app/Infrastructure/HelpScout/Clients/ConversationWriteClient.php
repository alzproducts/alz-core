<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Clients;

use App\Application\Contracts\HelpScout\ConversationWriteClientInterface;
use App\Application\HelpScout\Commands\CreateCustomerConversationCommand;
use App\Application\HelpScout\Requests\CreateCustomerRequestDTO;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Infrastructure\HelpScout\HelpScoutConfig;
use App\Infrastructure\HelpScout\Mappers\CustomerMapper;
use App\Infrastructure\HelpScout\Mappers\TagMapper;
use GuzzleHttp\Exception\ConnectException;
use HelpScout\Api\ApiClient;
use HelpScout\Api\Conversations\Conversation;
use HelpScout\Api\Conversations\Threads\CustomerThread;
use HelpScout\Api\Customers\Customer;
use HelpScout\Api\Exception\AuthenticationException;
use HelpScout\Api\Exception\ValidationErrorException;
use Illuminate\Support\Facades\Log;

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
        private CustomerMapper $customerMapper,
    ) {}

    /**
     * @throws AuthenticationExpiredException When credentials invalid/expired
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiRequestException When request parameters invalid
     * @throws UnexpectedApiResultException When API returns null conversation ID
     * @throws InsufficientDataException When customer has no email or phone
     */
    public function createConversationFromCustomer(CreateCustomerConversationCommand $command): int
    {
        $customerRequest = new CreateCustomerRequestDTO(
            email: $command->email,
            name: $command->name,
            phone: $command->phone,
        );

        $customer = $this->customerMapper->toSdk($customerRequest);
        $thread = $this->buildCustomerThread($customer, $command->body);
        $conversation = $this->buildConversation($command, $customer, $thread);

        return $this->executeCreate($conversation);
    }

    /**
     * Execute the SDK create call with exception translation.
     *
     * Caller should set Log::withContext() with relevant identifiers (e.g., submission_id)
     * before calling - this allows correlation without logging PII in Infrastructure.
     *
     * @throws AuthenticationExpiredException When credentials invalid/expired
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiRequestException When request parameters invalid
     * @throws UnexpectedApiResultException When API returns null conversation ID
     */
    private function executeCreate(Conversation $conversation): int
    {
        try {
            $conversationId = $this->sdkClient->conversations()->create($conversation);

            if ($conversationId === null) {
                Log::error(self::SERVICE_NAME . ' create returned null conversation ID');
                throw new UnexpectedApiResultException(self::SERVICE_NAME, 'Create returned null conversation ID');
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
            ]);
            throw new InvalidApiRequestException(self::SERVICE_NAME, $e->getMessage(), $e);
        } catch (ConnectException $e) {
            Log::error(self::SERVICE_NAME . ' connection failed during conversation creation', [
                'error' => $e->getMessage(),
            ]);
            throw new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
        }
    }

    /**
     * Build SDK CustomerThread for customer-initiated messages.
     */
    private function buildCustomerThread(Customer $customer, string $body): CustomerThread
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
        CreateCustomerConversationCommand $command,
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
}

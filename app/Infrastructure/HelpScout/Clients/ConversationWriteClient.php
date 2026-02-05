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
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\HelpScout\HelpScoutConfig;
use App\Infrastructure\HelpScout\Mappers\CustomerMapper;
use App\Infrastructure\HelpScout\Mappers\TagMapper;
use App\Infrastructure\HelpScout\Support\SdkExceptionTranslator;
use HelpScout\Api\ApiClient;
use HelpScout\Api\Conversations\Conversation;
use HelpScout\Api\Conversations\Threads\CustomerThread;
use HelpScout\Api\Conversations\Threads\NoteThread;
use HelpScout\Api\Customers\Customer;
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

        $conversationId = SdkExceptionTranslator::execute(
            fn(): ?int => $this->sdkClient->conversations()->create($conversation),
            'conversation creation',
        );

        if ($conversationId === null) {
            Log::error(self::SERVICE_NAME . ' create returned null conversation ID');
            throw new UnexpectedApiResultException(self::SERVICE_NAME, 'Create returned null conversation ID');
        }

        return $conversationId;
    }

    /**
     * @throws AuthenticationExpiredException When credentials invalid/expired
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiRequestException When request parameters invalid
     */
    public function addNoteToConversation(IntId $conversationId, string $noteText, IntId $userId): void
    {
        $thread = new NoteThread();
        $thread->setText($noteText);
        $thread->setUserId($userId->value);

        SdkExceptionTranslator::execute(
            fn() => $this->sdkClient->threads()->create($conversationId->value, $thread),
            'thread creation',
            ['conversation_id' => $conversationId->value],
        );
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

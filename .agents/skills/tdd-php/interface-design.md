# Interface Design for Testability

Good interfaces make testing natural:

1. **Accept dependencies, don't create them**

   ```php
   // Testable — dependencies injected via constructor
   final class ProcessOrderUseCase
   {
       public function __construct(
           private readonly PaymentClientInterface $paymentClient,
           private readonly OrderRepositoryInterface $orderRepository,
       ) {}

       public function execute(OrderCommand $command): OrderResult { /* ... */ }
   }

   // Hard to test — dependency created internally
   final class ProcessOrderUseCase
   {
       public function execute(OrderCommand $command): OrderResult
       {
           $client = new StripePaymentClient(config('services.stripe.key'));
           // ...
       }
   }
   ```

2. **Return results, don't produce side effects**

   ```php
   // Testable — pure function returning a value object
   public function calculateDiscount(Cart $cart): Discount
   {
       // ...
   }

   // Hard to test — mutates input, returns nothing
   public function applyDiscount(Cart $cart): void
   {
       $cart->total -= $this->resolveDiscountAmount($cart);
   }
   ```

3. **Small surface area**
   - Fewer methods = fewer tests needed
   - Fewer parameters = simpler test setup
   - Prefer specific value objects over `array $params` — typed inputs document intent and let PHPStan catch shape mismatches before tests run

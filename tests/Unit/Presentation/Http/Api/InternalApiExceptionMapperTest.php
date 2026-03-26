<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Http\Api;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\DomainException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Presentation\Http\Api\InternalApiExceptionMapper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\LaravelData\Exceptions\CannotCreateData;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

#[CoversClass(InternalApiExceptionMapper::class)]
final class InternalApiExceptionMapperTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Non-JSON requests / non-API routes
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function render_returns_null_for_non_json_request(): void
    {
        $request = Request::create('/api/products', 'GET');
        $exception = new RuntimeException('Something broke');

        $result = InternalApiExceptionMapper::render($exception, $request);

        $this->assertNull($result);
    }

    #[Test]
    public function render_returns_null_for_non_api_json_request(): void
    {
        $request = Request::create('/horizon/api/stats', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
        $exception = new HttpException(401, 'Unauthorized');

        $result = InternalApiExceptionMapper::render($exception, $request);

        $this->assertNull($result);
    }

    /*
    |--------------------------------------------------------------------------
    | Domain exceptions
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function render_maps_validation_failed_exception_to_422(): void
    {
        $exception = new ValidationFailedException('Name is required');
        $request = $this->jsonRequest();

        $response = InternalApiExceptionMapper::render($exception, $request);

        $this->assertNotNull($response);
        $this->assertSame(422, $response->getStatusCode());
        $body = $response->getData(assoc: true);
        $this->assertSame('validation_error', $body['error']['type']);
        $this->assertSame('Name is required', $body['error']['message']);
    }

    #[Test]
    public function render_includes_context_in_errors_for_validation_failed_exception(): void
    {
        $context = ['name' => ['Name is required.']];
        $exception = new ValidationFailedException('Validation failed', $context);
        $request = $this->jsonRequest();

        $response = InternalApiExceptionMapper::render($exception, $request);

        $this->assertNotNull($response);
        $body = $response->getData(assoc: true);
        $this->assertSame($context, $body['error']['errors']);
    }

    #[Test]
    public function render_maps_resource_not_found_exception_to_404(): void
    {
        $exception = new ResourceNotFoundException('shopwired', 'Product', 42);
        $request = $this->jsonRequest();

        $response = InternalApiExceptionMapper::render($exception, $request);

        $this->assertNotNull($response);
        $this->assertSame(404, $response->getStatusCode());
        $body = $response->getData(assoc: true);
        $this->assertSame('not_found', $body['error']['type']);
    }

    #[Test]
    public function render_maps_duplicate_record_exception_to_409_with_fixed_message(): void
    {
        $exception = new DuplicateRecordException('products', 'products_sku_unique');
        $request = $this->jsonRequest();

        $response = InternalApiExceptionMapper::render($exception, $request);

        $this->assertNotNull($response);
        $this->assertSame(409, $response->getStatusCode());
        $body = $response->getData(assoc: true);
        $this->assertSame('conflict', $body['error']['type']);
        $this->assertSame('A conflicting record already exists.', $body['error']['message']);
    }

    #[Test]
    public function render_maps_transient_api_failure_to_503_with_fixed_message(): void
    {
        $exception = new ExternalServiceUnavailableException('shopwired');
        $request = $this->jsonRequest();

        $response = InternalApiExceptionMapper::render($exception, $request);

        $this->assertNotNull($response);
        $this->assertSame(503, $response->getStatusCode());
        $body = $response->getData(assoc: true);
        $this->assertSame('service_unavailable', $body['error']['type']);
        $this->assertSame('The service is temporarily unavailable. Please try again shortly.', $body['error']['message']);
    }

    #[Test]
    public function render_maps_lock_acquisition_exception_to_503_with_fixed_message(): void
    {
        $exception = new LockAcquisitionException('sku-generator', 5);
        $request = $this->jsonRequest();

        $response = InternalApiExceptionMapper::render($exception, $request);

        $this->assertNotNull($response);
        $this->assertSame(503, $response->getStatusCode());
        $body = $response->getData(assoc: true);
        $this->assertSame('service_unavailable', $body['error']['type']);
        $this->assertSame('The service is temporarily unavailable. Please try again shortly.', $body['error']['message']);
    }

    #[Test]
    public function render_maps_resource_not_found_permanent_api_failure_to_404(): void
    {
        $exception = new ResourceNotFoundException('shopwired', 'Order', 99);
        $request = $this->jsonRequest();

        $response = InternalApiExceptionMapper::render($exception, $request);

        $this->assertNotNull($response);
        $this->assertSame(404, $response->getStatusCode());
        $body = $response->getData(assoc: true);
        $this->assertSame('not_found', $body['error']['type']);
    }

    #[Test]
    public function render_maps_generic_domain_exception_to_500_with_exception_message(): void
    {
        $exception = new class ('Business rule violated') extends DomainException {};
        $request = $this->jsonRequest();

        $response = InternalApiExceptionMapper::render($exception, $request);

        $this->assertNotNull($response);
        $this->assertSame(500, $response->getStatusCode());
        $body = $response->getData(assoc: true);
        $this->assertSame('server_error', $body['error']['type']);
        $this->assertSame('Business rule violated', $body['error']['message']);
    }

    /*
    |--------------------------------------------------------------------------
    | Laravel / Symfony HTTP exceptions
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function render_maps_laravel_validation_exception_to_422_with_errors(): void
    {
        $validator = Validator::make(
            ['name' => ''],
            ['name' => 'required'],
        );
        $exception = new ValidationException($validator);
        $request = $this->jsonRequest();

        $response = InternalApiExceptionMapper::render($exception, $request);

        $this->assertNotNull($response);
        $this->assertSame(422, $response->getStatusCode());
        $body = $response->getData(assoc: true);
        $this->assertSame('validation_error', $body['error']['type']);
        $this->assertArrayHasKey('errors', $body['error']);
    }

    #[Test]
    public function render_maps_not_found_http_exception_to_404(): void
    {
        $exception = new NotFoundHttpException('Route not found');
        $request = $this->jsonRequest();

        $response = InternalApiExceptionMapper::render($exception, $request);

        $this->assertNotNull($response);
        $this->assertSame(404, $response->getStatusCode());
        $body = $response->getData(assoc: true);
        $this->assertSame('not_found', $body['error']['type']);
        $this->assertSame('Route not found', $body['error']['message']);
    }

    #[Test]
    public function render_maps_method_not_allowed_http_exception_to_405(): void
    {
        $exception = new MethodNotAllowedHttpException(['GET'], 'Method not allowed');
        $request = $this->jsonRequest();

        $response = InternalApiExceptionMapper::render($exception, $request);

        $this->assertNotNull($response);
        $this->assertSame(405, $response->getStatusCode());
        $body = $response->getData(assoc: true);
        $this->assertSame('method_not_allowed', $body['error']['type']);
    }

    #[Test]
    public function render_maps_generic_http_exception_using_its_status_code(): void
    {
        $exception = new HttpException(418, "I'm a teapot");
        $request = $this->jsonRequest();

        $response = InternalApiExceptionMapper::render($exception, $request);

        $this->assertNotNull($response);
        $this->assertSame(418, $response->getStatusCode());
        $body = $response->getData(assoc: true);
        $this->assertSame("I'm a teapot", $body['error']['message']);
    }

    #[Test]
    public function render_maps_cannot_create_data_to_422_with_fixed_message(): void
    {
        $exception = new CannotCreateData('Could not create data object');
        $request = $this->jsonRequest();

        $response = InternalApiExceptionMapper::render($exception, $request);

        $this->assertNotNull($response);
        $this->assertSame(422, $response->getStatusCode());
        $body = $response->getData(assoc: true);
        $this->assertSame('validation_error', $body['error']['type']);
        $this->assertSame('The request data could not be processed.', $body['error']['message']);
    }

    /*
    |--------------------------------------------------------------------------
    | Unknown / generic exceptions
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function render_maps_unknown_exception_to_500_with_generic_fallback_message(): void
    {
        $exception = new RuntimeException('Internal secret details');
        $request = $this->jsonRequest();

        $response = InternalApiExceptionMapper::render($exception, $request);

        $this->assertNotNull($response);
        $this->assertSame(500, $response->getStatusCode());
        $body = $response->getData(assoc: true);
        $this->assertSame('server_error', $body['error']['type']);
        $this->assertSame('An unexpected error occurred.', $body['error']['message']);
    }

    /*
    |--------------------------------------------------------------------------
    | PermanentApiFailure (concrete subtype that is 502)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function render_maps_permanent_api_failure_subtype_to_502(): void
    {
        // Create a concrete PermanentApiFailure that is not a ResourceNotFoundException
        $exception = new class ('test-service', 'Something permanently broken') extends PermanentApiFailure {};
        $request = $this->jsonRequest();

        $response = InternalApiExceptionMapper::render($exception, $request);

        $this->assertNotNull($response);
        $this->assertSame(502, $response->getStatusCode());
        $body = $response->getData(assoc: true);
        $this->assertSame('upstream_error', $body['error']['type']);
        $this->assertSame('An upstream service encountered an error.', $body['error']['message']);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function jsonRequest(): Request
    {
        return Request::create('/api/test', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
    }
}

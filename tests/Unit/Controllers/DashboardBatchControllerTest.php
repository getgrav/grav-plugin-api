<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Controllers\DashboardBatchController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Response\ErrorResponse;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /admin-next/dashboard runs each part's own endpoint handler with the
 * incoming request, keeps its `data`, and reports a part that is refused or
 * fails in `errors` without taking the rest down. The handlers are stood in
 * for here (the real notifications and feed handlers go to getgrav.org).
 */
#[CoversClass(DashboardBatchController::class)]
class DashboardBatchControllerTest extends TestCase
{
    private Config $config;

    protected function setUp(): void
    {
        $this->config = new Config(['plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']]]);
        TestHelper::createMockGrav(['config' => $this->config, 'permissions' => new Permissions()]);
    }

    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    #[Test]
    public function each_part_is_the_data_of_its_handler_with_its_own_query(): void
    {
        $controller = new DashboardTestBatchController(Grav::instance(), $this->config);
        $response = $controller->show($this->request($this->user(), ['unrelated' => 'x']));
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['system_info', 'notifications', 'popularity', 'feed', 'backups', 'updates', 'reports', 'widgets', 'recent_pages'],
            array_keys($decoded['data'])
        );
        self::assertSame(['handler' => 'info', 'query' => []], $decoded['data']['system_info']);
        self::assertSame(['handler' => 'systemInfo', 'query' => []], $decoded['data']['reports']);
        // The dashboard's own query parameters never reach a part.
        self::assertSame(
            ['handler' => 'index', 'query' => ['sort' => 'modified', 'order' => 'desc', 'per_page' => '8', 'fields' => 'summary']],
            $decoded['data']['recent_pages']
        );
        self::assertSame([], $decoded['errors']);
        self::assertStringEndsWith(',"errors":{}}', $body, 'errors is an object even when empty');
    }

    #[Test]
    public function recent_pages_editable_lists_only_editable_pages(): void
    {
        $controller = new DashboardTestBatchController(Grav::instance(), $this->config);
        $data = json_decode((string) $controller->show($this->request($this->user(), ['recent_pages' => 'editable']))->getBody(), true)['data'];

        self::assertSame('true', $data['recent_pages']['query']['editable']);
    }

    #[Test]
    public function a_refused_or_failing_part_goes_to_errors_and_the_rest_still_arrive(): void
    {
        $controller = new DashboardTestBatchController(Grav::instance(), $this->config);
        $controller->refuse = 'backups';
        $controller->fail = 'feed';
        $controller->errorStatus = 'updates';

        $response = $controller->show($this->request($this->user()));
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'feed' => ['status' => 500, 'title' => 'Internal Server Error'],
            'backups' => ['status' => 403, 'title' => 'Forbidden'],
            'updates' => ['status' => 503, 'title' => 'Service Unavailable'],
        ], $body['errors']);
        foreach (['feed', 'backups', 'updates'] as $part) {
            self::assertArrayNotHasKey($part, $body['data'], $part);
        }
        self::assertCount(6, $body['data']);
    }

    #[Test]
    public function a_caller_without_api_access_is_refused_outright(): void
    {
        $this->expectException(ForbiddenException::class);

        $user = TestHelper::createMockUser('nobody', ['access' => ['api' => ['access' => false]]]);
        (new DashboardTestBatchController(Grav::instance(), $this->config))->show($this->request($user));
    }

    private function user(): UserInterface
    {
        return TestHelper::createMockUser('editor', ['access' => ['api' => ['access' => true]]]);
    }

    /**
     * @param array<string, string> $query
     */
    private function request(UserInterface $user, array $query = []): ServerRequestInterface
    {
        return TestHelper::createMockRequest(queryParams: $query, attributes: ['api_user' => $user]);
    }
}

/**
 * Every part's controller is the stand-in below; which part should be refused
 * or fail is set on the batch controller.
 */
final class DashboardTestBatchController extends DashboardBatchController
{
    public ?string $refuse = null;
    public ?string $fail = null;
    public ?string $errorStatus = null;

    private const HANDLER_PART = [
        'info' => 'system_info', 'notifications' => 'notifications', 'popularity' => 'popularity',
        'feed' => 'feed', 'backups' => 'backups', 'updates' => 'updates', 'systemInfo' => 'reports',
        'widgets' => 'widgets', 'index' => 'recent_pages',
    ];

    protected function controller(string $class): AbstractApiController
    {
        $part = new DashboardTestPartController($this->grav, $this->config);
        $part->decide = function (string $handler): ?string {
            $name = self::HANDLER_PART[$handler];

            return match ($name) {
                $this->refuse => 'refuse',
                $this->fail => 'fail',
                $this->errorStatus => 'error',
                default => null,
            };
        };

        return $part;
    }
}

final class DashboardTestPartController extends AbstractApiController
{
    /** @var \Closure(string): ?string */
    public \Closure $decide;

    /** @param array<int, mixed> $args */
    public function __call(string $handler, array $args): ResponseInterface
    {
        /** @var ServerRequestInterface $request */
        $request = $args[0];

        return match (($this->decide)($handler)) {
            'refuse' => throw new ForbiddenException('Not for you.'),
            'fail' => throw new \RuntimeException('Remote feed unreachable.'),
            'error' => ErrorResponse::create(503, 'Service Unavailable', 'GPM repository unreachable.'),
            default => ApiResponse::create(['handler' => $handler, 'query' => $request->getQueryParams()]),
        };
    }
}

<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Plugin\Api\Controllers\ConfigController;
use Grav\Plugin\Api\Tests\Unit\Services\ShadowsFixture;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Config responses carry `meta.shadowed`: values a layer above the write target
 * decides. With "Default" (base) selected while the site boots under a
 * hostname overlay that sets `enabled: false`, saving `enabled: true` to base
 * changes nothing, and the response now says so
 * (getgrav/grav-plugin-sync-mercure#1). Removing the override is a revert
 * against the environment, which works without a prior GET for its ETag.
 */
#[CoversClass(ConfigController::class)]
class ConfigControllerShadowedMetaTest extends TestCase
{
    private ShadowsFixture $site;

    private string $envFile;

    protected function setUp(): void
    {
        $this->site = new ShadowsFixture();
        $this->site->write('user/plugins/mercure/mercure.yaml', "enabled: true\nhub: https://hub.test\n");
        $this->site->write('user/config/plugins/mercure.yaml', "enabled: true\n");
        $this->envFile = 'user/env/example.com/config/plugins/mercure.yaml';
        $this->site->write($this->envFile, "enabled: false\n");
        $this->site->bootEnvironment('example.com');
    }

    protected function tearDown(): void
    {
        $this->site->cleanup();
    }

    #[Test]
    public function show_for_base_lists_the_environment_value_that_wins(): void
    {
        $meta = $this->meta($this->controller()->show($this->request('GET', 'default')));

        self::assertCount(1, $meta['shadowed']);
        self::assertSame('enabled', $meta['shadowed'][0]['path']);
        self::assertSame('environment', $meta['shadowed'][0]['source']);
        self::assertSame('example.com', $meta['shadowed'][0]['environment']);
        self::assertStringEndsWith($this->envFile, $meta['shadowed'][0]['file']);
        self::assertFalse($meta['shadowed'][0]['value']);
        // The existing override metadata is untouched.
        self::assertArrayHasKey('overrides', $meta);
        self::assertArrayHasKey('fallback', $meta);
    }

    #[Test]
    public function show_for_the_environment_itself_lists_nothing(): void
    {
        $meta = $this->meta($this->controller()->show($this->request('GET', 'example.com')));

        self::assertSame([], $meta['shadowed']);
        self::assertSame(['enabled'], $meta['overrides']);
    }

    #[Test]
    public function show_without_the_header_targets_the_active_environment_and_lists_nothing(): void
    {
        $meta = $this->meta($this->controller()->show($this->request('GET', null)));

        self::assertSame([], $meta['shadowed']);
    }

    #[Test]
    public function shadowed_is_an_empty_list_in_json_when_nothing_is_shadowed(): void
    {
        $response = $this->controller()->show($this->request('GET', 'example.com'));

        self::assertStringContainsString('"shadowed":[]', (string) $response->getBody());
    }

    #[Test]
    public function reverting_the_environment_key_without_if_match_removes_the_override(): void
    {
        $response = $this->controller()->revert(
            $this->request('POST', 'example.com', ['keys' => ['enabled']], '/revert'),
        );

        self::assertSame(200, $response->getStatusCode());
        $meta = $this->meta($response);
        self::assertTrue($meta['reverted']);
        self::assertSame([], $meta['shadowed']);
        // The file emptied out, so it is gone; the env folder stays.
        self::assertFileDoesNotExist($this->site->root . '/' . $this->envFile);
        self::assertDirectoryExists($this->site->root . '/user/env/example.com/config');

        $after = $this->meta($this->controller()->show($this->request('GET', 'default')));
        self::assertSame([], $after['shadowed']);
    }

    #[Test]
    public function revert_against_base_still_reports_the_environment_shadow(): void
    {
        $response = $this->controller()->revert(
            $this->request('POST', 'default', ['keys' => ['enabled']], '/revert'),
        );

        self::assertSame(['enabled'], array_column($this->meta($response)['shadowed'], 'path'));
    }

    private function controller(): ConfigController
    {
        $grav = $this->site->grav(['plugins' => ['mercure' => ['enabled' => false, 'hub' => 'https://hub.test']]]);

        return new ConfigController($grav, $grav['config']);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, ?string $environment, ?array $body = null, string $suffix = ''): ServerRequestInterface
    {
        $attributes = [
            'api_user' => TestHelper::createMockUser('root', ['access' => ['api' => ['super' => true]]]),
            'route_params' => ['scope' => 'plugins/mercure'],
        ];
        if ($body !== null) {
            $attributes['json_body'] = $body;
        }

        return TestHelper::createMockRequest(
            $method,
            '/config/plugins/mercure' . $suffix,
            headers: $environment !== null ? ['X-Config-Environment' => $environment] : [],
            attributes: $attributes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true)['meta'];
    }
}

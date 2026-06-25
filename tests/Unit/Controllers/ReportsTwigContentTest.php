<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Controllers\ReportsController;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 of the "Twig in Content" streamline plan: the api plugin's report and
 * the "Add to allowlist" action.
 *
 * The report-building path needs a full Grav + Pages and is covered by the live
 * smoke test; here we cover the allowlist mutation logic (the part with the
 * fiddly merge-by-position / comma-string handling) and the super-admin guard.
 */
#[CoversClass(ReportsController::class)]
class ReportsTwigContentTest extends TestCase
{
    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    private function controller(): ReportsController
    {
        Grav::resetInstance();
        return new ReportsController(Grav::instance(), new Config());
    }

    private function invoke(ReportsController $c, string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod($c, $method);
        $ref->setAccessible(true);
        return $ref->invoke($c, ...$args);
    }

    // -- appendToList (tags/filters/functions) --------------------------------

    #[Test]
    public function append_to_list_adds_new_token(): void
    {
        $out = $this->invoke($this->controller(), 'appendToList', [['upper', 'lower'], 'date']);
        self::assertSame(['upper', 'lower', 'date'], $out);
    }

    #[Test]
    public function append_to_list_dedups_case_insensitively(): void
    {
        $out = $this->invoke($this->controller(), 'appendToList', [['Upper', 'lower'], 'upper']);
        self::assertSame(['Upper', 'lower'], $out);
    }

    #[Test]
    public function append_to_list_skips_non_string_entries(): void
    {
        $out = $this->invoke($this->controller(), 'appendToList', [['upper', 42, null], 'date']);
        self::assertSame(['upper', 'date'], $out);
    }

    // -- appendToMethodMap (methods/properties) -------------------------------

    #[Test]
    public function append_to_method_map_appends_to_matching_class_row(): void
    {
        $rows = [
            ['class' => 'Grav\\Common\\Page\\Medium\\Medium', 'methods' => 'url, html'],
            ['class' => 'Grav\\Common\\Uri', 'methods' => 'path'],
        ];
        $out = $this->invoke($this->controller(), 'appendToMethodMap', [$rows, 'Grav\\Common\\Page\\Medium\\Medium', 'lightbox']);

        self::assertSame('url, html, lightbox', $out[0]['methods']);
        self::assertSame('path', $out[1]['methods']);
    }

    #[Test]
    public function append_to_method_map_dedups(): void
    {
        $rows = [['class' => 'Grav\\Common\\Uri', 'methods' => 'path, url']];
        $out = $this->invoke($this->controller(), 'appendToMethodMap', [$rows, 'Grav\\Common\\Uri', 'URL']);
        self::assertSame('path, url', $out[0]['methods']);
    }

    #[Test]
    public function append_to_method_map_adds_new_row_for_unknown_class(): void
    {
        $rows = [['class' => 'Grav\\Common\\Uri', 'methods' => 'path']];
        $out = $this->invoke($this->controller(), 'appendToMethodMap', [$rows, 'My\\Plugin\\Gallery', 'render']);

        self::assertCount(2, $out);
        self::assertSame(['class' => 'My\\Plugin\\Gallery', 'methods' => 'render'], $out[1]);
    }

    // -- allowlistDescriptor --------------------------------------------------

    #[Test]
    public function descriptor_is_null_for_gate_and_xss_events(): void
    {
        $c = $this->controller();
        self::assertNull($this->invoke($c, 'allowlistDescriptor', [['type' => 'gate_blocked', 'token' => 'content', 'class' => '']]));
        self::assertNull($this->invoke($c, 'allowlistDescriptor', [['type' => 'xss_blanked', 'token' => '<script>', 'class' => '']]));
    }

    #[Test]
    public function descriptor_maps_function_event_to_list_key(): void
    {
        $d = $this->invoke($this->controller(), 'allowlistDescriptor', [['type' => 'sandbox_function', 'token' => 'read_file', 'class' => '']]);
        self::assertSame('function', $d['rule']);
        self::assertSame('allowed_functions', $d['key']);
        self::assertSame('list', $d['kind']);
        self::assertSame('read_file', $d['token']);
    }

    #[Test]
    public function descriptor_requires_class_for_method_event(): void
    {
        $c = $this->controller();
        // Missing class → no actionable descriptor.
        self::assertNull($this->invoke($c, 'allowlistDescriptor', [['type' => 'sandbox_method', 'token' => 'save', 'class' => '']]));
        // With class → map descriptor.
        $d = $this->invoke($c, 'allowlistDescriptor', [['type' => 'sandbox_method', 'token' => 'save', 'class' => 'Acme\\X']]);
        self::assertSame('allowed_methods', $d['key']);
        self::assertSame('map', $d['kind']);
        self::assertSame('Acme\\X', $d['class']);
    }

    // -- allowlistAdd guards --------------------------------------------------

    #[Test]
    public function allowlist_add_is_forbidden_for_non_super(): void
    {
        // requirePermission(api.config.write) passes for super via short-circuit
        // but needs the live Permissions service for a non-super resolve, which
        // the bare test Grav doesn't wire. So assert the super-admin boundary at
        // the layer that matters: isSuperAdmin() gates the mutation. (The full
        // requirePermission round-trip for security writes is covered by
        // ConfigControllerPrivilegedScopeTest.)
        $controller = $this->controller();
        $isSuper = new \ReflectionMethod($controller, 'isSuperAdmin');
        $isSuper->setAccessible(true);

        self::assertFalse($isSuper->invoke($controller, $this->nonSuper()));
        self::assertTrue($isSuper->invoke($controller, $this->super()));
    }

    #[Test]
    public function allowlist_add_rejects_unknown_rule_for_super(): void
    {
        $controller = $this->controller();
        $request = TestHelper::createMockRequest(
            'POST',
            '/reports/twig-content/allowlist',
            body: json_encode(['rule' => 'bogus', 'token' => 'x']),
            attributes: ['api_user' => $this->super()],
        );

        $this->expectException(ValidationException::class);
        $controller->allowlistAdd($request);
    }

    private function nonSuper(): UserInterface
    {
        return TestHelper::createMockUser('config-admin', [
            'access' => ['api' => ['access' => true, 'config' => ['write' => true]]],
        ]);
    }

    private function super(): UserInterface
    {
        return TestHelper::createMockUser('root', ['access.api.super' => true]);
    }
}

<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\UsersController;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RocketTheme\Toolbox\Event\Event;

/**
 * Covers the Users-list custom columns contract (getgrav/grav-plugin-admin2#111):
 * the server-side guards that keep plugin-declared columns safe — authorize
 * re-checks, the formatter whitelist, field/value sanitization, scalar-only data,
 * size caps — and the batched, isolated column-data merge that must never let a
 * misbehaving plugin break the users list.
 */
#[CoversClass(UsersController::class)]
class UsersControllerColumnsTest extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];

    private function controller(array $configData = [], bool $withEvents = false): UsersController
    {
        $config = new Config(array_merge_recursive([
            'plugins' => ['api' => [
                'route' => '/api',
                'version_prefix' => 'v1',
                'pagination' => ['default_per_page' => 20, 'max_per_page' => 100],
            ]],
        ], $configData));

        $services = [
            'config'      => $config,
            'permissions' => new Permissions(),
        ];

        if ($withEvents) {
            $this->warnings = [];
            $services['log'] = new class ($this->warnings) {
                /** @var list<string> */
                private array $sink;
                /** @param list<string> $sink */
                public function __construct(array &$sink) { $this->sink = &$sink; }
                public function warning(string $message): void { $this->sink[] = $message; }
            };
        }

        TestHelper::createMockGrav($services);

        return new UsersController(\Grav\Common\Grav::instance(), $config);
    }

    /**
     * @param array<int, mixed> $contributed
     * @return array<int, array<string, mixed>>
     */
    private function assembleColumns(UsersController $c, array $contributed, UserInterface $user): array
    {
        $m = new ReflectionMethod($c, 'assembleColumns');
        $m->setAccessible(true);
        return $m->invoke($c, new Event(['columns' => $contributed]), $user);
    }

    /**
     * @param array<mixed, mixed> $extra
     * @return array<string, mixed>
     */
    private function sanitize(UsersController $c, array $extra): array
    {
        $m = new ReflectionMethod($c, 'sanitizeColumnValues');
        $m->setAccessible(true);
        return $m->invoke($c, $extra);
    }

    /**
     * @param array<int, array<string, mixed>> $data
     * @return array<int, array<string, mixed>>
     */
    private function applyColumnData(UsersController $c, array $data, UserInterface $user): array
    {
        $m = new ReflectionMethod($c, 'applyColumnData');
        $m->setAccessible(true);
        return $m->invoke($c, $data, $user);
    }

    #[Test]
    public function drops_malformed_columns(): void
    {
        $c = $this->controller();
        $user = TestHelper::createMockUser('anyone', []);

        $columns = $this->assembleColumns($c, [
            ['id' => '', 'field' => 'a'],                 // empty id — dropped
            ['field' => 'b'],                             // no id — dropped
            'not-an-array',                               // not an array — dropped
            ['id' => 'no-field'],                         // no field — dropped
            ['id' => 'blank-field', 'field' => '  '],     // field sanitizes to empty — dropped
            ['id' => 'real', 'field' => 'ok'],
        ], $user);

        $this->assertSame(['real'], array_column($columns, 'id'));
    }

    #[Test]
    public function whitelists_the_formatter_and_defaults_unknown_to_text(): void
    {
        $c = $this->controller();
        $user = TestHelper::createMockUser('anyone', []);

        $columns = $this->assembleColumns($c, [
            ['id' => 'a', 'field' => 'a', 'formatter' => 'datetime'],
            ['id' => 'b', 'field' => 'b', 'formatter' => 'script'],   // not whitelisted
            ['id' => 'c', 'field' => 'c'],                            // missing
        ], $user);

        $byId = array_column($columns, 'formatter', 'id');
        $this->assertSame('datetime', $byId['a']);
        $this->assertSame('text', $byId['b']);
        $this->assertSame('text', $byId['c']);
    }

    #[Test]
    public function sanitizes_the_field_key_charset(): void
    {
        $c = $this->controller();
        $user = TestHelper::createMockUser('anyone', []);

        $columns = $this->assembleColumns($c, [
            ['id' => 'ok', 'field' => 'sub.valid_till'],
            ['id' => 'dirty', 'field' => 'a/../b<script>'],
        ], $user);

        $byId = array_column($columns, 'field', 'id');
        $this->assertSame('sub.valid_till', $byId['ok']);
        $this->assertSame('a..bscript', $byId['dirty']);
    }

    #[Test]
    public function drops_unauthorized_columns_and_strips_the_authorize_field(): void
    {
        $c = $this->controller();
        // Has api.users.read but not api.super.
        $user = TestHelper::createMockUser('editor', [
            'access' => ['api' => ['users' => ['read' => true]]],
        ]);

        $columns = $this->assembleColumns($c, [
            ['id' => 'allowed', 'field' => 'a', 'authorize' => 'api.users.read'],
            ['id' => 'denied', 'field' => 'b', 'authorize' => 'api.super'],
            ['id' => 'either', 'field' => 'c', 'authorize' => ['api.super', 'api.users.read']],
        ], $user);

        $this->assertSame(['allowed', 'either'], array_column($columns, 'id'));
        foreach ($columns as $column) {
            $this->assertArrayNotHasKey('authorize', $column);
        }
    }

    #[Test]
    public function orders_by_descending_priority(): void
    {
        $c = $this->controller();
        $user = TestHelper::createMockUser('anyone', []);

        $columns = $this->assembleColumns($c, [
            ['id' => 'low', 'field' => 'a', 'priority' => 1],
            ['id' => 'high', 'field' => 'b', 'priority' => 50],
            ['id' => 'mid', 'field' => 'c', 'priority' => 10],
        ], $user);

        $this->assertSame(['high', 'mid', 'low'], array_column($columns, 'id'));
    }

    #[Test]
    public function first_declaration_of_a_duplicate_id_wins(): void
    {
        $c = $this->controller();
        $user = TestHelper::createMockUser('anyone', []);

        $columns = $this->assembleColumns($c, [
            ['id' => 'dup', 'field' => 'first'],
            ['id' => 'dup', 'field' => 'second'],
        ], $user);

        $this->assertCount(1, $columns);
        $this->assertSame('first', $columns[0]['field']);
    }

    #[Test]
    public function sanitize_keeps_scalars_and_null_but_rejects_arrays_and_objects(): void
    {
        $c = $this->controller();

        $clean = $this->sanitize($c, [
            'name' => 'Alice',
            'count' => 42,
            'ratio' => 1.5,
            'active' => true,
            'missing' => null,
            'blob' => ['nested' => 'no'],       // array — dropped
            'obj' => new \stdClass(),            // object — dropped
            0 => 'non-string-key',              // non-string key — dropped
        ]);

        $this->assertSame(
            ['name' => 'Alice', 'count' => 42, 'ratio' => 1.5, 'active' => true, 'missing' => null],
            $clean,
        );
    }

    #[Test]
    public function sanitize_enforces_field_count_and_value_length_caps(): void
    {
        $c = $this->controller();

        $many = [];
        for ($i = 0; $i < 50; $i++) {
            $many["f$i"] = "v$i";
        }
        $many['huge'] = str_repeat('x', 5000);

        $clean = $this->sanitize($c, $many);

        $this->assertLessThanOrEqual(32, count($clean));
        foreach ($clean as $value) {
            $this->assertLessThanOrEqual(2048, strlen((string) $value));
        }
    }

    #[Test]
    public function apply_column_data_no_ops_on_an_empty_page(): void
    {
        $c = $this->controller(withEvents: true);
        $user = TestHelper::createMockUser('root', ['access' => ['api' => ['super' => true]]]);

        $this->assertSame([], $this->applyColumnData($c, [], $user));
    }

    #[Test]
    public function apply_column_data_merges_scalar_values_scoped_to_the_page(): void
    {
        $c = $this->controller(withEvents: true);
        $user = TestHelper::createMockUser('root', ['access' => ['api' => ['super' => true]]]);

        \Grav\Common\Grav::instance()->addListener(
            'onApiUserListColumnData',
            static function (Event $event): void {
                // Only the page's usernames are offered to the plugin.
                $event['data'] = [
                    'alice' => ['valid_till' => '2026-01-01', 'blob' => ['x' => 1]],
                    // 'carol' is not on this page — ignored on merge.
                    'carol' => ['valid_till' => 'nope'],
                ];
            },
        );

        $page = [
            ['username' => 'alice'],
            ['username' => 'bob'],
        ];

        $result = $this->applyColumnData($c, $page, $user);

        // Scalar merged for alice; non-scalar blob stripped by sanitization.
        $this->assertSame(['valid_till' => '2026-01-01'], $result[0]['extra']);
        // No data offered for bob → no extra key at all.
        $this->assertArrayNotHasKey('extra', $result[1]);
    }

    #[Test]
    public function apply_column_data_isolates_a_throwing_subscriber(): void
    {
        $c = $this->controller(withEvents: true);
        $user = TestHelper::createMockUser('root', ['access' => ['api' => ['super' => true]]]);

        \Grav\Common\Grav::instance()->addListener(
            'onApiUserListColumnData',
            static function (Event $event): void {
                throw new \RuntimeException('plugin blew up');
            },
        );

        $page = [['username' => 'alice'], ['username' => 'bob']];

        // The list must come back intact, with no extra data, and never throw.
        $result = $this->applyColumnData($c, $page, $user);

        $this->assertSame($page, $result);
        $this->assertNotEmpty($this->warnings, 'a plugin fault should be logged as a warning');
    }
}

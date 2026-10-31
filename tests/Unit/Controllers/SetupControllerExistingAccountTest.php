<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Plugin\Api\Controllers\SetupController;
use Grav\Plugin\Api\Exceptions\ConflictException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\TestCase;

final class SetupControllerExistingAccountTest extends TestCase
{
    public function testExistingAccountIsUnchangedWhenCountIsZero(): void
    {
        $user = TestHelper::createMockUser('existing', ['email' => 'existing@example.com']);
        $accounts = new class ($user) {
            public function __construct(private object $user) {}
            public function count(): int { return 0; }
            public function load(string $name): object { return $this->user; }
        };
        $config = new Config();
        $grav = TestHelper::createMockGrav(['config' => $config, 'accounts' => $accounts]);
        $body = ['username' => 'existing', 'password' => 'Example-password-482!', 'email' => 'replacement@example.com'];
        $request = TestHelper::createMockRequest(method: 'POST', attributes: ['json_body' => $body]);

        try {
            (new SetupController($grav, $config))->create($request);
            self::fail('Expected a conflict for an existing account.');
        } catch (ConflictException $e) {
            self::assertSame(409, $e->getStatusCode());
        } finally {
            self::assertSame('existing@example.com', $user->get('email'));
        }
    }
}

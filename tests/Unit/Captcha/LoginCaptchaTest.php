<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Captcha;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Plugin\Api\Captcha\LoginCaptcha;
use Grav\Plugin\Api\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use TrilbyMedia\Cap\Prng;

/**
 * The captcha gate in front of login, forgotten-password and setup.
 *
 * The behavior that matters here is all about failing in the right direction:
 * a gate that's off must never reject, a provider that can't verify must never
 * pretend to be on (that would lock every admin out), and a redeemed token must
 * only work once.
 */
class LoginCaptchaTest extends TestCase
{
    protected function setUp(): void
    {
        Grav::resetInstance();

        $grav = Grav::instance();
        $grav['log'] = new class {
            public function warning($message): void {}
            public function error($message): void {}
        };
        $grav['cache'] = new class {
            private CacheInterface $simple;

            public function __construct()
            {
                $this->simple = new class implements CacheInterface {
                    private array $items = [];

                    public function get(string $key, mixed $default = null): mixed
                    {
                        return $this->items[$key] ?? $default;
                    }

                    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
                    {
                        $this->items[$key] = $value;
                        return true;
                    }

                    public function delete(string $key): bool
                    {
                        unset($this->items[$key]);
                        return true;
                    }

                    public function clear(): bool
                    {
                        $this->items = [];
                        return true;
                    }

                    public function getMultiple(iterable $keys, mixed $default = null): iterable
                    {
                        $out = [];
                        foreach ($keys as $key) {
                            $out[$key] = $this->get($key, $default);
                        }
                        return $out;
                    }

                    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
                    {
                        foreach ($values as $key => $value) {
                            $this->set($key, $value, $ttl);
                        }
                        return true;
                    }

                    public function deleteMultiple(iterable $keys): bool
                    {
                        foreach ($keys as $key) {
                            $this->delete($key);
                        }
                        return true;
                    }

                    public function has(string $key): bool
                    {
                        return isset($this->items[$key]);
                    }
                };
            }

            public function getSimpleCache(): CacheInterface
            {
                return $this->simple;
            }
        };
    }

    #[Test]
    public function it_is_off_and_gates_nothing_by_default(): void
    {
        $captcha = $this->captcha([]);

        $this->assertFalse($captcha->isEnabled());
        $this->assertFalse($captcha->guards(LoginCaptcha::FLOW_LOGIN));

        // The whole point of the flow check: a disabled gate must never reject
        // a request that carries no token.
        $captcha->verify(LoginCaptcha::FLOW_LOGIN, []);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_only_gates_the_flows_it_is_configured_for(): void
    {
        $captcha = $this->captcha([
            'enabled' => true,
            'flows' => ['login' => true, 'forgot_password' => false, 'setup' => false],
        ]);

        $this->assertTrue($captcha->guards(LoginCaptcha::FLOW_LOGIN));
        $this->assertFalse($captcha->guards(LoginCaptcha::FLOW_FORGOT_PASSWORD));

        $captcha->verify(LoginCaptcha::FLOW_FORGOT_PASSWORD, []);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_rejects_a_gated_request_with_no_token(): void
    {
        $captcha = $this->captcha(['enabled' => true, 'flows' => ['login' => true]]);

        $this->expectException(ValidationException::class);
        $captcha->verify(LoginCaptcha::FLOW_LOGIN, []);
    }

    #[Test]
    public function it_rejects_a_token_it_never_issued(): void
    {
        $captcha = $this->captcha(['enabled' => true, 'flows' => ['login' => true]]);

        $this->expectException(ValidationException::class);
        $captcha->verify(LoginCaptcha::FLOW_LOGIN, [LoginCaptcha::TOKEN_FIELD => 'made:up']);
    }

    #[Test]
    public function it_accepts_a_solved_challenge_exactly_once(): void
    {
        $captcha = $this->captcha([
            'enabled' => true,
            'flows' => ['login' => true],
            // Keep the proof-of-work trivial — the protocol is what's under
            // test here, not the CPU cost.
            'cap' => ['challenge_count' => 2, 'challenge_difficulty' => 1],
        ]);

        $body = [LoginCaptcha::TOKEN_FIELD => $this->solve($captcha)];

        $captcha->verify(LoginCaptcha::FLOW_LOGIN, $body);
        $this->addToAssertionCount(1);

        // Replaying the same token must fail: redeemed tokens are single-use,
        // otherwise one solved challenge would license unlimited attempts.
        $this->expectException(ValidationException::class);
        $captcha->verify(LoginCaptcha::FLOW_LOGIN, $body);
    }

    #[Test]
    public function it_rejects_wrong_solutions(): void
    {
        $captcha = $this->captcha([
            'enabled' => true,
            'flows' => ['login' => true],
            'cap' => ['challenge_count' => 2, 'challenge_difficulty' => 1],
        ]);

        $challenge = $captcha->challenge();
        $result = $captcha->redeem($challenge['token'], [0, 0]);

        $this->assertFalse($result['success']);
        $this->assertArrayNotHasKey('token', $result);
    }

    #[Test]
    public function it_reports_disabled_when_a_delegated_provider_has_no_keys(): void
    {
        // Turnstile and reCAPTCHA live in the Form plugin and need its keys. If
        // we reported enabled here the login page would render a widget the
        // server can't verify, and nobody could sign in.
        $captcha = $this->captcha(['enabled' => true, 'provider' => 'turnstile']);

        $this->assertFalse($captcha->isEnabled());
        $this->assertFalse($captcha->guards(LoginCaptcha::FLOW_LOGIN));
        $this->assertFalse($captcha->clientConfig()['enabled']);
    }

    #[Test]
    public function its_client_config_carries_no_secret(): void
    {
        $captcha = $this->captcha(['enabled' => true, 'flows' => ['login' => true]]);
        $config = $captcha->clientConfig();

        $this->assertTrue($config['enabled']);
        $this->assertSame('cap', $config['provider']);
        $this->assertStringEndsWith('/auth/captcha/', $config['endpoint']);
        $this->assertArrayNotHasKey('secret_key', $config);
        $this->assertStringNotContainsString('secret', strtolower(json_encode($config)));
    }

    /**
     * Run the proof-of-work the browser would normally do in wasm.
     */
    private function solve(LoginCaptcha $captcha): string
    {
        $challenge = $captcha->challenge();
        $token = $challenge['token'];
        ['c' => $count, 's' => $size, 'd' => $difficulty] = $challenge['challenge'];

        $solutions = [];
        for ($i = 1; $i <= $count; $i++) {
            $salt = Prng::generate($token . $i, $size);
            $target = Prng::generate($token . $i . 'd', $difficulty);
            for ($nonce = 0; ; $nonce++) {
                if (str_starts_with(hash('sha256', $salt . $nonce), $target)) {
                    $solutions[] = $nonce;
                    break;
                }
            }
        }

        return $captcha->redeem($token, $solutions)['token'];
    }

    private function captcha(array $settings): LoginCaptcha
    {
        $config = new Config([
            'plugins' => [
                'api' => [
                    'route' => '/api',
                    'version_prefix' => 'v1',
                    'login' => ['captcha' => $settings],
                ],
            ],
        ]);

        $grav = Grav::instance();
        $grav['uri'] = new class {
            public function rootUrl($include_host = false): string
            {
                return 'https://example.com';
            }
        };

        return new LoginCaptcha($grav, $config);
    }
}

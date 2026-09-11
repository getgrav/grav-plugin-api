<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Middleware;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Auth\ApiKeyAuthenticator;
use Grav\Plugin\Api\Auth\AuthenticatorInterface;
use Grav\Plugin\Api\Auth\JwtAuthenticator;
use Grav\Plugin\Api\Auth\SessionAuthenticator;
use Grav\Plugin\Api\Exceptions\UnauthorizedException;
use Grav\Plugin\Api\Middleware\AuthMiddleware;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The authenticated caller becomes Grav's current user (`$grav['user']`), so
 * core and other plugins stop seeing a guest during API requests
 * (grav-plugin-api#36). A scoped API key is the exception: its owner's ACL is
 * wider than the key, and `$grav['user']` consumers never see the scope cap.
 */
#[CoversClass(AuthMiddleware::class)]
class AuthMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        Grav::resetInstance();
    }

    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    #[Test]
    public function jwt_user_becomes_the_active_user(): void
    {
        $user = $this->user('editor');

        $request = $this->attach($this->instance(JwtAuthenticator::class), $user);

        self::assertSame($user, $request->getAttribute('api_user'));
        self::assertSame($user, Grav::instance()['user']);
        // Without these core's authorize() refuses every action.
        self::assertTrue($user->get('authenticated'));
        self::assertTrue($user->get('authorized'));
    }

    #[Test]
    public function session_user_becomes_the_active_user(): void
    {
        $user = $this->user('editor', ['authenticated' => true, 'authorized' => true]);

        $this->attach($this->instance(SessionAuthenticator::class), $user);

        self::assertSame($user, Grav::instance()['user']);
    }

    #[Test]
    public function unscoped_api_key_user_becomes_the_active_user(): void
    {
        $user = $this->user('editor');

        $request = $this->attach($this->apiKey([]), $user);

        self::assertSame([], $request->getAttribute('api_key_scopes'));
        self::assertSame($user, Grav::instance()['user']);
        self::assertTrue($user->get('authenticated'));
    }

    #[Test]
    public function scoped_api_key_user_is_not_presented_to_core(): void
    {
        $grav = $this->grav();
        $guest = $this->user('');
        $grav['user'] = $guest;
        $owner = $this->user('root', ['access' => ['api' => ['super' => true]]]);

        $request = $this->attach($this->apiKey(['api.pages.read']), $owner);

        // The request still carries the owner and the cap for the API's own checks...
        self::assertSame($owner, $request->getAttribute('api_user'));
        self::assertSame(['api.pages.read'], $request->getAttribute('api_key_scopes'));
        // ...but core keeps the guest, and the owner is never marked logged in.
        self::assertSame($guest, $grav['user']);
        self::assertNull($owner->get('authenticated'));
        self::assertNull($owner->get('authorized'));
    }

    #[Test]
    public function replaces_the_guest_already_in_the_container(): void
    {
        // Under the test stubs the container is a plain ArrayAccess. The real
        // one is Pimple, where the Login plugin's `user` closure is frozen once
        // read and only unset-then-set can replace it; that is what
        // setActiveUser() does.
        $grav = $this->grav();
        $grav['user'] = $this->user('');

        $user = $this->user('editor');
        $this->attach($this->instance(JwtAuthenticator::class), $user);

        self::assertSame($user, $grav['user']);
    }

    #[Test]
    public function leaves_the_shared_session_user_alone(): void
    {
        $grav = $this->grav();
        $visitor = $this->user('visitor', ['authenticated' => true, 'authorized' => true]);
        $session = new \stdClass();
        $session->user = $visitor;
        $grav['session'] = $session;

        $this->attach($this->instance(JwtAuthenticator::class), $this->user('editor'));

        self::assertSame($visitor, $session->user);
    }

    #[Test]
    public function unauthenticated_requests_never_change_the_active_user(): void
    {
        $grav = $this->grav();
        $guest = $this->user('');
        $grav['user'] = $guest;
        $middleware = $this->middleware();

        $optional = $middleware->processOptional(TestHelper::createMockRequest());
        self::assertNull($optional->getAttribute('api_user'));
        self::assertSame($guest, $grav['user']);

        try {
            $middleware->processRequest(TestHelper::createMockRequest());
            self::fail('Expected UnauthorizedException');
        } catch (UnauthorizedException) {
            self::assertSame($guest, $grav['user']);
        }
    }

    private function grav(): Grav
    {
        return Grav::instance();
    }

    /**
     * Every authenticator switched off, so processRequest()/processOptional()
     * see no credentials and the attachUser() tests drive the method directly.
     */
    private function middleware(): AuthMiddleware
    {
        return new AuthMiddleware(Grav::instance(), new Config(['plugins' => ['api' => ['auth' => [
            'api_keys_enabled' => false,
            'jwt_enabled' => false,
            'session_enabled' => false,
        ]]]]));
    }

    private function attach(AuthenticatorInterface $authenticator, UserInterface $user): ServerRequestInterface
    {
        $middleware = $this->middleware();

        return (new \ReflectionMethod($middleware, 'attachUser'))
            ->invoke($middleware, TestHelper::createMockRequest(), $authenticator, $user);
    }

    private function user(string $username, array $data = []): UserInterface
    {
        return TestHelper::createMockUser($username, $data);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function instance(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private function apiKey(array $scopes): ApiKeyAuthenticator
    {
        $authenticator = $this->instance(ApiKeyAuthenticator::class);
        (new \ReflectionProperty(ApiKeyAuthenticator::class, 'authenticatedScopes'))->setValue($authenticator, $scopes);

        return $authenticator;
    }
}

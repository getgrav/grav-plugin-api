<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Captcha;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Plugin\Api\Exceptions\ValidationException;
use TrilbyMedia\Cap\Cap;
use TrilbyMedia\Cap\Config as CapConfig;
use TrilbyMedia\Cap\Storage\Psr16Storage;

/**
 * Captcha gate for the unauthenticated auth endpoints — login, forgotten
 * password and first-run setup.
 *
 * The default provider is cap: a proof-of-work challenge served by this plugin
 * itself, with no keys to obtain and no third-party service in the request
 * path. Turnstile and reCAPTCHA are available on sites that also run the Form
 * plugin, which owns those providers and their secrets — this class delegates
 * to them rather than keeping a second copy of the keys.
 *
 * Cap is deliberately NOT proxied to the Form plugin's /forms-cap/ endpoints.
 * Those emit no CORS headers, so they break the moment admin-next is served
 * from a different origin than the API, and routing through them would make
 * captcha on admin login depend on a plugin the admin doesn't otherwise need.
 */
class LoginCaptcha
{
    /** Request-body field the client sends the solved token in. */
    public const TOKEN_FIELD = 'captcha_token';

    /** Flows the gate can be applied to. */
    public const FLOW_LOGIN = 'login';
    public const FLOW_FORGOT_PASSWORD = 'forgot_password';
    public const FLOW_SETUP = 'setup';

    /** Built-in provider — no keys, no third-party service. */
    public const PROVIDER_CAP = 'cap';

    /**
     * Providers owned by the Form plugin, mapped to the class that implements
     * them there.
     */
    private const DELEGATED = [
        'turnstile' => \Grav\Plugin\Form\Captcha\TurnstileProvider::class,
        'recaptcha' => \Grav\Plugin\Form\Captcha\ReCaptchaProvider::class,
    ];

    private ?Cap $cap = null;

    public function __construct(
        private readonly Grav $grav,
        private readonly Config $config,
    ) {}

    /**
     * Configured provider name, normalized. Defaults to cap.
     */
    public function provider(): string
    {
        $provider = strtolower(trim((string) $this->setting('provider', self::PROVIDER_CAP)));

        return $provider !== '' ? $provider : self::PROVIDER_CAP;
    }

    /**
     * Whether captcha is switched on AND the configured provider can actually
     * run here. An unavailable provider reports disabled rather than serving a
     * widget the server can't verify.
     */
    public function isEnabled(): bool
    {
        return (bool) $this->setting('enabled', false) && $this->isAvailable($this->provider());
    }

    /**
     * Whether the given flow is gated.
     */
    public function guards(string $flow): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $flows = (array) $this->setting('flows', []);

        return (bool) ($flows[$flow] ?? false);
    }

    /**
     * Widget mode: 'invisible' solves in the background, 'checkbox' makes the
     * user click. Only cap and Turnstile honor it.
     */
    public function mode(): string
    {
        $mode = strtolower(trim((string) $this->setting('mode', 'invisible')));

        return in_array($mode, ['invisible', 'checkbox'], true) ? $mode : 'invisible';
    }

    /**
     * Everything the login page needs to render the challenge. Served publicly
     * (GET /auth/captcha), so it must never expose a secret — cap has none, and
     * the delegated providers only contribute their public site key.
     */
    public function clientConfig(): array
    {
        $enabled = $this->isEnabled();
        $provider = $this->provider();

        $data = [
            'enabled'     => $enabled,
            'provider'    => $enabled ? $provider : null,
            'mode'        => $this->mode(),
            'token_field' => self::TOKEN_FIELD,
            'flows'       => [
                self::FLOW_LOGIN           => $this->guards(self::FLOW_LOGIN),
                self::FLOW_FORGOT_PASSWORD => $this->guards(self::FLOW_FORGOT_PASSWORD),
                self::FLOW_SETUP           => $this->guards(self::FLOW_SETUP),
            ],
        ];

        if (!$enabled) {
            return $data;
        }

        if ($provider === self::PROVIDER_CAP) {
            $data['endpoint'] = $this->endpoint();

            return $data;
        }

        $data['site_key'] = $this->formConfig($provider)['site_key'] ?? '';

        if ($provider === 'recaptcha') {
            $data['version'] = (string) ($this->formConfig($provider)['version'] ?? '2-checkbox');
        }

        return $data;
    }

    /**
     * Verify the challenge carried by an unauthenticated request. A no-op when
     * captcha is off or this flow isn't gated, so callers can call it
     * unconditionally.
     *
     * @throws ValidationException when the challenge is missing or fails
     */
    public function verify(string $flow, array $body, ?string $ip = null): void
    {
        if (!$this->guards($flow)) {
            return;
        }

        $token = trim((string) ($body[self::TOKEN_FIELD] ?? ''));

        if ($token === '') {
            $this->logFailure($flow, 'missing token', $ip);
            throw new ValidationException(
                'The captcha challenge was not completed.',
                [['field' => self::TOKEN_FIELD, 'message' => 'The captcha challenge was not completed.']],
            );
        }

        $provider = $this->provider();
        $verified = $provider === self::PROVIDER_CAP
            ? $this->verifyCap($token)
            : $this->verifyDelegated($provider, $token);

        if (!$verified) {
            $this->logFailure($flow, 'invalid token', $ip);
            throw new ValidationException(
                'The captcha challenge could not be verified. Please try again.',
                [['field' => self::TOKEN_FIELD, 'message' => 'The captcha challenge could not be verified.']],
            );
        }
    }

    /**
     * Mint a cap challenge for the client to solve.
     */
    public function challenge(): array
    {
        return $this->cap()->createChallenge();
    }

    /**
     * Exchange solved sub-challenges for a token the login request can carry.
     */
    public function redeem(string $token, array $solutions): array
    {
        return $this->cap()->redeemChallenge($token, array_map(static fn ($v) => (int) $v, $solutions));
    }

    /**
     * Base URL of this plugin's cap endpoints. The cap.js client appends
     * `challenge` and `redeem` to it, so the trailing slash matters.
     */
    public function endpoint(): string
    {
        $base = $this->config->get('plugins.api.route', '/api');
        $prefix = $this->config->get('plugins.api.version_prefix', 'v1');
        $apiBase = '/' . trim((string) $base, '/') . '/' . trim((string) $prefix, '/');
        $root = rtrim((string) $this->grav['uri']->rootUrl(true), '/');

        return $root . $apiBase . '/auth/captcha/';
    }

    /**
     * Cap instance, backed by Grav's cache so challenges and redeemed tokens
     * survive across the two round-trips without a dedicated store.
     */
    private function cap(): Cap
    {
        if ($this->cap !== null) {
            return $this->cap;
        }

        $settings = (array) $this->setting('cap', []);

        return $this->cap = new Cap(new CapConfig(
            challengeStorage:    new Psr16Storage($this->grav['cache']->getSimpleCache()),
            tokenStorage:        new Psr16Storage($this->grav['cache']->getSimpleCache()),
            challengeCount:      (int) ($settings['challenge_count'] ?? 30),
            challengeSize:       (int) ($settings['challenge_size'] ?? 32),
            challengeDifficulty: (int) ($settings['challenge_difficulty'] ?? 4),
            expiresMs:           (int) ($settings['expires_ms'] ?? 600_000),
        ));
    }

    private function verifyCap(string $token): bool
    {
        try {
            return $this->cap()->validateToken($token);
        } catch (\Throwable $e) {
            $this->grav['log']->error('Login captcha (cap) verification error: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Hand the token to the Form plugin's provider for the remote siteverify
     * call. The Form providers check $_POST first and fall back to the form
     * array; an API request carries a JSON body, so $_POST is empty and the
     * array we build here is the only source.
     */
    private function verifyDelegated(string $provider, string $token): bool
    {
        $instance = $this->formProvider($provider);

        if ($instance === null) {
            return false;
        }

        if ($provider === 'turnstile') {
            $form = ['cf-turnstile-response' => $token];
        } else {
            // Pass only the key the configured reCAPTCHA version reads. The
            // provider infers v3 from the presence of a bare `token` key, so
            // sending both would silently move a v2 site onto the v3 branch.
            $version = (string) ($this->formConfig($provider)['version'] ?? '2-checkbox');
            $form = str_starts_with($version, '3')
                ? ['token' => $token, 'action' => 'login']
                : ['g-recaptcha-response' => $token];
        }

        try {
            $result = $instance->validate($form);
        } catch (\Throwable $e) {
            $this->grav['log']->error("Login captcha ({$provider}) verification error: " . $e->getMessage());

            return false;
        }

        return ($result['success'] ?? false) === true;
    }

    /**
     * Resolve a Form-plugin provider, preferring an already-registered
     * instance so a site that swapped one out via
     * onFormRegisterCaptchaProviders keeps its replacement.
     */
    private function formProvider(string $provider): ?object
    {
        $class = self::DELEGATED[$provider] ?? null;

        if ($class === null || !class_exists($class)) {
            return null;
        }

        if (class_exists(\Grav\Plugin\Form\Captcha\CaptchaFactory::class)) {
            $registered = \Grav\Plugin\Form\Captcha\CaptchaFactory::getProvider($provider);
            if ($registered !== null) {
                return $registered;
            }
        }

        return new $class();
    }

    /**
     * Whether the named provider can run on this install.
     */
    private function isAvailable(string $provider): bool
    {
        if ($provider === self::PROVIDER_CAP) {
            return class_exists(Cap::class);
        }

        if (!isset(self::DELEGATED[$provider]) || !class_exists(self::DELEGATED[$provider])) {
            return false;
        }

        // A delegated provider without both keys can't verify anything, and a
        // widget with no site key can't render — treat it as unconfigured.
        $config = $this->formConfig($provider);

        return trim((string) ($config['site_key'] ?? '')) !== ''
            && trim((string) ($config['secret_key'] ?? '')) !== '';
    }

    /**
     * The Form plugin's own config for a delegated provider — the single place
     * those keys live.
     */
    private function formConfig(string $provider): array
    {
        return (array) $this->config->get("plugins.form.{$provider}", []);
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        return $this->config->get("plugins.api.login.captcha.{$key}", $default);
    }

    private function logFailure(string $flow, string $reason, ?string $ip): void
    {
        $this->grav['log']->warning(sprintf(
            'Login captcha failed on %s (%s) from %s',
            $flow,
            $reason,
            $ip !== null && $ip !== '' ? $ip : 'unknown',
        ));
    }
}

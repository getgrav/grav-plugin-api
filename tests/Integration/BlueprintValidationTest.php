<?php

declare(strict_types=1);

// Load the API plugin's autoloader so its controller classes are available.
require_once '/Users/rhuk/Projects/grav/grav-plugin-api/vendor/autoload.php';

use Codeception\Util\Fixtures;
use Grav\Common\Data\Blueprint;
use Grav\Common\Data\Blueprints;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Controllers\ConfigController;
use Grav\Plugin\Api\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration coverage for server-side blueprint validation on save
 * (getgrav/grav-plugin-admin2#30).
 *
 * The API validates only the fields a request actually changes — NOT the whole
 * merged object — because stock Grav config doesn't pass a whole-object
 * `$blueprint->validate()`: `system.errors.display` is a bool against a
 * `type: int` rule, and Grav's `list` validator rejects complete
 * security/backups/scheduler list items. These tests pin both halves: required
 * /invalid submitted values ARE rejected, and editing an unrelated field does
 * NOT trip those stock-config landmines.
 *
 * Requires a booted Grav (Validation translates messages), so it lives in the
 * integration group.
 */
#[Group('integration')]
class BlueprintValidationTest extends TestCase
{
    private object $controller;
    private \ReflectionMethod $validate;

    protected function setUp(): void
    {
        parent::setUp();

        // Boot the real Grav framework via the shared Codeception fixture, the
        // same way the other integration tests do — Validation needs the
        // language service to translate messages.
        $grav = Fixtures::get('grav');
        $grav();

        $this->controller = (new \ReflectionClass(ConfigController::class))->newInstanceWithoutConstructor();
        $this->validate = (new \ReflectionClass(AbstractApiController::class))->getMethod('validateChangedFields');
    }

    private function blueprint(array $items): Blueprint
    {
        $bp = new Blueprint('test', $items);
        $bp->init();
        return $bp;
    }

    /** @return string[] field names that failed, empty if validation passed */
    private function failingFields(array $changes, ?Blueprint $blueprint): array
    {
        try {
            $this->validate->invoke($this->controller, $changes, $blueprint);
            return [];
        } catch (ValidationException $e) {
            return array_map(static fn(array $err) => $err['field'], $e->getValidationErrors());
        }
    }

    #[Test]
    public function required_field_submitted_empty_is_rejected(): void
    {
        $bp = $this->blueprint(['form' => ['fields' => [
            'api_key' => ['type' => 'text', 'label' => 'API Key', 'validate' => ['required' => true]],
        ]]]);

        $this->assertSame(['api_key'], $this->failingFields(['api_key' => ''], $bp));
        $this->assertSame([], $this->failingFields(['api_key' => 'abc'], $bp));
    }

    #[Test]
    public function untouched_required_field_does_not_block_unrelated_edit(): void
    {
        $bp = $this->blueprint(['form' => ['fields' => [
            'api_key' => ['type' => 'text', 'validate' => ['required' => true]],
            'timeout' => ['type' => 'number', 'validate' => ['type' => 'int', 'min' => 1, 'max' => 60]],
        ]]]);

        // api_key is required but not part of this change — must not be flagged.
        $this->assertSame([], $this->failingFields(['timeout' => 30], $bp));
        $this->assertSame(['timeout'], $this->failingFields(['timeout' => 999], $bp));
    }

    #[Test]
    public function int_typed_field_accepts_boolean_via_coercion(): void
    {
        // Mirrors system.errors.display: declared type:int, but Grav's runtime
        // accepts bool (true === 1). Both must validate.
        $bp = $this->blueprint(['form' => ['fields' => [
            'errors.display' => ['type' => 'select', 'validate' => ['type' => 'int']],
        ]]]);

        $this->assertSame([], $this->failingFields(['errors' => ['display' => 1]], $bp));
        $this->assertSame([], $this->failingFields(['errors' => ['display' => true]], $bp));
        $this->assertSame([], $this->failingFields(['errors' => ['display' => false]], $bp));
    }

    #[Test]
    public function real_system_blueprint_does_not_false_positive_on_unrelated_edit(): void
    {
        $system = (new Blueprints('blueprints://config'))->get('system');

        // Stock system config fails a whole-object validate on errors.display;
        // a delta that doesn't touch it must still save cleanly.
        $this->assertSame([], $this->failingFields(['timezone' => 'UTC'], $system));
        $this->assertSame([], $this->failingFields(['errors' => ['display' => true]], $system));
    }

    #[Test]
    public function real_security_blueprint_does_not_trip_list_validation_bug(): void
    {
        $security = (new Blueprints('blueprints://config'))->get('security');

        // security.twig_sandbox.allowed_methods is a list whose per-item
        // required `.class` field trips a core validation bug on a whole-object
        // validate. Editing an unrelated scalar must not surface it.
        $this->assertSame([], $this->failingFields(['xss_enabled' => true], $security));
    }

    #[Test]
    public function real_account_blueprint_validates_submitted_fields(): void
    {
        $account = (new Blueprints('blueprints://user'))->get('account');

        $this->assertSame(['email'], $this->failingFields(['email' => 'not-an-email'], $account));
        $this->assertSame([], $this->failingFields(['email' => 'joe@example.com'], $account));
        $this->assertSame(['fullname'], $this->failingFields(['fullname' => ''], $account));
        // Dynamic data-options@ select must not false-positive (options unresolved).
        $this->assertSame([], $this->failingFields(['language' => 'fr'], $account));
    }
}

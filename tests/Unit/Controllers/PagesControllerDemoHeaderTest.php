<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Plugin\Api\Controllers\PagesController;
use Grav\Plugin\Api\Exceptions\DemoModeException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PagesControllerDemoHeaderTest extends TestCase
{
    private function sanitize(array $header, array $settings = [], bool $demo = true): array
    {
        $config = new Config($settings);
        $grav = TestHelper::createMockGrav(['config' => $config]);
        $user = TestHelper::createMockUser('demo', ['access.api.demo' => $demo]);
        $request = TestHelper::createMockRequest(attributes: ['api_user' => $user]);
        $controller = (new \ReflectionClass(PagesController::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($controller, 'grav'))->setValue($controller, $grav);
        (new \ReflectionProperty($controller, 'config'))->setValue($controller, $config);
        return (new \ReflectionMethod(PagesController::class, 'demoSafeHeader'))->invoke($controller, $request, $header);
    }

    public function testDemoMarkdownUsesOnlyTheEscapingSetting(): void
    {
        $result = $this->sanitize(['title' => 'Example', 'markdown' => ['special_chars' => [], 'escape_markup' => false, 'extra' => true]]);
        self::assertSame(['escape_markup' => true], $result['markdown']);
        self::assertSame('Example', $result['title']);
    }

    public static function inheritedTwig(): array
    {
        return [[[]], [['process' => ['twig' => null]]]];
    }

    #[DataProvider('inheritedTwig')]
    public function testMissingTwigSettingInheritsTheCoreGate(array $header): void
    {
        $this->expectException(DemoModeException::class);
        $this->sanitize($header, ['security' => ['twig_content' => ['process_enabled' => true]]]);
    }

    public function testAnExplicitPageTwigFalseAllowsAnOtherwiseSafeDemoSave(): void
    {
        self::assertSame(['escape_markup' => true], $this->sanitize(['process' => ['twig' => false]], ['security' => ['twig_content' => ['process_enabled' => true]]])['markdown']);
    }

    public function testAnExplicitSiteTwigFalseAllowsAnOtherwiseSafeDemoSave(): void
    {
        $result = $this->sanitize([], ['security' => ['twig_content' => ['process_enabled' => true]], 'system' => ['pages' => ['process' => ['twig' => false]]]]);
        self::assertSame(['escape_markup' => true], $result['markdown']);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testTheGateDefaultAlsoWorksWithoutTheCoreHelper(): void
    {
        class_alias(DemoLegacySecurity::class, \Grav\Common\Security::class);
        $this->expectException(DemoModeException::class);
        $this->sanitize([], ['security' => ['twig_content' => ['process_enabled' => true]]]);
    }

    public function testNonDemoHeadersAreUnchanged(): void
    {
        $header = ['markdown' => ['extra' => true], 'process' => ['twig' => true]];
        self::assertSame($header, $this->sanitize($header, [], false));
    }
}

final class DemoLegacySecurity {}

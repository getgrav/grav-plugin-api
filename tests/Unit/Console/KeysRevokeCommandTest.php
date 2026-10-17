<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Console;

use Grav\Plugin\Console\KeysRevokeCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require_once __DIR__ . '/../../../cli/KeysRevokeCommand.php';

/**
 * The interactive key picker in `bin/plugin api keys:revoke` (api#60).
 *
 * Feeds an answer to the real Symfony ChoiceQuestion and checks that the key
 * ID comes back, whether the user types the ID or the full label.
 */
class KeysRevokeCommandTest extends TestCase
{
    private static function key(string $id, string $name = 'CLI Generated Key'): array
    {
        return ['id' => $id, 'name' => $name, 'prefix' => 'grav_6431aac...'];
    }

    private static function answer(array $keys, string $typed): ?string
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $typed . "\n");
        rewind($stream);

        $input = new ArrayInput([]);
        $input->setStream($stream);
        $input->setInteractive(true);

        return KeysRevokeCommand::askForKey(new QuestionHelper(), $input, new BufferedOutput(), $keys);
    }

    public static function answers(): array
    {
        $one = [self::key('6bd84b79b861bc8c')];
        $two = [self::key('6bd84b79b861bc8c'), self::key('a1b2c3d4e5f60718', 'Deploy')];
        $numeric = [self::key('1234567890123456')];

        return [
            'typed the key id' => [$one, '6bd84b79b861bc8c', '6bd84b79b861bc8c'],
            'typed the full label' => [$one, 'CLI Generated Key (grav_6431aac...) - 6bd84b79b861bc8c', '6bd84b79b861bc8c'],
            'second of two keys' => [$two, 'a1b2c3d4e5f60718', 'a1b2c3d4e5f60718'],
            'all-digit key id' => [$numeric, '1234567890123456', '1234567890123456'],
        ];
    }

    #[Test]
    #[DataProvider('answers')]
    public function selectingAKeyReturnsItsId(array $keys, string $typed, string $expected): void
    {
        self::assertSame($expected, self::answer($keys, $typed));
    }
}

<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\Api\Demo\DemoManager;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reset demo content back to the captured baseline.
 * Invoked as: bin/plugin api demo:reset
 */
class DemoResetCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('demo:reset')
            ->setAliases(['demo-reset'])
            ->setDescription('Reset demo-mode content back to the captured baseline')
            ->setHelp('The <info>demo:reset</info> command restores the demo-writable content (pages/media/etc.) to the baseline captured with <info>demo:baseline</info>, after taking a safety snapshot of the current state.');
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';

        $io = new SymfonyStyle($this->input, $this->output);
        $this->initializePlugins();

        $grav = Grav::instance();
        $manager = new DemoManager($grav, $grav['config']);

        if (!$manager->baselineExists()) {
            $io->error('No baseline has been captured. Run "bin/plugin api demo:baseline" first.');
            return 1;
        }

        $io->writeln('Resetting demo content to baseline…');

        if (!$manager->reset(true)) {
            $io->error('Reset did not run (no baseline or no writable resources).');
            return 1;
        }

        $io->success('Demo content reset to baseline.');

        return 0;
    }
}

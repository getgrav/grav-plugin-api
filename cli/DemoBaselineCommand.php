<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\Api\Demo\DemoManager;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Capture the current demo-writable content as the reset baseline.
 * Invoked as: bin/plugin api demo:baseline
 */
class DemoBaselineCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('demo:baseline')
            ->setAliases(['demo-baseline'])
            ->setDescription('Capture the current content as the demo-mode reset baseline')
            ->setHelp('The <info>demo:baseline</info> command snapshots the demo-writable content roots (pages/media/etc.) as the "known good" state that demo mode resets back to.');
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';

        $io = new SymfonyStyle($this->input, $this->output);
        $this->initializePlugins();

        $grav = Grav::instance();
        $manager = new DemoManager($grav, $grav['config']);

        $roots = $manager->writableRoots();
        if ($roots === []) {
            $io->error('No writable demo resources are configured (plugins.api.demo.writable is empty or maps to no existing folders). Nothing to capture.');
            return 1;
        }

        $io->writeln('Capturing baseline for: <cyan>' . implode(', ', array_keys($roots)) . '</cyan>');

        if (!$manager->captureBaseline()) {
            $io->error('Failed to capture baseline.');
            return 1;
        }

        $io->success('Demo baseline captured.');
        $io->writeln('Reset interval: <yellow>' . $manager->resetIntervalMinutes() . '</yellow> minutes.');

        return 0;
    }
}

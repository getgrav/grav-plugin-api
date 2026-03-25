<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\Api\Auth\ApiKeyManager;
use Symfony\Component\Console\Style\SymfonyStyle;

class KeysMigrateCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('keys:migrate')
            ->setDescription('Migrate API keys from user accounts to centralized storage')
            ->setHelp('Moves API keys from individual user account YAML files to the centralized user/data/api-keys.yaml file.');
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';

        $io = new SymfonyStyle($this->input, $this->output);
        $grav = Grav::instance();

        $this->initializePlugins();

        $manager = new ApiKeyManager();
        $migrated = $manager->migrateFromAccounts();

        if ($migrated > 0) {
            $io->success("Migrated {$migrated} API key(s) to user/data/api-keys.yaml");
        } else {
            $io->writeln('No API keys found in user accounts to migrate.');
        }

        return 0;
    }
}

<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserCollectionInterface;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\Api\Auth\ApiKeyManager;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

class KeysGenerateCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('keys:generate')
            ->setAliases(['keys:gen', 'keys:create'])
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'The username to generate the key for')
            ->addOption('name', 'N', InputOption::VALUE_OPTIONAL, 'A name/label for the API key', 'CLI Generated Key')
            ->addOption('expiry', 'e', InputOption::VALUE_OPTIONAL, 'Key expiry in days (default: never expires)')
            ->setDescription('Generate a new API key for a user')
            ->setHelp('The <info>keys:generate</info> command creates a new API key for the specified user.');
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';

        $io = new SymfonyStyle($this->input, $this->output);
        $grav = Grav::instance();

        $this->initializePlugins();

        /** @var UserCollectionInterface $accounts */
        $accounts = $grav['accounts'];

        // Get username
        $username = $this->input->getOption('user');
        if (!$username) {
            $helper = $this->getHelper('question');
            $question = new Question('Enter the <yellow>username</yellow>: ');
            $question->setValidator(function ($value) use ($accounts) {
                if (!$value) {
                    throw new \RuntimeException('Username is required.');
                }
                $user = $accounts->load($value);
                if (!$user->exists()) {
                    throw new \RuntimeException("User '{$value}' does not exist.");
                }
                return $value;
            });
            $username = $helper->ask($this->input, $this->output, $question);
        }

        $user = $accounts->load($username);
        if (!$user->exists()) {
            $io->error("User '{$username}' does not exist.");
            return 1;
        }

        $name = $this->input->getOption('name');
        $expiryDays = $this->input->getOption('expiry') !== null ? (int) $this->input->getOption('expiry') : null;

        $manager = new ApiKeyManager();
        $result = $manager->generateKey($user, $name, [], $expiryDays);

        $io->newLine();
        $io->success("API key generated for user '{$username}'");
        $io->newLine();

        $io->writeln('<yellow>API Key:</yellow> <cyan>' . $result['key'] . '</cyan>');
        $io->writeln('<yellow>Key ID:</yellow>  ' . $result['id']);
        if ($expiryDays) {
            $io->writeln('<yellow>Expires:</yellow> ' . date('Y-m-d H:i', time() + ($expiryDays * 86400)));
        } else {
            $io->writeln('<yellow>Expires:</yellow> Never');
        }
        $io->newLine();

        $io->warning('Save this key now — it cannot be retrieved later.');

        return 0;
    }
}

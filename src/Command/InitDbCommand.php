<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\GameDatabase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:db:init',
    description: 'Initializes SQLite database and seeds 500+ curated Rebus questions from data/master_questions.json'
)]
class InitDbCommand extends Command
{
    public function __construct(private readonly GameDatabase $gameDatabase)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('reset', null, InputOption::VALUE_NONE, 'Wipe existing database and re-seed from scratch');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $reset = (bool) $input->getOption('reset');

        $io->title('Initializing Rebus Game Show Database');

        try {
            $count = $this->gameDatabase->seedFromMasterJson($reset);
            $totalQ = $this->gameDatabase->getQuestionCount();

            $io->success(sprintf('Database successfully initialized! Total questions in question bank: %d (seeded %d).', $totalQ, $count));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Database initialization failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}


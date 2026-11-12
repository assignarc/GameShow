<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\GameDatabase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:db:init',
    description: 'Initializes SQLite database with curated Rebus questions if not already initialized'
)]
class InitDbCommand extends Command
{
    public function __construct(private readonly GameDatabase $gameDatabase)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Initializing Rebus Game Show Database');

        try {
            $existingCount = $this->gameDatabase->getQuestionCount();
            if ($existingCount > 0) {
                $io->success(sprintf('Database already initialized! Preserving %d existing puzzles in question bank.', $existingCount));
                return Command::SUCCESS;
            }

            $count = $this->gameDatabase->seedFromMasterJson();
            $totalQ = $this->gameDatabase->getQuestionCount();

            $io->success(sprintf('Database initialized! Loaded %d questions into question bank.', $totalQ));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Database initialization failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}


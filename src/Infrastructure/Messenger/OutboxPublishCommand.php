<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger;

use App\Application\Outbox\OutboxPublisher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'app:outbox:publish',
    description: 'Publishes transactional Outbox events to RabbitMQ.',
)]
final class OutboxPublishCommand extends Command
{
    public function __construct(
        private readonly OutboxPublisher $publisher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'watch',
                null,
                InputOption::VALUE_NONE,
                'Continuously poll the Outbox.',
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of events claimed per iteration.',
                '100',
            )
            ->addOption(
                'sleep-ms',
                null,
                InputOption::VALUE_REQUIRED,
                'Sleep between empty iterations in milliseconds.',
                '500',
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);
        $watch = (bool) $input->getOption('watch');
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        $sleepMs = filter_var($input->getOption('sleep-ms'), FILTER_VALIDATE_INT);

        if (
            $limit === false
            || $sleepMs === false
            || $limit < 1
            || $limit > 1000
            || $sleepMs < 10
            || $sleepMs > 60000
        ) {
            $io->error('Invalid limit or sleep interval.');

            return Command::INVALID;
        }

        $running = true;

        if ($watch && function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);

            foreach ([SIGINT, SIGTERM] as $signal) {
                pcntl_signal(
                    $signal,
                    static function () use (&$running): void {
                        $running = false;
                    },
                );
            }
        }

        do {
            try {
                $processed = $this->publisher->publishBatch($limit);
            } catch (Throwable $exception) {
                $io->error($exception->getMessage());

                return Command::FAILURE;
            }

            if ($processed > 0) {
                $io->writeln(
                    sprintf('Claimed and processed %d Outbox event(s).', $processed),
                );
            }

            if ($watch && ($processed === 0 || $processed < $limit)) {
                usleep($sleepMs * 1000);
            }
        } while ($watch && $running);

        return Command::SUCCESS;
    }
}

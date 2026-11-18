<?php

declare(strict_types=1);

namespace Dochazka\Infrastructure;

use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('db:migrate', 'Aplikuje čekající databázové migrace.')]
final class MigrateCommand extends Command
{
    public function __construct(private Connection $db, private string $directory)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $names = $this->db->transactional(function (): array {
            $this->db->executeStatement('SELECT pg_advisory_xact_lock(718311)');
            $this->db->executeStatement('CREATE TABLE IF NOT EXISTS migration (name text PRIMARY KEY)');
            $applied = [];
            foreach (glob($this->directory.'/*.sql') as $file) {
                $name = basename($file);
                if ($this->db->fetchOne('SELECT 1 FROM migration WHERE name = ?', [$name])) {
                    continue;
                }
                $sql = file_get_contents($file);
                if ($sql === false) {
                    throw new RuntimeException("Nelze přečíst migraci $name.");
                }
                $this->db->executeStatement($sql);
                $this->db->insert('migration', ['name' => $name]);
                $applied[] = $name;
            }
            return $applied;
        });

        foreach ($names as $name) {
            $output->writeln("Aplikována migrace $name.");
        }
        return Command::SUCCESS;
    }
}

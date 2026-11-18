<?php

declare(strict_types=1);

namespace Dochazka\Identity;

use Dochazka\Staff\Employee;
use Dochazka\Staff\OrgUnits;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand('account:admin', 'Vytvoří účet správce.')]
final class CreateAdminCommand extends Command
{
    public function __construct(private OrgUnits $units, private EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('login', InputArgument::REQUIRED, 'Uživatelské jméno správce');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $login = trim($input->getArgument('login'));
        if (stream_isatty(STDIN) && $input->isInteractive()) {
            $question = (new Question('Heslo správce: '))->setHidden(true)->setHiddenFallback(false);
            $password = (string) $this->getHelper('question')->ask($input, $output, $question);
        } else {
            $password = rtrim((string) fgets(STDIN), "\r\n");
        }
        if ($login === '' || strlen($login) > 200) {
            $output->writeln('<error>Uživatelské jméno musí mít 1 až 200 bajtů.</error>');
            return Command::INVALID;
        }
        if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            $output->writeln('<error>Heslo musí mít 12 až 72 bajtů.</error>');
            return Command::INVALID;
        }
        $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($login, $password): void {
            $em->getConnection()->executeStatement('SELECT pg_advisory_xact_lock(718312)');
            $em->persist(Employee::administrator($login, 'Správce', $password, $this->units->adminUnit()));
        });
        $output->writeln('Účet správce byl vytvořen.');
        return Command::SUCCESS;
    }
}

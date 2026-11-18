<?php
declare(strict_types=1);

namespace Dochazka\Attendance;

use DateTimeImmutable;
use DateTimeZone;
use Dochazka\Http\Problem;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('attendance:fill-holidays', 'Doplní sváteční konto do prázdných ukončených dnů podle pracovního modelu.')]
final class FillHolidaysCommand extends Command
{
    public function __construct(private HolidayEntries $entries)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, 'První den včetně (YYYY-MM-DD).')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Poslední den včetně (YYYY-MM-DD), výchozí je včerejšek.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Spočítá změny bez zápisu.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Prague'));
        try {
            $to = $input->getOption('to') === null ? $today->modify('-1 day') : WorkModels::day($input->getOption('to'));
            $from = $input->getOption('from') === null ? $to->modify('-30 days') : WorkModels::day($input->getOption('from'));
            if ($from > $to || $to >= $today) throw new Problem('Vyberte ukončené dny v pořadí od–do.');
        } catch (Problem $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');
            return Command::INVALID;
        }
        $dryRun = $input->getOption('dry-run');
        $changes = $this->entries->fill($from, $to->modify('+1 day'), $dryRun);
        $output->writeln(sprintf('%s %s až %s. Přidáno %d, změněno %d, odebráno %d.',
            $dryRun ? 'Náhled' : 'Zpracováno', $from->format('Y-m-d'), $to->format('Y-m-d'), $changes['added'], $changes['changed'], $changes['removed']));
        return Command::SUCCESS;
    }
}

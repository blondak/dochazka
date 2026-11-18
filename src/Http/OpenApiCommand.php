<?php

declare(strict_types=1);

namespace Dochazka\Http;

use Apitte\OpenApi\ISchemaBuilder;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('api:openapi', 'Vygeneruje popis OpenAPI.')]
final class OpenApiCommand extends Command
{
    public function __construct(private ISchemaBuilder $schema, private string $destination)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $spec = $this->schema->build()->toArray();
        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $operation) {
                if (empty($operation['operationId']) || empty($operation['responses'][200])) {
                    throw new RuntimeException("Nedokumentovaný endpoint: $path");
                }
            }
        }
        ksort($spec['paths']);
        $json = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->destination, $json."\n") === false) {
            throw new RuntimeException('Popis OpenAPI se nepodařilo zapsat.');
        }
        return Command::SUCCESS;
    }
}

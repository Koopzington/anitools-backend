<?php

declare(strict_types=1);

namespace AniTools\Command;

use AniTools;
use AniTools\DBService;
use AniTools\Importer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

final class Import extends Command
{
    protected static $defaultName = 'app:import';
    protected static $defaultDescription = 'Imports all the data';

    private ?string $source = null;

    protected function configure(): void
    {
        $this->addArgument(
            'source',
            InputArgument::OPTIONAL,
            'Which source to import (AniList, AWC)',
        );
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        /** @var QuestionHelper */
        $helper = $this->getHelper('question');

        $source = $input->getArgument('source');
        if ($source === null) {
            $question = new ChoiceQuestion(
                'Please select the source you want to import',
                Importer::VALID_DATATYPES,
            );
            $question->setErrorMessage('Source %s is invalid.');

            $source = $helper->ask($input, $output, $question);
            $output->writeln('Source ' . $source . ' selected.');
        }

        $this->source = $source;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->source === null) {
            return self::FAILURE;
        }

        $importer = new AniTools\Importer(DBService::getDBConnection(), $output);

        return $importer->import($this->source);
    }
}

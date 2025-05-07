<?php

declare(strict_types=1);

namespace AniTools\Command;

use AniTools\Scraper\AniList;
use AniTools\Scraper\Animeshon;
use AniTools\Scraper\AWC;
use AniTools\Scraper\MangaDex;
use AniTools\Scraper\MangaUpdates;
use AniTools\Scraper\ScraperInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

final class Scrape extends Command implements SignalableCommandInterface
{
    private const VALID_SCRAPERS = [
        AniList::SCRAPER_NAME => AniList::class,
        AWC::SCRAPER_NAME => AWC::class,
        Animeshon::SCRAPER_NAME => Animeshon::class,
        MangaUpdates::SCRAPER_NAME => MangaUpdates::class,
        MangaDex::SCRAPER_NAME => MangaDex::class,
    ];

    protected static $defaultName = 'app:scrape';
    protected static $defaultDescription = 'Scrapes various datasources in for an import into the local database.';

    private ?ScraperInterface $scraper = null;
    private ?string $dataType = null;
    private ?string $userName = null;

    protected function configure(): void
    {
        $this->addArgument(
            'scraper',
            InputArgument::OPTIONAL,
            'Which scraper to use.',
        );
        $this->addArgument('dataType', InputArgument::OPTIONAL);
        $this->addArgument('userName', InputArgument::OPTIONAL);
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $scraper = $input->getArgument('scraper');
        if ($scraper === null) {
            $question = new ChoiceQuestion(
                'Please select the scraper you want to use',
                array_keys(self::VALID_SCRAPERS),
            );
            $question->setErrorMessage('Scraper %s is invalid.');

            $scraper = $io->askQuestion($question);
            $io->writeln('Scraper ' . $scraper . ' selected.');
        }
        if (! array_key_exists($scraper, self::VALID_SCRAPERS)) {
            $io->writeln(
                '<error>Invalid scraper provided. Valid scrapers are: '
                . implode(', ', array_keys(self::VALID_SCRAPERS)) . '</error>'
            );

            return;
        }

        $scraperClass = self::VALID_SCRAPERS[strtolower($scraper)];

        if (! $output instanceof ConsoleOutputInterface) {
            throw new \LogicException('This command accepts only an instance of "ConsoleOutputInterface".');
        }

        $this->scraper = new $scraperClass($output);

        $dataType = $input->getArgument('dataType');
        if ($dataType === null) {
            $question = new ChoiceQuestion(
                'Please select the datatype you want to scrape',
                $this->scraper::VALID_DATATYPES,
            );
            $question->setErrorMessage('Datatype %s is invalid.');

            $dataType = $io->askQuestion($question);
            $io->writeln('Scraper ' . $dataType . ' selected.');
        }
        if (! in_array($dataType, $this->scraper::VALID_DATATYPES, true)) {
            $io->writeln(
                '<error>Invalid datatype provided. Valid types are: '
                . implode(', ', $this->scraper::VALID_DATATYPES) . '</error>'
            );

            return;
        }

        $userName = $input->getArgument('userName');
        if ($dataType === 'activities' && $userName === null) {
            $question = new Question('Please enter the username: ', null);
            $userName = $io->askQuestion($question);

            if ($userName === null) {
                $io->writeln(
                    '<error>Username needs to be provided</error>'
                );

                return;
            }

            $this->userName = $userName;
        }

        $this->dataType = $dataType;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->scraper === null || $this->dataType === null) {
            return self::FAILURE;
        }

        if ($this->scraper instanceof AniList) {
            return $this->scraper->scrape($this->dataType, $this->userName);
        } else {
            return $this->scraper->scrape($this->dataType);
        }
    }

    /** @return array<int, int> */
    public function getSubscribedSignals(): array
    {
        return [SIGINT];
    }

    public function handleSignal(int $signal)
    {
        // Only handle the SIGINT signal if the scraper actually supports canceling
        if ($signal === SIGINT && $this->scraper !== null && method_exists($this->scraper, 'cancel')) {
            $this->scraper->cancel();
            return self::FAILURE;
        }

        // Continue execution because it wasn't a SIGINT
        return false;
    }
}

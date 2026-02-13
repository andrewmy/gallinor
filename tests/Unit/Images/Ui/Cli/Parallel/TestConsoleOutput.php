<?php

declare(strict_types=1);

namespace App\Tests\Unit\Images\Ui\Cli\Parallel;

use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

use function fopen;

final class TestConsoleOutput extends StreamOutput implements ConsoleOutputInterface
{
    /** @var list<ConsoleSectionOutput> */
    private array $sections = [];
    private OutputInterface $errorOutput;

    public function __construct()
    {
        $stream = fopen('php://memory', 'wb+');

        parent::__construct($stream, OutputInterface::VERBOSITY_DEBUG, true);

        $this->errorOutput = new StreamOutput(fopen('php://memory', 'wb+'), OutputInterface::VERBOSITY_DEBUG, true);
    }

    public function section(): ConsoleSectionOutput
    {
        return new ConsoleSectionOutput(
            $this->getStream(),
            $this->sections,
            $this->getVerbosity(),
            $this->isDecorated(),
            $this->getFormatter(),
        );
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->errorOutput;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        $this->errorOutput = $error;
    }

    /** @return resource */
    public function stream()
    {
        return $this->getStream();
    }

    public function setFormatter(OutputFormatterInterface $formatter): void
    {
        parent::setFormatter($formatter);

        $this->errorOutput->setFormatter($formatter);
    }

    public function setDecorated(bool $decorated): void
    {
        parent::setDecorated($decorated);

        $this->errorOutput->setDecorated($decorated);
    }

    public function setVerbosity(int $level): void
    {
        parent::setVerbosity($level);

        $this->errorOutput->setVerbosity($level);
    }
}

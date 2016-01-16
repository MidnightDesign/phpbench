<?php

/*
 * This file is part of the PHPBench package
 *
 * (c) Daniel Leech <daniel@dantleech.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpBench\Progress\Logger;

use PhpBench\Benchmark\Iteration;
use PhpBench\Benchmark\IterationCollection;
use PhpBench\Benchmark\Metadata\BenchmarkMetadata;
use PhpBench\Benchmark\SuiteDocument;
use PhpBench\Util\TimeUnit;

class BlinkenLogger extends AnsiLogger
{
    /**
     * Number of measurements to show per row.
     */
    const NUMBER_COLS = 15;

    const INDENT = 4;

    /**
     * Track rejected iterations.
     *
     * @var int[]
     */
    private $rejects = array();

    /**
     * Current number of rows in the time display.
     *
     * @var int
     */
    private $currentLine = 0;

    /**
     * Column width.
     *
     * @var int
     */
    private $colWidth = 6;

    /**
     * {@inheritdoc}
     */
    public function endSuite(SuiteDocument $suiteDocument)
    {
        $this->output->write(PHP_EOL);
        parent::endSuite($suiteDocument);
    }

    /**
     * {@inheritdoc}
     */
    public function benchmarkStart(BenchmarkMetadata $benchmark)
    {
        static $first = true;

        if (false === $first) {
            $this->output->write(PHP_EOL);
        }
        $first = false;
        $this->output->write(sprintf('<comment>%s</comment>', $benchmark->getClass()));

        $subjectNames = array();
        foreach ($benchmark->getSubjectMetadatas() as $subject) {
            $subjectNames[] = sprintf('#%s %s', $subject->getIndex(), $subject->getName());
        }

        $this->output->write(sprintf(' (%s)', implode(', ', $subjectNames)));
        $this->output->write(PHP_EOL);
        $this->output->write(PHP_EOL);
    }

    /**
     * {@inheritdoc}
     */
    public function iterationsStart(IterationCollection $collection)
    {
        $this->drawIterations($collection, $this->rejects, 'error');
        $this->renderCollectionStatus($collection);
        $this->resetLinePosition(); // put cursor at starting ypos ready for iteration times
    }

    /**
     * {@inheritdoc}
     */
    public function iterationsEnd(IterationCollection $collection)
    {
        $this->resetLinePosition();
        $this->drawIterations($collection, array(), null);

        if ($collection->hasException()) {
            $this->output->write(' <error>ERROR</error>');
            $this->output->write("\x1B[0J"); // clear the rest of the line
            $this->output->write(PHP_EOL);

            return;
        }

        $this->rejects = array();

        foreach ($collection->getRejects() as $reject) {
            $this->rejects[$reject->getIndex()] = true;
        }

        if ($this->rejects) {
            $this->resetLinePosition();

            return;
        }

        $this->output->write(sprintf(
            ' <comment>%s</comment>',
            $this->formatIterationsShortSummary($collection)
        ));
        $this->output->write(PHP_EOL);
    }

    /**
     * {@inheritdoc}
     */
    public function iterationEnd(Iteration $iteration)
    {
        $this->output->write(sprintf(
            "\x1B[" . $this->getXPos($iteration) . 'G%s',
            $this->formatIterationTime($iteration)
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function iterationStart(Iteration $iteration)
    {
        if ($this->currentLine != $yPos = $this->getYPos($iteration)) {
            $downMovement = $yPos - $this->currentLine;
            $this->output->write("\x1B[" . $downMovement . 'B');
            $this->currentLine = $yPos;
        }

        $this->output->write(sprintf(
            "\x1B[" . $this->getXPos($iteration) . 'G<bg=green>%s</>',
            $this->formatIterationTime($iteration)
        ));
    }

    /**
     * {@inheritdoc}
     */
    protected function formatIterationTime(Iteration $iteration)
    {
        $time = sprintf('%-' . $this->colWidth . 's', parent::formatIterationTime($iteration));

        if (strlen($time) > $this->colWidth) {
            // add one to allow a single space between columns
            $this->colWidth = strlen($time) + 1;
            $this->drawIterations($iteration->getCollection(), array(), null);
            $this->resetLinePosition();
        }

        return $time;
    }

    private function drawIterations(IterationCollection $collection, array $specials, $tag)
    {
        $this->output->write("\x1B[0G"); // put cursor at column 0

        $timeUnit = $collection->getSubject()->getOutputTimeUnit();
        $outputMode = $collection->getSubject()->getOutputMode();
        $lines = array();
        $line = sprintf('%-' . self::INDENT . 's', '#' . $collection->getSubject()->getIndex());

        for ($index = 0; $index < $collection->count(); $index++) {
            $iteration = $collection->getIteration($index);

            $displayTime = $this->formatIterationTime($iteration);

            if (isset($specials[$iteration->getIndex()])) {
                $displayTime = sprintf('<%s>%' . $this->colWidth . 's</%s>', $tag, $displayTime, $tag);
            }

            $line .= $displayTime;

            if ($index > 0 && ($index + 1) % self::NUMBER_COLS == 0) {
                $lines[] = $line;
                $line = str_repeat(' ', self::INDENT);
            }
        }

        $lines[] = $line;
        $this->currentLine = count($lines) - 1;

        $output = trim(implode(PHP_EOL, $lines));
        $output .= sprintf(
            ' (%s)',
            $this->timeUnit->getDestSuffix(
                $this->timeUnit->resolveDestUnit($timeUnit),
                $this->timeUnit->resolveMode($outputMode)
            )
        );

        $this->output->write(sprintf("%s\x1B[0J", $output)); // clear rest of the line
    }

    private function getXPos(Iteration $iteration)
    {
        return self::INDENT + ($iteration->getIndex() % self::NUMBER_COLS) * $this->colWidth + 1;
    }

    private function getYPos(Iteration $iteration)
    {
        return floor($iteration->getIndex() / self::NUMBER_COLS);
    }

    private function resetLinePosition()
    {
        if ($this->currentLine) {
            $this->output->write("\x1B[" . $this->currentLine . 'A'); // reset cursor Y pos
        }
        $this->currentLine = 0;
    }
}

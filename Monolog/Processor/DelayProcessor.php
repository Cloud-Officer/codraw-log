<?php

namespace Draw\Component\Log\Monolog\Processor;

use Monolog\LogRecord;
use Symfony\Contracts\Service\ResetInterface;

class DelayProcessor implements ResetInterface
{
    private ?float $start = null;

    public function __construct(private string $key = 'delay')
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        if (null === $this->start) {
            $this->start = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        }

        $record['extra'][$this->key] = number_format(microtime(true) - $this->start, 2);

        return $record;
    }

    public function reset(): void
    {
        $this->start = microtime(true);
    }
}

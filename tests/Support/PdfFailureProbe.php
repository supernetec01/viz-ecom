<?php

namespace Tests\Support;

use App\Exceptions\PdfInspectionFailed;
use App\Services\PdfSandbox;

class PdfFailureProbe extends PdfSandbox
{
    public bool $observedOom = false;

    public function __construct(private string $mode) {}

    protected function exec(string $container, array $arguments, float $deadline, array $allowed = [0], int &$exit = 0): string
    {
        if ($arguments[0] === '/usr/bin/qpdf') {
            try {
                return parent::exec($container, $this->mode === 'missing' ? ['/missing-qpdf'] : ['/usr/local/bin/sandbox-probe', $this->mode], $deadline, $allowed, $exit);
            } catch (PdfInspectionFailed $exception) {
                if ($this->mode === 'memory') {
                    $events = parent::exec($container, ['/bin/cat', '/sys/fs/cgroup/memory.events'], $deadline);
                    $this->observedOom = preg_match('/^oom_kill [1-9][0-9]*$/m', $events) === 1;
                }
                throw $exception;
            }
        }

        return parent::exec($container, $arguments, $deadline, $allowed, $exit);
    }
}

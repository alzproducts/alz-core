<?php

declare(strict_types=1);

namespace App\DevTools\GitHooks;

use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

/**
 * A Process whose stop() also kills every descendant. Symfony signals only the direct child, so a timed-out `make test`
 * would leave its ParaTest workers running.
 */
final class ProcessTreeProcess extends Process
{
    /**
     * @throws ProcessRuntimeException When the process list cannot be read
     */
    public function stop(float $timeout = 10, ?int $signal = null): ?int
    {
        $pid = $this->getPid();
        if ($pid !== null) {
            $this->killTree($pid);
        }

        return parent::stop($timeout, $signal);
    }

    /**
     * intentional: not a process group. A terminal Ctrl-C reaches only the foreground group, so the tree would outlive it.
     * Each process is frozen before the next listing, so none can fork, or exit and re-parent its children, unseen.
     *
     * @throws ProcessRuntimeException When the process list cannot be read
     */
    private function killTree(int $rootPid): void
    {
        $frozen = [];
        $found = [$rootPid];

        try {
            while ($found !== []) {
                foreach ($found as $pid) {
                    \posix_kill($pid, \SIGSTOP);
                    $frozen[] = $pid;
                }

                $found = $this->childrenOf($frozen);
            }
        } finally {
            foreach ($frozen as $pid) {
                \posix_kill($pid, \SIGKILL);
            }
        }
    }

    /**
     * @param list<int> $parents
     *
     * @return list<int> Children of the given processes that are not among them
     *
     * @throws ProcessRuntimeException When the process list cannot be read
     */
    private function childrenOf(array $parents): array
    {
        $listing = new Process(['ps', '-A', '-o', 'pid=,ppid=']);
        $listing->mustRun();

        \preg_match_all('/^\s*(\d+)\s+(\d+)\s*$/m', $listing->getOutput(), $rows, \PREG_SET_ORDER);

        $children = [];
        foreach ($rows as [, $pid, $parentPid]) {
            if (\in_array((int) $parentPid, $parents, true) && ! \in_array((int) $pid, $parents, true)) {
                $children[] = (int) $pid;
            }
        }

        return $children;
    }
}

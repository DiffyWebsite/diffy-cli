<?php

namespace DiffyCli\Commands;

use Diffy\Diff;

trait WaitForDiffTrait
{
    /**
     * Poll a diff until it completes, fails or $maxWait seconds pass.
     *
     * @return string|null Why the diff did not complete, null if it did.
     */
    protected function waitForDiff(int $diffId, int $maxWait, int $sleep = 10): ?string
    {
        $diff = Diff::retrieve($diffId);
        $waited = 0;

        while (!$diff->isCompleted() && !$diff->isFailed() && $waited < $maxWait) {
            sleep($sleep);
            $waited += $sleep;
            $diff->refresh();
        }

        return $this->getDiffWaitError($diff, $maxWait);
    }

    protected function getDiffWaitError(Diff $diff, int $maxWait): ?string
    {
        if ($diff->isCompleted()) {
            return null;
        }

        if ($diff->isFailed()) {
            // Diff::getStateName() only names the states a diff goes through on its way to completion.
            $state = (int) $diff->data['state'];

            return sprintf('Diff %d failed (%s).', $diff->diffId, $state === -1 ? 'stopped' : 'state ' . $state);
        }

        return sprintf('Diff %d did not complete within %d seconds. It keeps running on Diffy.', $diff->diffId, $maxWait);
    }
}

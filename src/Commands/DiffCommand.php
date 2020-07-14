<?php

namespace DiffyCli\Commands;

use Diffy\Diff;
use Diffy\Diffy;
use Diffy\InvalidArgumentsException;
use DiffyCli\Config;

class DiffCommand extends \Robo\Tasks
{
    /**
     * Create a diff from screenshots
     *
     * @command diff:create
     *
     * @param int $projectId ID of the project
     * @param int $screenshotId1 ID of the first screenshot to compare
     * @param int $screenshotId2 ID of the second screenshot to compare
     *
     * @param array $options
     * @throws InvalidArgumentsException
     * @option wait Wait for the diff to be completed
     * @option max-wait Maximum number of seconds to wait for the diff to be completed.
     *
     * @usage diff:create 342 1221 1223 Compare screenshots 1221 and 1223.
     * @usage diff:create --wait 342 1221 1223 Compare screenshots 1221 and 1223 and wait for the diff to be completed.
     */
    public function createDiff(
        int $projectId,
        int $screenshotId1,
        int $screenshotId2,
        array $options = ['wait' => false, 'max-wait' => 1200]
    ) {

        $apiKey = Config::getConfig()['key'];

        Diffy::setApiKey($apiKey);
        $diffId = Diff::create($projectId, $screenshotId1, $screenshotId2);

        if (!empty($options['wait']) && $options['wait'] == true) {
            $sleep = 10;
            $max_wait = (int)$options['max-wait'];
            sleep($sleep);
            $i = 0;
            $diff = Diff::retrieve($diffId);
            while ($i < $max_wait / $sleep) {
                if ($diff->isCompleted() || $diff->isFailed()) {
                    break;
                }
                sleep($sleep);
                $diff->refresh();

                $i += $sleep;
            }
        }

        $this->io()->write($diffId);
    }

    /**
     * Get diff status.
     *
     * @command diff:get-status
     *
     * @param int $diffId
     *
     * @return mixed
     * @throws \Exception
     *
     * @usage diff:get-status 12345 Get diff status.
     */
    public function getDiffStatus(int $diffId)
    {
        $apiKey = Config::getConfig()['key'];
        Diffy::setApiKey($apiKey);
        $diff = Diff::retrieve($diffId);

        $this->io()->write($diff->isCompleted());
    }

    /**
     * Get diff status.
     *
     * @command diff:get-changes-percent
     *
     * @param int $diffId
     *
     * @return mixed
     * @throws \Exception
     * @usage diff:get-changes-percent 12345 Get diff changes percent.
     */
    public function getDiffPercent(int $diffId)
    {
        $apiKey = Config::getConfig()['key'];
        Diffy::setApiKey($apiKey);
        $diff = Diff::retrieve($diffId);

        $this->io()->write($diff->getChangesPercentage());
    }

    /**
     * Get diff status.
     *
     * @command diff:get-list
     *
     * @param int $projectId
     * @param int $page
     *
     * @return mixed
     * @throws \Exception
     * @usage diff:get-list 12345 1 Get diffs list for project (page 1).
     */
    public function getDiffs(int $projectId, $page = 0)
    {
        $apiKey = Config::getConfig()['key'];
        Diffy::setApiKey($apiKey);
        $diffs = Diff::list($projectId, $page);

        $numberItemsOnPage = isset($diffs['numberItemsOnPage']) ? $diffs['numberItemsOnPage'] : 0;
        $totalPages = isset($diffs['totalPages']) ? $diffs['totalPages'] : 0;

        $headers = ['id', 'changes', 'state', 'jobs', 'estimate', 'sharedUrl'];
        $results = [];

        if (isset($diffs['diffs']) && !empty($diffs['diffs'])) {
            foreach ($diffs['diffs'] as $diff) {
                $results[] = [
                    'id' => $diff['id'],
                    'changes' => $diff['changes'],
                    'state' => Diff::getStateName($diff['state']),
                    'jobs' => $diff['status']['jobs'],
                    'estimate' => ($diff['status']['jobs'] > 0) ? $diff['status']['estimate'] : 'Finished',
                    'sharedUrl' => $diff['sharedUrl'],
                ];
            }
        }

        $this->io()->table($headers, $results);

        $this->io()->definitionList(
            ['Number items on page' => $numberItemsOnPage],
            ['Total pages' => $totalPages],
            ['Current page' => $page]
        );
    }
}

<?php

namespace DiffyCli\Commands;

use Diffy\Diff;
use Diffy\Diffy;
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
     * @throws \Diffy\InvalidArgumentsException
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
            $max_wait = (int) $options['max-wait'];
            sleep($sleep);
            $i = 0;
            $diff = Diff::retrieve($diffId);
            while ($i < $max_wait / $sleep) {
                if ($diff->isCompleted()) {
                    break;
                }
                sleep($sleep);
                $diff->refresh();

                $i += $sleep;
            }
        }

        $this->io()->write($diffId);
    }
}

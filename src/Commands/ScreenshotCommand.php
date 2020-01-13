<?php

namespace DiffyCli\Commands;

use Diffy\Diffy;
use Diffy\Screenshot;
use DiffyCli\Config;

class ScreenshotCommand extends \Robo\Tasks
{
    /**
     * Create a screenshot from environment
     *
     * @command screenshot:create
     *
     * @param int $projectId ID of the project
     * @param string $environment Environment of the project. Can be one of "production", "staging", "development", "custom"
     *
     * @param array $options
     * @throws \Diffy\InvalidArgumentsException
     * @option wait Wait for the screenshot to be completed
     * @option max-wait Maximum number of seconds to wait for the screenshot to be completed.
     *
     * @usage screenshot:create 342 production Take screenshot from production on project 342.
     * @usage screenshot:create 342 production --wait Take the screenshot and wait till they are completed.
     */
    public function createScreenshot($projectId, $environment, array $options = ['wait' => false, 'max-wait' => 1200])
    {
        $apiKey = Config::getConfig()['key'];

        Diffy::setApiKey($apiKey);
        $screenshotId = Screenshot::create($projectId, $environment);

        if (!empty($options['wait']) && $options['wait'] == true) {
            $sleep = 10;
            $max_wait = (int) $options['max-wait'];
            sleep($sleep);
            $i = 0;
            $screenshot = Screenshot::retrieve($screenshotId);
            while ($i < $max_wait / $sleep) {
                if ($screenshot->isCompleted()) {
                    break;
                }
                sleep($sleep);
                $screenshot->refresh();

                $i += $sleep;
            }
        }

        $this->io()->write($screenshotId);
    }
}

<?php

namespace DiffyCli\Commands;

use Diffy\Diffy;
use Diffy\Screenshot;
use DiffyCli\Config;
use GuzzleHttp\Exception\InvalidArgumentException;
use Robo\Tasks;

use function GuzzleHttp\json_decode;

class ScreenshotCommand extends Tasks
{
    /**
     * Create a screenshot from environment
     *
     * @command screenshot:create
     *
     * @param int    $projectId   ID of the project
     * @param string $environment Environment of the project. Can be one of "production", "staging", "development", "custom" (short options: "prod", "stage", "dev")
     *
     * @param array  $options
     *
     * @throws \Diffy\InvalidArgumentsException
     *
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

        if ($environment === 'prod') {
            $environment = 'production';
        } elseif ($environment === 'dev') {
            $environment = 'development';
        } elseif ($environment === 'stage') {
            $environment = 'staging';
        }

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

    /**
     * Get the list of screenshots
     *
     * @command screenshot:list
     *
     * @param int   $projectId ID of the project
     *
     * @param array $options
     *
     * @throws \Diffy\InvalidArgumentsException
     *
     * @option name Filter by the title of the screenshot
     * @option limit Number of results to return
     *
     * @usage screenshot:list 342 return all the screenshots for the project 32.
     * @usage screenshot:list 342 --name="feature-branch-5" --limit=1 retrieve details of the last screenshot named "feature-branch-5"
     * @usage screenshot:list 18765 --limit=1 --name="Demo screenshot staging" | grep \'id\' | php -r 'print(preg_replace("/[^0-9]/", "", stream_get_contents(STDIN)));'
     *      Extract ID of the latest screenshot with the name "Demo screenshot staging"
     */
    public function listScreenshot($projectId, array $options = ['name' => '', 'limit' => 0])
    {
        $apiKey = Config::getConfig()['key'];

        Diffy::setApiKey($apiKey);
        $list = Screenshot::all($projectId);

        $screenshots = $list['screenshotsForDiff'];
        // Do not include baseline.
        array_shift($screenshots);

        if (!empty($options['name'])) {
            $filtered = [];
            foreach ($screenshots as $screenshot) {
                if ($screenshot['name'] == $options['name']) {
                    $filtered[] = $screenshot;
                }
            }
            $screenshots = $filtered;
        }

        if (!empty($options['limit'])) {
            $screenshots = array_slice($screenshots, 0, $options['limit']);
        }

        $this->io()->write(var_export($screenshots, true));
    }

    /**
     * Create a screenshot from uploade images
     *
     * @command screenshot:create-uploaded
     *
     * @param int    $projectId         ID of the project
     * @param string $configurationPath Path to the json config file.
     *                                  Json encoded array of snapshotName and arrays "files", "breakpoints", "urls".
     *
     * @usage screenshot:create-uploaded 342 ./diffy_create_screenshot_upload.json
     */
    public function createScreenshotUpload($projectId, string $configurationPath)
    {
        $apiKey = Config::getConfig()['key'];

        Diffy::setApiKey($apiKey);

        $configuration = file_get_contents($configurationPath);

        if (!$configuration) {
            $this->io()->write(sprintf('Configuration not found on path : %s', $configurationPath));
            throw new InvalidArgumentException();
        }

        try {
            $configuration = json_decode($configuration, true);
        } catch (InvalidArgumentException $exception) {
            $this->io()->write('Configuration is not valid JSON ');
            throw $exception;
        }

        $screenshotId = Screenshot::createUpload($projectId, $configuration);

        $this->io()->write($screenshotId);
    }

    /**
     * Creates a new baseline from a given environment
     *
     * @command screenshot:create-baseline
     *
     * @param int $projectId ID of the project
     * @param string $environment Environment of the project. Can be one of "production", "staging", "development", "custom"
     *
     * @param array $options
     *
     * @throws \Diffy\InvalidArgumentsException
     *
     * @option wait Wait for the screenshot to be completed
     * @option max-wait Maximum number of seconds to wait for the screenshot to be completed.
     *
     * @usage screenshot:create-baseline 342 production Take screenshot from production on project 342.
     * @usage screenshot:create-baseline 342 production --wait Take the screenshot and wait till they are completed.
     */
    public function createScreenshotBaseline($projectId, $environment, array $options = ['wait' => false, 'max-wait' => 1200])
    {
        $screenshotId = $this->createScreenshot($projectId, $environment, $options);
        $this->setBaselineSet($projectId, $screenshotId);
    }

    /**
     * Sets a new baseline from a screenshot ID.
     *
     * @command screenshot:set-baseline
     *
     * @param int $projectId ID of the project
     * @param int $screenshotId The screenshot ID to be the baseline.
     *
     * @usage screenshot:set-baseline 342 4325 Set the baseline for project to be screenshot ID.
     */
    public function setScreenshotBaseline($projectId, $screenshotId)
    {
        $apiKey = Config::getConfig()['key'];

        Diffy::setApiKey($apiKey);

        Screenshot::setBaselineSet($projectId, $screenshotId);
        $this->io()->write(sprintf('Baseline for project %d has been updated.', $projectId));
    }
}

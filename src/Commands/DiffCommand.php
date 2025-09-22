<?php

namespace DiffyCli\Commands;

use Diffy\Diff;
use Diffy\Diffy;
use Diffy\InvalidArgumentsException;
use DiffyCli\Config;
use N98\JUnitXml\Document;
use Robo\ResultData;
use Robo\Tasks;

class DiffCommand extends Tasks
{
    /**
     * Create a diff from screenshots
     *
     * @command diff:create
     *
     * @param int   $projectId     ID of the project
     * @param int   $screenshotId1 ID of the first screenshot to compare
     * @param int   $screenshotId2 ID of the second screenshot to compare
     * @param array $options
     *
     * @throws InvalidArgumentsException
     *
     * @option wait Wait for the diff to be completed
     * @option max-wait Maximum number of seconds to wait for the diff to be completed.
     * @option name Custom diff name
     * @option notifications Send an email notification when the diff is completed
     *
     * @usage diff:create 342 1221 1223
     *   Compare screenshots 1221 and 1223.
     * @usage diff:create --wait --name="custom name" 342 1221 1223
     *   Compare screenshots 1221 and 1223 and wait for the diff to be completed and set the name for the diff "custom name".]
     * @usage diff:create 342 1221 1223 --notifications="test@icloud.com,test@gmail.com"
     *   Compare screenshots 1221 and 1223. When the comparison is completed, send a notification with the comparison to test@icloud.com and test@gmail.com.
     */
    public function createDiff(
        int $projectId,
        int $screenshotId1,
        int $screenshotId2,
        array $options = ['wait' => false, 'max-wait' => 1200, 'name' => '', 'notifications' => '']
    ) {
        $apiKey = Config::getConfig()['key'];

        Diffy::setApiKey($apiKey);

        $diffId = Diff::create($projectId, $screenshotId1, $screenshotId2, $options);

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

        // Successful exit.
        return new ResultData();
    }

    /**
     * Get diff status
     *
     * @command diff:get-status
     *
     * @param int $diffId
     *
     * @return mixed
     *
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

        // Successful exit.
        return new ResultData();
    }

    /**
     * Get diff changes percentage
     *
     * @command diff:get-changes-percent
     *
     * @param int $diffId
     *
     * @return mixed
     *
     * @throws \Exception
     *
     * @usage diff:get-changes-percent 12345 Get diff changes percent.
     */
    public function getDiffPercent(int $diffId)
    {
        $apiKey = Config::getConfig()['key'];
        Diffy::setApiKey($apiKey);
        $diff = Diff::retrieve($diffId);

        $this->io()->write($diff->getChangesPercentage());

        // Successful exit.
        return new ResultData();
    }

    /**
     * Get diff result
     *
     * @command diff:get-result
     *
     * @param int $diffId
     *
     * @option format Format of the output (supported: junit-xml)
     *
     * @return mixed
     *
     * @throws \Exception
     *
     * @usage diff:get-result 12345 --format=junit-xml Get diff result.
     */
    public function getDiffResult(int $diffId, array $options = ['format' => ''])
    {
        $format = $options['format'] ?? '';

        if (!$format) {
            $this->io()->error('Provide --format="value" option');
            return;
        } elseif (!in_array($format, ['junit-xml'])) {
            $this->io()->error('Format value is not supported. Provide one of the following values: junit-xml.');
            return;
        }

        if ($format === 'junit-xml' && !extension_loaded('dom')) {
            $this->io()->error('PHP dom extension not loaded');
            return;
        }

        $apiKey = Config::getConfig()['key'];
        Diffy::setApiKey($apiKey);
        $diff = Diff::retrieve($diffId);

        if (!in_array($diff->data['state'], [2, 3, 4])) {
            $this->io()->error('Diff is not completed. Retry later.');
            return;
        }

        $document = new Document();

        /** @var \DOMElement $rootElement */
        $rootElement = $document->childNodes[0];
        $rootElement->setAttribute('name', $diff->data['name']);

        $generalFailures = 0;
        $generalTotal = 0;
        $i = 0;

        foreach ($diff->data['diffs'] as $url => $breakpoints) {
            $suite = $document->addTestSuite();
            $suite->setName($url);

            $failures = 0;

            foreach ($breakpoints as $breakpoint => $item) {
                $testCase = $suite->addTestCase();
                $testCase->setAttribute('classname', 'report');
                $testCase->setAttribute('name', 'Device size: ' . $breakpoint);
                $testCase->setAttribute('file', $diff->data['diffSharedUrl'] . '/single/' . $i . '/' . $breakpoint);

                $percentageChanges = $item['percentageChanges'] ?? 0;

                if ($percentageChanges) {
                    $failures++;

                    $testCase->addFailure($percentageChanges . '% of the page contains changes', 'error');
                }
            }

            $suite->setAttribute('tests', count($breakpoints));
            $suite->setAttribute('failures', $failures);
            $suite->setAttribute('errors', 0);
            $suite->setAttribute('skipped', 0);

            $generalTotal += count($breakpoints);
            $generalFailures += $failures;
            $i++;
        }

        $rootElement->setAttribute('tests', $generalTotal);
        $rootElement->setAttribute('failures', $generalFailures);
        $rootElement->setAttribute('errors', 0);
        $rootElement->setAttribute('skipped', 0);

        $this->io()->write($document->saveXML());

        // Successful exit.
        return new ResultData();
    }

    /**
     * Get diffs list
     *
     * @command diff:list
     *
     * @param int $projectId
     * @param int $page
     *
     * @return mixed
     *
     * @throws \Exception
     *
     * @usage diff:list 12345 1 Get diffs list for project (page 1).
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
                    'sharedUrl' => $diff['sharedUrl'] ?? NULL,
                ];
            }
        }

        $this->io()->table($headers, $results);

        $this->io()->definitionList(
            ['Number items on page' => $numberItemsOnPage],
            ['Total pages' => $totalPages],
            ['Current page' => $page]
        );

        // Successful exit.
        return new ResultData();
    }
}

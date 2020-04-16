<?php

namespace DiffyCli\Commands;

use Diffy\Screenshot;
use Diffy\Diffy;
use Diffy\Project;
use DiffyCli\Config;
use DiffyCli\BrowserStack;

/**
 *
 *
 * Class BrowserStackCommand
 * @package DiffyCli\Commands
 */
class BrowserStackCommand extends \Robo\Tasks
{

    private $browserStack;

    public function __construct()
    {
        $username = 'sergeygrigorenko1';
        $password = 'bfsqmbvQQBkLCYfgzzwY';

        $this->browserStack = new BrowserStack($username, $password);
        $this->waitScreenshotsInterval = 10; // Seconds
    }

    /**
     * Get browserstack browsers key list.
     *
     * @command diffy:browserstack-list
     */
    public function browserKeysList()
    {
        $browsers = $this->browserStack->getBrowsers();
        $headers = ['browser', 'browser version', 'os', 'os version', 'browser key'];
        $rows = [];

        usort(
            $browsers,
            function ($a, $b) {
                return strcmp($a['browser'], $b['browser']);
            }
        );

        foreach ($browsers as $id => $browser) {
            $keyData = $this->prepareBrowserStackKey($browser);
            if (!empty($keyData)) {
                $rows[] = $keyData;
            }
        }

        $this->io()->table($headers, $rows);
    }


    /**
     * Create screenshots via browserstack and upload them to Diffy.
     *
     * @command diffy:browserstack-screenshot
     *
     * @param int $projectId ID of the project
     * @param string $baseUrl Site base url
     * @param string $browserStackKeys BrowserStack keys: (safari--6.0--OS__X--Lion,firefox--39.0--Windows--8)
     * @throws \Exception
     */
    public function browserStackScreenshot(int $projectId, string $baseUrl, string $browserStackKeys)
    {
        $this->io()->title('Starting...');
        $browserStackKeys = explode(',', $browserStackKeys);
        $this->io()->writeln("Diffy project ID: $projectId");
        $this->io()->writeln("Base url: $baseUrl");

        $this->io()->writeln("BrowserStackKeys:");
        foreach ($browserStackKeys as $key) {
            $this->io()->writeln("  $key");
        }

        $apiKey = Config::getConfig()['key'];
        Diffy::setApiKey($apiKey);
        $project = Project::get($projectId);

        $urls = $project['urls'];
        $production = rtrim($project['production'], '/');

        $baseUrl = rtrim($baseUrl, '/');
        $urls = array_map(
            function ($url) use ($baseUrl, $production) {
                return str_replace($production, $baseUrl, $url);
            },
            $urls
        );

        $this->io()->newLine();
        $this->io()->title("Start processing ".count($urls) ." URLs");
        // Start screenshots.
        $screenshotResults = [];

        $browsers = [];
        foreach ($browserStackKeys as $key) {
            $browsers[] = $this->getBrowserStackParams($key);
        }


        // Init progress bar.
        $progress = $this->io()->createProgressBar(count($urls));
        $progress->setFormat("%message%\n %current%/%max% [%bar%] %percent:3s%%");

        foreach ($urls as $i => $url) {
            $progress->setMessage("Processing screenshot: $url");
            $progress->setProgress($i);
            $item = $this->browserStack->createScreenshot($url, $browsers);

            if (isset($item['job_id'])) {
                // Get screenshot results
                $result = $this->awaitScreenshotResult($item['job_id'], $progress);
                $progress->setProgress($i+1);
                foreach ($result['screenshots'] as $key => $value) {
                    $imageUrl = $value['image_url'];
                    $browserStackKey = $this->prepareBrowserStackKey($value, true);
                    $uri = str_replace($baseUrl, '', $url);
                    $uri = '/'.ltrim($uri, '/');

                    $screenshotResults[] = [
                        'key' => $browserStackKey,
                        'imageUrl' => $imageUrl,
                        'breakpoint' => getimagesize($imageUrl)[0],
                        'url' => $url,
                        'uri' => $uri,
                    ];
                }
            } else {
                $errors = [];
                if (isset($item['message'])) {
                    $errors[] = $item['message'];
                }
                if (isset($item['errors'])) {
                    if (is_array($item['errors'])) {
                        foreach ($item['errors'] as $error) {
                            $errors[] = var_export($error, true);
                        }
                    }
                }

                if (empty($errors)) {
                    $errors = var_export($item, true);
                }
                $this->io()->error($errors);
            }
        }

        $progress->finish();

        $this->io()->newLine();
        $this->io()->title('Screenshots have been created successfully');
        $headers = ['browserStackKey', 'imageUrl', 'breakpoint', 'url', 'uri'];
        $this->io()->table($headers, $screenshotResults);


        $this->io()->title('Start uploading data to Diffy.');
        $screenshotId = Screenshot::createBrowserStackScreenshot($projectId, $screenshotResults);
        $screenshotLink = rtrim(Diffy::$uiBaseUrl, '/') . '/snapshots/' . $screenshotId;
        $this->io()->success("Screenshot was successfully created. Screenshot: " . $screenshotLink);
    }


    /**
     * Getting screenshot results from BrowserStack.
     *
     * @param $screenshotJobId
     * @param $progress
     * @param int $counter
     * @return mixed
     * @throws \Exception
     */
    private function awaitScreenshotResult($screenshotJobId, $progress, $counter = 0)
    {
        $item = $this->browserStack->getListOfScreenshots($screenshotJobId);
        if ($item['status']) {
            return $item['data'];
        } else {
            $counter++;
            $message = "Waiting for screenshot job: $screenshotJobId ";
            for ($i=0; $i<=$counter; $i++) {
                $message .= '.';
            }
            $progress->setMessage($message);
            $progress->display();
            sleep($this->waitScreenshotsInterval);

            return $this->awaitScreenshotResult($screenshotJobId, $progress, $counter);
        }
    }

    /**
     * Get browserstack query params from browserstack key.
     *
     * @param $browserStackKey
     * @return array
     */
    private function getBrowserStackParams($browserStackKey)
    {
        $params = explode("--", $browserStackKey);
        foreach ($params as &$param) {
            $param = str_replace('__', ' ', $param);
        }

        return $params;
    }


    /**
     * Create browserstack key from browserstack browser params.
     *
     * @param $browser
     * @param bool $onlyKey
     * @return array|string
     */
    private function prepareBrowserStackKey($browser, $onlyKey = false)
    {
        $keyData = '';
        $key = '';
        if (!empty($browser['browser']) && !empty($browser['browser_version']) && !empty($browser['os']) && !empty($browser['os_version'])) {
            $browserName = str_replace(' ', '__', $browser['browser']);
            $browserVersion = str_replace(' ', '__', $browser['browser_version']);
            $os = str_replace(' ', '__', $browser['os']);
            $osVersion = str_replace(' ', '__', $browser['os_version']);

            $key = sprintf('%s--%s--%s--%s', $browserName, $browserVersion, $os, $osVersion);
            $keyData = [
                $browser['browser'],
                $browser['browser_version'],
                $browser['os'],
                $browser['os_version'],
                $key,
            ];
        }

        return ($onlyKey) ? $key : $keyData;
    }
}

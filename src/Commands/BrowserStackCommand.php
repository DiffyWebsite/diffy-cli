<?php

namespace DiffyCli\Commands;

use Diffy\Diff;
use Diffy\Diffy;
use Diffy\InvalidArgumentsException;
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
    }


    /**
     * @command diffy:browserstack-screenshot
     *
     * @param int $projectId ID of the project
     * @param string $baseUrl Site base url
     * @param string $browserStackKeys BrowserStack keys (safari--6.0--OS__X--Lion,firefox--39.0--Windows--8)
     */
    public function browserstackScreenshot(int $projectId, string $baseUrl, string $browserStackKeys)
    {
        $browserStackKeys = explode(',', $browserStackKeys);
        $this->io()->writeln($projectId);
        $this->io()->writeln($baseUrl);

        foreach ($browserStackKeys as $key) {
            $this->io()->writeln($key);
        }


        $apiKey = Config::getConfig()['key'];
        Diffy::setApiKey($apiKey);
        $project = Project::get($projectId);

        $urls = $project['urls'];
        $production = rtrim($project['production'], '/');
        $name = $project['name'];
        $authenticate = $project['authenticate'];

        $baseUrl = rtrim($baseUrl, '/');
        $urls = array_map(
            function ($url) use ($baseUrl, $production) {
                return str_replace($production, $baseUrl, $url);
            },
            $urls
        );

        var_export($urls);

        // Start screenshots.
        $screenshotResults = [];

        $browsers = [];
        foreach ($browserStackKeys as $key) {
            //list($browser, $browser_version, $os, $os_version) = $this->getBrowserStackParams($key);
            $browsers[] = $this->getBrowserStackParams($key);
        }

        foreach ($urls as $url) {
            $item = $this->browserStack->createScreenshot($url, $browsers);

            if (isset($item['job_id'])) {
                // Get screenshot results
                $result = $this->awaitScreenshotResult($item['job_id']);
                //var_export($result);
                $win_res = $result['win_res'];
                foreach ($result['screenshots'] as $key => $value) {
                    $imageUrl = $value['image_url'];
                    $browserStackKey = $this->prepareBrowserStackKey($value, true);
                    $screenshotResults[] = [
                        'key' => $browserStackKey,
                        'imageUrl' => $imageUrl,
                        'breakpoint' => getimagesize($imageUrl)[0],
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
        var_export($screenshotResults);
    }

    private function awaitScreenshotResult($screenshotJobId)
    {

        $item = $this->browserStack->getListOfScreenshots($screenshotJobId);
        if ($item['status']) {
            return $item['data'];
        } else {
            print "Next call: ID".$screenshotJobId.PHP_EOL;
            sleep(5);

            return $this->awaitScreenshotResult($screenshotJobId);
        }
    }

    private function getBrowserStackParams($browserStackKey)
    {
        // firefox--37.0--OS__X--High__Sierra
        $params = explode("--", $browserStackKey);
        foreach ($params as &$param) {
            $param = str_replace('__', ' ', $param);
        }

        return $params;
    }

    /**
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
     * Create browser key.
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

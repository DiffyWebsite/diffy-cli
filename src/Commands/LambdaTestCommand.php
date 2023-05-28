<?php

namespace DiffyCli\Commands;

use Diffy\InvalidArgumentsException;
use Diffy\Screenshot;
use Diffy\Diffy;
use Diffy\Project;
use DiffyCli\Config;
use DiffyCli\LambdaTest;
use Exception;
use Robo\Tasks;

/**
 * Integration with lambdatest.com.
 *
 * Class LambdaTestCommand
 *
 * @package DiffyCli\Commands
 */
class LambdaTestCommand extends Tasks
{
    /** @var LambdaTest */
    protected $lambdaTest;

    protected $waitScreenshotsInterval = 5; // Seconds

    protected $lambdaTestWaitValues = [2, 5, 10, 15, 20, 60];

    /**
     * Save LambdaTest credentials
     *
     * @command lambdatest:save-credentials
     *
     * @param string $username    You Lambdatest username. Obtain your username at https://accounts.lambdatest.com/detail/profile
     * @param string $accessToken Your Lambdatest Access Token. Obtain your key at https://accounts.lambdatest.com/detail/profile
     *
     * @usage lambdatest:save-credentials <username> <access_token> Saves the username <username> and access Key <access_token> to configuration for future use.
     *
     * @throws Exception
     */
    public function saveLambdaTestCredentials($username, $accessToken)
    {
        Config::saveLambdaTestCredentials($username, $accessToken);
        $this->io()->success('LambdaTest username and access token saved');
    }

    /**
     * Get LambdaTest browsers key list
     *
     * @command lambdatest:browsers-list
     *
     * @usage lambdatest:browsers-list Shows available browsers and browser keys.
     */
    public function browserKeysList()
    {
        $this->login();
        $response = $this->lambdaTest->getBrowsers();
        $headers = ['os', 'browser', 'browser version', 'browser key'];
        $rows = [];

        foreach ($response as $os => $os_row) {
            foreach ($os_row as $browser => $browser_row) {
                foreach ($browser_row as $browser_version) {
                    $rows[] = [$os, $browser, $browser_version, $this->prepareLambdaTestKey($os, $browser, $browser_version) ];
                }
            }
        }

        $this->io()->table($headers, $rows);
    }

    /**
     * Create screenshots via LambdaTest and upload them to Diffy
     *
     * @command lambdatest:screenshot
     *
     * @param int    $projectId      ID of the project
     * @param string $baseUrl        Site base url
     * @param string $lambdaTestKeys lambdatest keys: (windows__10--opera--75,windows__10--chrome--90,macos__big__sur--firefox--87,)
     * @param array  $options
     *
     * @throws InvalidArgumentsException
     *
     * @usage lambdatest:screenshot 1194 http://site.com windows__10--opera--75 Create screenshot for project with ID 1194, base url http://site.com and one browser windows__10--opera--75.
     * @usage lambdatest:screenshot --wait=10 1194 http://site.com windows__10--opera--75,windows__10--chrome--90 Create screenshot for project with ID 1194, base url http://site.com and two browsers windows__10--opera--75 and windows__10--chrome--90 with delay before screenshot 10 seconds.
     */
    public function lambdatestScreenshot(
        int $projectId,
        string $baseUrl,
        string $lambdaTestKeys,
        array $options = ['wait' => 5]
    ) {
        $waitTime = (int)$options['wait'];

        if (!in_array($waitTime, $this->lambdaTestWaitValues)) {
            throw new Exception('--wait option should be one of ' . implode(', ', $this->lambdaTestWaitValues));
        }

        $this->login();
        $this->io()->title('Starting...');
        $lambdaTestKeys = explode(',', $lambdaTestKeys);
        $this->io()->writeln("Diffy project ID: $projectId");
        $this->io()->writeln("Base url: $baseUrl");

        $this->io()->writeln('lambdatestKeys:');
        foreach ($lambdaTestKeys as $key) {
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
        $this->io()->title('Start processing ' . count($urls) . ' URLs');
        // Start screenshots.
        $screenshotResults = [];

        $browsers = $this->getLambdaTestParams($lambdaTestKeys);

        // Init progress bar.
        $progress = $this->io()->createProgressBar(count($urls));
        $progress->setFormat("%message%\n %current%/%max% [%bar%] %percent:3s%%");

        foreach ($urls as $i => $url) {
            $progress->setMessage("Processing screenshot: $url");
            $progress->setProgress($i);
            $item = $this->lambdaTest->createScreenshot($url, $browsers, $waitTime);

            if ($item && isset($item['test_id'])) {
                // Get screenshot results
                $result = $this->awaitScreenshotResult($item['test_id'], $progress);
                $progress->setProgress($i + 1);

                foreach ($result['screenshots'] as $value) {
                    $imageUrl = str_replace('.comprod/', '.com/prod/', $value['screenshot_url']);
                    $lambdaTestKey = $this->prepareLambdaTestKey($value['os'], $value['browser'], $value['browser_version']);
                    $uri = str_replace($baseUrl, '', $url);
                    $uri = '/' . ltrim($uri, '/');

                    list($width, $height) = explode('x', $value['resolution']);
                    $screenshotResults[] = [
                        'key' => $lambdaTestKey,
                        'imageUrl' => $imageUrl,
                        'breakpoint' => $width,
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
                throw new Exception('Can\'t start job.');
            }
        }

        $progress->finish();

        $this->io()->newLine();
        $this->io()->title('Screenshots have been created successfully');
        $headers = ['lambdatestKey', 'imageUrl', 'breakpoint', 'url', 'uri'];
        $this->io()->table($headers, $screenshotResults);

        $this->io()->title('Start uploading data to Diffy.');
        $screenshotId = Screenshot::createBrowserStackScreenshot($projectId, $screenshotResults);
        $screenshotLink = rtrim(Diffy::$uiBaseUrl, '/') . '/snapshots/' . $screenshotId;
        $this->io()->success('Screenshot was successfully created. Screenshot: ' . $screenshotLink);
    }

    /**
     * Login to LambdaTest
     *
     * @throws Exception
     */
    private function login()
    {
        $config = Config::getConfig();

        if (
            !isset($config['lambdaTestUsername']) || !isset($config['lambdaTestAccessToken'])
            || empty($config['lambdaTestUsername']) || empty($config['lambdaTestAccessToken'])
        ) {
            throw new Exception('lambdatest credentials are empty. Use command diffy `lambdatest:save-credentials <username> <access_token>` for add credentials.');
        }

        $this->lambdaTest = new LambdaTest($config['lambdaTestUsername'], $config['lambdaTestAccessToken']);
    }

    /**
     * Getting screenshot results from LambdaTest
     *
     * @param $screenshotJobId
     * @param $progress
     * @param int $counter
     *
     * @return mixed
     *
     * @throws Exception
     */
    private function awaitScreenshotResult($screenshotJobId, $progress, $counter = 0)
    {
        $item = $this->lambdaTest->getListOfScreenshots($screenshotJobId);

        if ($item['status']) {
            return $item['data'];
        } else {
            $counter++;
            $message = "Waiting for screenshot job: $screenshotJobId ";
            for ($i = 0; $i <= $counter; $i++) {
                $message .= '.';
            }
            $progress->setMessage($message);
            $progress->display();
            sleep($this->waitScreenshotsInterval);

            return $this->awaitScreenshotResult($screenshotJobId, $progress, $counter);
        }
    }

    /**
     * Get LambdaTest query params from LambdaTest key
     *
     * @param array $lambdaTestKeys
     *
     * @return array
     */
    private function getLambdaTestParams($lambdaTestKeys)
    {
        $browsers = [];

        foreach ($lambdaTestKeys as $lambdaTestKey) {
            $params = explode('--', $lambdaTestKey);
            $os = str_replace('__', ' ', $params[0]);
            $browser = str_replace('__', ' ', $params[1]);
            $browserVersion = str_replace('__', ' ', $params[2]);
            if (!isset($browsers[$os])) {
                $browsers[$os] = [];
            }
            if (!isset($browsers[$os][$browser])) {
                $browsers[$os][$browser] = [];
            }

            $browsers[$os][$browser][] = $browserVersion;
        }

        return $browsers;
    }

    /**
     * Create LambdaTest key from LambdaTest browser params
     *
     * @param $os
     * @param $browserName
     * @param $browserVersion
     *
     * @return string
     */
    private function prepareLambdaTestKey($os, $browserName, $browserVersion)
    {
        if (!empty($os) && !empty($browserName) && !empty($browserVersion)) {
            $browserNameUnd = str_replace(' ', '__', $browserName);
            $browserVersionUnd = str_replace(' ', '__', $browserVersion);
            $osUnd = str_replace(' ', '__', $os);

            return sprintf('%s--%s--%s', $osUnd, $browserNameUnd, $browserVersionUnd);
        }

        return '';
    }
}

<?php

namespace DiffyCli;

use mysql_xdevapi\Exception;

class BrowserStack
{
    private $username;
    private $password;

    private static $browserStackURL = "https://www.browserstack.com/screenshots";
    private static $browserStackURLAutomate = "https://api.browserstack.com/automate";

    public function __construct($username, $password)
    {
        $this->username = $username;
        $this->password = $password;
    }


    public function query($methodName, $request, $authRequired, $requestVerb, $isAutomate = false)
    {
        if ($isAutomate) {
            $baseURL = $url = (!empty($methodName)) ? BrowserStack::$browserStackURLAutomate.'/'.$methodName.'.json' : BrowserStack::$browserStackURLAutomate;
        } else {
            $baseURL = $url = (!empty($methodName)) ? BrowserStack::$browserStackURL.'/'.$methodName.'.json' : BrowserStack::$browserStackURL;
        }

        $ch = curl_init($baseURL);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $headers = array(
            'Content-type: application/json',
            'Accept: application/json',
        );
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($authRequired) {
            curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
        }
        switch ($requestVerb) {
            case 'GET':
                curl_setopt($ch, CURLOPT_HTTPGET, true);
                if (!empty($request)) {
                    if (is_array($request)) {
                        $fullURL = $baseURL.'?'.http_build_query($request);
                    } else {
                        $fullURL = $baseURL."/{$request}";
                    }
                } else {
                    $fullURL = $baseURL;
                }
                curl_setopt($ch, CURLOPT_URL, $fullURL);
                break;
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
                $fullURL = $baseURL."/{$request}";
                curl_setopt($ch, CURLOPT_URL, $fullURL);
                break;
        }
        $response = curl_exec($ch);

        curl_close($ch);

        return json_decode($response, true);
    }

    public function getBrowsers()
    {
        return $this->query('browsers', '', true, 'GET');
    }

    public function createScreenshot($url, array $browsers)
    {
        $browserStackBrowsers = [];
        foreach ($browsers as list($browser, $browser_version, $os, $os_version)) {
            $browserStackBrowsers[] = [
                'browser' => $browser,
                'browser_version' => $browser_version,
                'os' => $os,
                'os_version' => $os_version,
                'device' => null,
                'real_mobile' => null,
                'wait_time' => 10,
                'quality' => 'original',
            ];
        }

        $params = [
            'browsers' => $browserStackBrowsers,
            'url' => $url,
        ];

        return $this->query('', json_encode($params), true, 'POST');
    }

    public function createScreenshotSingleBrowser($url, $os = 'Windows', $os_version = 'XP', $browser = 'chrome', $browser_version = '21.0', $device = null, $real_mobile = null)
    {
        if ($this->validateBrowser($os, $os_version, $browser, $browser_version, $device, $real_mobile)) {
            $selectedBrowser = [
                'os' => $os,
                'os_version' => $os_version,
                'browser' => $browser,
                'browser_version' => $browser_version,
                'device' => $device,
                'real_mobile' => $real_mobile,
                'wait_time' => 10,
            ];

            $params = [
                'browsers' => [$selectedBrowser],
                'url' => $url,
            ];

            $result = $this->query('', json_encode($params), true, 'POST');

            //$jobId = $result['job_id'];

            return $result;
            //var_dump($result);
        } else {
            throw new Exception("Wrong browser params");
        }
    }


    public function getListOfScreenshots($jobId)
    {
        $result = $this->query($jobId, '', true, 'GET');

        print $result['state'].PHP_EOL;

        if (isset($result['state']) && $result['state'] == 'done') { // processing
            return ['status' => true, 'data' => $result];
        } else {
            return ['status' => false, 'data' => $result];
        }
    }

    private function validateBrowser($os, $os_version, $browser, $browser_version, $device, $real_mobile)
    {
        $browsers = $this->getBrowsers();
        $valid = false;

        $selectedBrowser = ['os' => $os, 'os_version' => $os_version, 'browser' => $browser, 'browser_version' => $browser_version, 'device' => $device, 'real_mobile' => $real_mobile];

        foreach ($browsers as $browser) {
            $exact = array_diff($selectedBrowser, $browser);
            if ($exact) {
                $valid = true;
                break;
            }
        }

        return $valid;
    }
}

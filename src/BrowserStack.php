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
        $this->waitTime = 10; // seconds.
        $this->quality = 'original';
    }

    /**
     * Get available browsers list.
     *
     * @return mixed
     */
    public function getBrowsers()
    {
        return $this->query('browsers', '', true, 'GET');
    }

    /**
     * Create screenshot job.
     *
     * @param $url
     * @param array $browsers
     * @return mixed
     */
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
                'wait_time' => $this->waitTime,
                'quality' => $this->quality,
            ];
        }

        $params = [
            'browsers' => $browserStackBrowsers,
            'url' => $url,
        ];

        return $this->query('', json_encode($params), true, 'POST');
    }


    /**
     * Get job results: list of screenshots.
     *
     * @param $jobId
     * @return array
     * @throws \Exception
     */
    public function getListOfScreenshots($jobId)
    {
        $result = $this->query($jobId, '', true, 'GET');

        if (!isset($result['state'])) {
            throw new \Exception('Bad response: '.var_export($result, true));
        }

        switch ($result['state']) {
            case 'queue':
            case 'queued_all':
                return ['status' => false, 'data' => $result];
                break;

            case 'done':
                return ['status' => true, 'data' => $result];
                break;
            default:
                throw new \Exception('Undefined state: '.$result['state']);
        }
    }

    /**
     * Run query to BrowserStack server.
     *
     * @param $methodName
     * @param $request
     * @param $authRequired
     * @param $requestVerb
     * @param bool $isAutomate
     * @return mixed
     */
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
}

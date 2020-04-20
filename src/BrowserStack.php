<?php

namespace DiffyCli;

use GuzzleHttp\Client;

class BrowserStack
{
    private $username;

    private $password;

    private $browserStackURL = "https://www.browserstack.com/";

    private $quality;

    private $client;

    public function __construct($username, $password)
    {
        $this->username = $username;
        $this->password = $password;
        $this->quality = 'original';

        $clientParams = [
            'base_uri' => $this->browserStackURL,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'auth' => [$this->username, $this->password]
        ];

        $this->client = new Client($clientParams);
    }

    /**
     * Get available browsers list.
     *
     * @return mixed
     */
    public function getBrowsers()
    {
        return $this->query('browsers', 'GET');
    }

    /**
     * Create screenshot job.
     *
     * @param $url
     * @param array $browsers
     * @param int $waitTime
     * @return mixed
     */
    public function createScreenshot($url, array $browsers, int $waitTime = 5)
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
            ];
        }

        $params = [
            'browsers' => $browserStackBrowsers,
            'url' => $url,
            'wait_time' => $waitTime,
            'quality' => $this->quality,
        ];

        return $this->query('', 'POST', $params);
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
        $result = $this->query($jobId, 'GET');

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
     * @param string $methodName
     * @param string $requestVerb
     * @param array $body
     * @return mixed
     */
    public function query(string $methodName, string $requestVerb, array $body = [])
    {
        $uri = (!empty($methodName)) ? 'screenshots/' . $methodName.'.json' : 'screenshots';

        if ($requestVerb == 'GET') {
            if (!empty($body)) {
                $uri .= '?'.http_build_query($body);
            }
        } else {
            $body = ['json' => $body];
        }

        $response = $this->client->request($requestVerb, $uri, $body);

        try {
            $result = json_decode($response->getBody()->getContents(), true);
            if (empty($result)) {
                $result = $response;
            }
        } catch (\Exception $e) {
            $result = $response;
        }

        return $result;
    }
}

<?php

namespace DiffyCli;

use GuzzleHttp\Client;

/**
 * Class LambdaTest
 *
 * Documentation https://www.lambdatest.com/support/docs/automated-screenshot-api-for-cross-browser-testing/
 * Swagger https://www.lambdatest.com/support/docs/api-doc/#screenshots
 *
 * @package DiffyCli
 */
class LambdaTest
{
    private $username;

    private $password;

    private $lambdaTestURL = 'https://api.lambdatest.com/screenshots/v1/';

    private $quality;

    private $client;

    public function __construct($username, $password)
    {
        $this->username = $username;
        $this->password = $password;
        $this->quality = 'original';

        $clientParams = [
            'base_uri' => $this->lambdaTestURL,
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
        return $this->query('os-browsers', 'GET');
    }

    /**
     * Create screenshot job.
     *
     * @param  $url
     * @param  array $browsers
     * @param  int   $waitTime
     * @return mixed
     */
    public function createScreenshot($url, array $browsers, int $waitTime = 5)
    {
        $params = [
            'configs' => $browsers,
            'url' => $url,
            'defer_time' => $waitTime,
            'email' => false,
            'mac_res' => '1024x768',
            'win_res' => '1366X768',
            'smart_scroll' => true,
            'layout' => 'portrait',
        ];

        return $this->query('', 'POST', $params);
    }

    /**
     * Get job results: list of screenshots.
     *
     * @param  $jobId
     * @return array
     * @throws \Exception
     */
    public function getListOfScreenshots($jobId)
    {
        $result = $this->query($jobId, 'GET');

        if (!isset($result['test_status'])) {
            throw new \Exception('Bad response: ' . var_export($result, true));
        }

        switch ($result['test_status']) {
            case 'initiated':
            case 'started':
                return ['status' => false, 'data' => $result];
            case 'completed':
                return ['status' => true, 'data' => $result];
            default:
                throw new \Exception('Undefined state: ' . $result['test_status']);
        }
    }

    /**
     * Run query to LambdaTest server.
     *
     * @param  string $methodName
     * @param  string $requestVerb
     * @param  array  $body
     * @return mixed
     */
    public function query(string $methodName, string $requestVerb, array $body = [])
    {
        $uri = $methodName;

        if ($requestVerb == 'GET') {
            if (!empty($body)) {
                $uri .= '?' . http_build_query($body);
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

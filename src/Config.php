<?php

namespace DiffyCli;

use Symfony\Component\Yaml\Yaml;

class Config
{

    /**
     * Save API Key to configuration file.
     *
     * @param $key
     * @throws \Exception
     */
    public static function saveApiKey($key)
    {
        $config = self::getConfig(FALSE);
        $config['key'] = $key;
        self::saveConfig($config);
    }

    /**
     * Save Browserstack credentials.
     *
     * @param $username
     * @param $accessKey
     * @throws \Exception
     */
    public static function saveBrowserstackCredentials($username, $accessKey)
    {
        $config = self::getConfig();
        $config['browserStackUsername'] = $username;
        $config['browserStackAccessKey'] = $accessKey;
        self::saveConfig($config);
    }

    /**
     * Save Lambdatest credentials.
     *
     * @param $username
     * @param $accessToken
     * @throws \Exception
     */
    public static function saveLambdatestCredentials($username, $accessToken)
    {
        $config = self::getConfig();
        $config['lambdaTestUsername'] = $username;
        $config['lambdaTestAccessToken'] = $accessToken;
        self::saveConfig($config);
    }

    /**
     * Save configuration file.
     *
     * @param $config
     * @throws \Exception
     */
    public static function saveConfig($config)
    {
        $configPrefix = 'DIFFYCLI';
        $configDirectory = getenv($configPrefix . '_CONFIG') ?: getenv('HOME') . '/.diffy-cli';

        $writable = is_dir($configDirectory)
            || (!file_exists($configDirectory) && @mkdir($configDirectory, 0777, true));
        $writable = $writable && is_writable($configDirectory);
        if (!$writable) {
            throw new \Exception(
                'Could not save data to a file because the path "' . $configDirectory . '" cannot be written to.'
            );
        }

        $configFilePath = $configDirectory . '/diffy-cli.yaml';
        $yaml = Yaml::dump($config);

        file_put_contents($configFilePath, $yaml);
    }

    /**
     * Save Configuration.
     *
     * @return mixed
     * @throws \Exception
     */
    public static function getConfig($should_exist = TRUE)
    {
        $configPrefix = 'DIFFYCLI';
        $configFilePath = getenv($configPrefix . '_CONFIG') ?: getenv('HOME') . '/.diffy-cli/diffy-cli.yaml';

        if (!file_exists($configFilePath)) {
            $config = [];
            if ($should_exist) {
                throw new \Exception(
                    'Configuration file "' . $configFilePath . '" does not exist yet. Save your API KEY with "diffy auth:login" command.'
                );
            }
        }
        else {
            $config = Yaml::parseFile($configFilePath);
        }

        return $config;
    }
}

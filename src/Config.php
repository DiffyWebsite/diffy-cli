<?php

namespace DiffyCli;

use Symfony\Component\Yaml\Yaml;

class Config {

    /**
     * Save API Key to configuration file.
     *
     * @param $key
     * @throws \Exception
     */
    static public function saveApiKey($key) {
        $config = [
            'key' => $key,
        ];
        self::saveConfig($config);
    }

    /**
     * Save configuration file.
     *
     * @param $config
     * @throws \Exception
     */
    static public function saveConfig($config) {
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
    static public function getConfig() {
        $configPrefix = 'DIFFYCLI';
        $configFilePath = getenv($configPrefix . '_CONFIG') ?: getenv('HOME') . '/.diffy-cli/diffy-cli.yaml';

        if (!file_exists($configFilePath)) {
            throw new \Exception(
                'Configuration file "' . $configFilePath . '" does not exist yet. Save your API KEY with "diffy auth:login" command.'
            );
        }

        $config = Yaml::parseFile($configFilePath);
        return $config;
    }


}

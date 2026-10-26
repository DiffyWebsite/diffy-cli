<?php

namespace DiffyCli;

use Symfony\Component\Yaml\Yaml;

class Config
{
    /**
     * Save API Key to configuration file.
     *
     * @param  $key
     * @throws \Exception
     */
    public static function saveApiKey($key)
    {
        $config = self::getConfig(false);
        $config['key'] = $key;
        self::saveConfig($config);
    }

    /**
     * Save configuration file.
     *
     * @param  $config
     * @throws \Exception
     */
    public static function saveConfig($config)
    {
        // DIFFYCLI_CONFIG is the path of the file, the same one getConfig() reads.
        $configFilePath = self::getConfigFilePath();
        $configDirectory = dirname($configFilePath);

        $writable = is_dir($configDirectory)
            || (!file_exists($configDirectory) && @mkdir($configDirectory, 0777, true));
        $writable = $writable && is_writable($configDirectory);
        if (!$writable) {
            throw new \Exception(
                'Could not save data to a file because the path "' . $configDirectory . '" cannot be written to.'
            );
        }

        $yaml = Yaml::dump($config);

        file_put_contents($configFilePath, $yaml);
    }

    /**
     * Save Configuration.
     *
     * @return mixed
     * @throws \Exception
     */
    public static function getConfig($should_exist = true)
    {
        $configFilePath = self::getConfigFilePath();

        if (!file_exists($configFilePath)) {
            $config = [];
            if ($should_exist) {
                throw new \Exception(
                    'Configuration file "' . $configFilePath . '" does not exist yet. Save your API KEY with "diffy auth:login" command.'
                );
            }
        } else {
            $config = Yaml::parseFile($configFilePath);
        }

        return $config;
    }

    private static function getConfigFilePath(): string
    {
        return getenv('DIFFYCLI_CONFIG') ?: getenv('HOME') . '/.diffy-cli/diffy-cli.yaml';
    }
}

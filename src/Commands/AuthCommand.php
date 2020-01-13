<?php

namespace DiffyCli\Commands;

use Diffy\Diffy;
use DiffyCli\Config;

class AuthCommand extends \Robo\Tasks
{
    /**
     * Logs in to Diffy
     *
     * @command auth:login
     *
     * @param string $apiKey Your API Key. Obtain your key at https://app.diffy.website/#/keys.
     *
     * @usage auth:login <api_key> Saves the API Key <api_key> to configuration for future use.
     */
    public function logIn($apiKey)
    {
        Diffy::setApiKey($apiKey);
        // If authentication fail we would get exception already.
        // So now it is safe to save the key to configuration.

        Config::saveApiKey($apiKey);
        $this->io()->success("Key is validated and saved");
    }
}

<?php

namespace DiffyCli\Commands;

use Diffy\Diffy;
use Diffy\Key;
use Diffy\User;
use DiffyCli\Config;
use GuzzleHttp\Exception\ClientException;
use Robo\ResultData;
use Robo\Tasks;

class AuthCommand extends Tasks
{
    /**
     * Exit code of auth:status (and auth:register) when the account exists but
     * has no API access yet, so scripts can tell it apart from a bad key.
     */
    public const EXITCODE_API_ACCESS_DISABLED = 2;

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
        $error = $this->authenticate($apiKey);
        if ($error) {
            $this->io()->error($error['message']);

            return new ResultData($error['code']);
        }

        // Authentication succeeded, so it is safe to save the key to configuration.
        Config::saveApiKey($apiKey);
        $this->io()->success("Key is validated and saved");

        // Successful exit.
        return new ResultData();
    }

    /**
     * Create a Diffy account and log in to it
     *
     * The account gets a random password that is never shown or stored. An
     * email with a link to set a password of your own is sent to the address,
     * you only need it for the web dashboard.
     *
     * @command auth:register
     *
     * @param array $options
     *
     * @option email Email of the new account.
     * @option name Your name.
     * @option coupon Coupon code to apply to the new account.
     * @option location Where the signup comes from, its utm_* query parameters are kept for attribution.
     *
     * @usage auth:register --email=jane@example.com --name="Jane Doe" --coupon=CODE
     *   Creates the account, creates an API key for it and saves the key to configuration.
     */
    public function register(array $options = ['email' => '', 'name' => '', 'coupon' => '', 'location' => ''])
    {
        if (empty($options['email']) || empty($options['name'])) {
            $this->io()->error('Both --email and --name are required.');

            return new ResultData(ResultData::EXITCODE_ERROR);
        }

        $email = $options['email'];
        $password = bin2hex(random_bytes(24));

        try {
            $registration = User::create($options['name'], $email, $password, $options['location'] ?? '', $options['coupon'] ?? '');
        } catch (\Exception $exception) {
            $this->io()->error('Could not create the account: ' . $this->getErrorMessage($exception));

            return new ResultData(ResultData::EXITCODE_ERROR);
        }

        unset($password);

        if (empty($registration['token'])) {
            $this->io()->error('The account was created but Diffy did not log it in. Log in at ' . Diffy::getUiBaseUrl() . ' instead.');

            return new ResultData(ResultData::EXITCODE_ERROR);
        }

        $coupon = $registration['coupon'] ?? ['applied' => false, 'message' => null];
        if (!empty($coupon['applied'])) {
            $this->io()->success($coupon['message'] ?: 'Coupon applied.');
        } elseif (!empty($coupon['message'])) {
            $this->io()->warning('Coupon was not applied: ' . $coupon['message']);
        }

        // Ask for the password email right away: the account has to be usable
        // from the web dashboard whatever happens with the API key below.
        try {
            User::sendResetPasswordEmail($email);
            $this->io()->success('Account created for ' . $email . '. We emailed a link to set your password.');
        } catch (\Exception $exception) {
            $this->io()->warning('Account created for ' . $email . ', but the password email could not be sent: '
                . $this->getErrorMessage($exception) . ' Run "diffy auth:reset-password ' . $email . '" to try again.');
        }

        Diffy::setApiToken($registration['token']);

        try {
            $keys = Key::create('diffy-cli ' . gethostname());
        } catch (ClientException $exception) {
            if ($exception->getResponse()->getStatusCode() === 403) {
                $this->io()->error($this->getApiAccessDisabledMessage());

                return new ResultData(self::EXITCODE_API_ACCESS_DISABLED);
            }

            $this->io()->error('Could not create an API key: ' . $this->getErrorMessage($exception));

            return new ResultData(ResultData::EXITCODE_ERROR);
        }

        // The key just created is the last one in the list.
        $key = end($keys['keys']);
        Config::saveApiKey($key['key']);
        $this->io()->success('API key created and saved. You are logged in.');

        return new ResultData();
    }

    /**
     * Send an email with a link to reset the password of an account
     *
     * @command auth:reset-password
     *
     * @param string $email Email of the account.
     *
     * @usage auth:reset-password jane@example.com
     */
    public function resetPassword(string $email)
    {
        try {
            User::sendResetPasswordEmail($email);
        } catch (\Exception $exception) {
            $this->io()->error($this->getErrorMessage($exception));

            return new ResultData(ResultData::EXITCODE_ERROR);
        }

        $this->io()->success('We emailed ' . $email . ' a link to reset the password.');

        return new ResultData();
    }

    /**
     * Check that the saved API key works
     *
     * Exits with 0 when it does, 1 when there is no key or it is not valid and
     * 2 when the account has no API access.
     *
     * @command auth:status
     *
     * @usage auth:status
     */
    public function status()
    {
        try {
            $apiKey = Config::getConfig()['key'] ?? '';
        } catch (\Exception $exception) {
            $this->io()->error('Not logged in. ' . $exception->getMessage());

            return new ResultData(ResultData::EXITCODE_ERROR);
        }

        $error = $this->authenticate($apiKey);
        if ($error) {
            $this->io()->error($error['message']);

            return new ResultData($error['code']);
        }

        $profile = Diffy::request('GET', 'user/profile');
        $this->io()->success('Logged in as ' . ($profile['profile']['email'] ?? 'unknown') . '.');

        return new ResultData();
    }

    /**
     * Exchange the key for a token.
     *
     * @return array{code: int, message: string}|null The error, null on success.
     */
    private function authenticate(string $apiKey): ?array
    {
        try {
            Diffy::setApiKey($apiKey);
        } catch (ClientException $exception) {
            $content = json_decode((string) $exception->getResponse()->getBody(), true);

            if (($content['type'] ?? '') === 'api_access_disabled') {
                return ['code' => self::EXITCODE_API_ACCESS_DISABLED, 'message' => $this->getApiAccessDisabledMessage()];
            }

            return ['code' => ResultData::EXITCODE_ERROR, 'message' => 'The API key is not valid. Create one at ' . Diffy::getUiBaseUrl() . 'keys.'];
        }

        if (empty(Diffy::getApiToken())) {
            return ['code' => ResultData::EXITCODE_ERROR, 'message' => 'The API key is not valid. Create one at ' . Diffy::getUiBaseUrl() . 'keys.'];
        }

        return null;
    }

    private function getApiAccessDisabledMessage(): string
    {
        return 'API access is not enabled for this account. Redeem a coupon at ' . Diffy::getUiBaseUrl()
            . 'profile or upgrade to a paid plan, then create a key at ' . Diffy::getUiBaseUrl() . 'keys and run "diffy auth:login <key>".';
    }

    private function getErrorMessage(\Exception $exception): string
    {
        if ($exception instanceof ClientException) {
            $content = json_decode((string) $exception->getResponse()->getBody(), true);

            if (!empty($content['errors'])) {
                return implode(' ', (array) $content['errors']);
            }
        }

        return $exception->getMessage();
    }
}

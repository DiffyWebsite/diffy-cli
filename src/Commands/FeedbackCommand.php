<?php

namespace DiffyCli\Commands;

use Diffy\Diffy;
use Diffy\Feedback;
use DiffyCli\Config;
use GuzzleHttp\Exception\ClientException;
use Robo\ResultData;
use Robo\Tasks;

class FeedbackCommand extends Tasks
{
    /**
     * Send feedback on a comparison to the Diffy team
     *
     * The feedback is emailed to support@diffy.website together with the email
     * of your account.
     *
     * @command feedback:send
     *
     * @param int   $diffId  ID of the diff the feedback is about.
     * @param array $options
     *
     * @option rating One of: useful, ok, not_useful.
     * @option comment Optional comment.
     * @option source Client sending the feedback, e.g. claude-code/compare-urls.
     *
     * @usage feedback:send 333 --rating=useful --comment="Caught a header overflow at 640px."
     */
    public function sendFeedback(int $diffId, array $options = ['rating' => '', 'comment' => '', 'source' => ''])
    {
        Diffy::setApiKey(Config::getConfig()['key']);

        try {
            Feedback::create($diffId, $options['rating'] ?? '', $options['comment'] ?? '', $options['source'] ?? '', $this->getClientVersion());
        } catch (ClientException $exception) {
            $content = json_decode((string) $exception->getResponse()->getBody(), true);
            $this->io()->error('Could not send feedback: ' . ($content['message'] ?? $exception->getMessage()));

            return new ResultData(ResultData::EXITCODE_ERROR);
        } catch (\Exception $exception) {
            $this->io()->error('Could not send feedback: ' . $exception->getMessage());

            return new ResultData(ResultData::EXITCODE_ERROR);
        }

        $this->io()->success('Feedback sent. Thank you!');

        return new ResultData();
    }

    private function getClientVersion(): string
    {
        $version = 'diffy-cli ' . trim((string) @file_get_contents(__DIR__ . '/../../VERSION'));

        if (getenv('DIFFY_PLUGIN_VERSION')) {
            $version .= '; diffy-plugin ' . getenv('DIFFY_PLUGIN_VERSION');
        }

        return $version;
    }
}

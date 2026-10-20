<?php
declare(strict_types=1);

namespace CommerceKings\NotificationSlack\Exception;

/**
 * Slack refused a message (nothing was posted): getSlackError() is Slack's error code, e.g. "channel_not_found" or
 * "invalid_auth" ("http_<status>" when Slack gave none). A configuration problem, not a passing one: retrying soon
 * gives the same answer.
 */
class SlackException extends \RuntimeException
{
    public function __construct(string $message, private readonly string $slackError, int $httpStatus = 0)
    {
        parent::__construct($message, $httpStatus);
    }

    public function getSlackError(): string
    {
        return $this->slackError;
    }
}

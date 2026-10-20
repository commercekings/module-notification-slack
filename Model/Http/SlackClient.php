<?php
declare(strict_types=1);

namespace CommerceKings\NotificationSlack\Model\Http;

use CommerceKings\Notification\Exception\MessageSentException;
use CommerceKings\Notification\Exception\TemporaryFailureException;
use CommerceKings\NotificationSlack\Exception\SlackException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Slack's Web API (chat.postMessage). What a failure means for the sender:
 * - Slack answered {"ok": false} with an error: nothing was posted – SlackException with Slack's error code, except
 *   "ratelimited" and "service_unavailable" (try again soon – TemporaryFailureException) and "internal_error" and
 *   "fatal_error", which Slack says may have done part of the work – MessageSentException;
 * - HTTP 429, or 503 with Retry-After: nothing was posted, try again soon – TemporaryFailureException;
 * - any other 5xx, or a success that is not Slack's answer: it may have been posted – MessageSentException;
 * - any other 4xx: nothing was posted – SlackException;
 * - no connection (proven by cURL's report): nothing was posted – \RuntimeException;
 * - anything else, e.g. a timeout waiting for the answer: it may have been posted – MessageSentException, so it is
 *   never posted twice.
 * The token goes in the Authorization header only; no message here contains it or the text.
 */
class SlackClient
{
    /** cURL errors before the request left: proxy or host not resolved, connection refused, TLS handshake. */
    private const NOT_SENT_ERRNOS = [5, 6, 7, 35];
    private const ERRNO_TIMEOUT = 28;

    private const TEMPORARY_ERRORS = ['ratelimited', 'service_unavailable'];
    private const MAYBE_SENT_ERRORS = ['internal_error', 'fatal_error'];

    /** What to fix, by Slack's error code; shown in logs and by the admin's test button. */
    private const HINTS = [
        'invalid_auth' => 'check the bot token',
        'not_authed' => 'check the bot token',
        'token_revoked' => 'the bot token was revoked; reinstall the app and save its new token',
        'token_expired' => 'the bot token expired; use a bot token without rotation',
        'account_inactive' => 'the app\'s workspace or bot user is deactivated',
        'missing_scope' => 'the app needs the chat:write scope; add it and reinstall the app',
        'channel_not_found' => 'check the channel ID; a private channel needs the app invited',
        'not_in_channel' => 'invite the app to the channel, or add the chat:write.public scope',
        'is_archived' => 'the channel is archived',
        'restricted_action' => 'the workspace does not let the app post in that channel',
        'msg_too_long' => 'the message is too long',
        'no_text' => 'the message is empty',
    ];

    public function __construct(
        private readonly ClientInterface $http,
        private readonly int $connectTimeout = 3,
        private readonly int $timeout = 10
    ) {
    }

    /**
     * Posts a message as the app's bot user.
     *
     * @param string $baseUrl Slack's Web API, e.g. https://slack.com/api
     * @param string $text Slack mrkdwn, already escaped
     * @return array<string, mixed> Slack's answer (ok, channel, ts, …)
     */
    public function postMessage(string $baseUrl, string $token, string $channel, string $text): array
    {
        if ($token === '') {
            throw new \RuntimeException('Slack is not configured for this store: a bot token is needed.');
        }
        if (!preg_match('#^https?://#i', $baseUrl)) {
            throw new \RuntimeException('The Slack API URL must start with https:// (or http:// for a test double).');
        }
        try {
            $response = $this->http->request('POST', rtrim($baseUrl, '/') . '/chat.postMessage', [
                // with the charset: Slack warns "missing_charset" without it
                'headers' => ['Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json; charset=utf-8'],
                'json' => ['channel' => $channel, 'text' => $text, 'unfurl_links' => false, 'unfurl_media' => false],
                'connect_timeout' => $this->connectTimeout,
                'timeout' => $this->timeout,
                'http_errors' => false,
            ]);
        } catch (ConnectException $error) {
            if (self::neverSent($error->getHandlerContext())) {
                throw new \RuntimeException('Slack could not be reached; nothing was posted.', 0, $error);
            }
            // a timeout waiting for the answer, an empty reply (cURL 52), …: it may have been posted
            throw new MessageSentException('Slack did not answer; the message may have been posted.', 0, $error);
        } catch (RequestException $error) {
            if ($error->getResponse() === null) {
                throw new MessageSentException('Slack did not answer in time; the message may have been posted.', 0, $error);
            }
            throw new \RuntimeException('Slack request failed.', 0, $error);
        } catch (GuzzleException $error) {
            throw new MessageSentException('Slack did not answer; the message may have been posted.', 0, $error);
        }
        $status = $response->getStatusCode();
        $body = json_decode((string)$response->getBody(), true);
        $body = is_array($body) ? $body : [];
        $error = is_string($body['error'] ?? null) ? $body['error'] : '';
        $retryAfter = TemporaryFailureException::parseRetryAfter($response->getHeaderLine('Retry-After'));
        if ($status === 429 || ($status === 503 && $retryAfter !== null)) {
            // too many requests, or briefly unavailable and Slack said for how long: nothing was posted, try again soon
            throw new TemporaryFailureException(sprintf('Slack refused the message for now (HTTP %d); nothing was'
                . ' posted.', $status), $retryAfter);
        }
        if ($status >= 500) {
            // a server error may come after Slack posted the message: never post it twice
            throw new MessageSentException(sprintf('Slack answered HTTP %d; the message may have been posted.', $status));
        }
        if ($status < 200 || $status >= 300) {
            throw self::refusal($error !== '' ? $error : 'http_' . $status, $status);
        }
        if (!is_bool($body['ok'] ?? null)) {
            // not Slack's answer (e.g. a proxy's page): whether it was posted is unknown
            throw new MessageSentException(sprintf('Slack\'s answer (HTTP %d) could not be read; the message may have'
                . ' been posted.', $status));
        }
        if ($body['ok']) {
            return $body;
        }
        if (in_array($error, self::TEMPORARY_ERRORS, true)) {
            throw new TemporaryFailureException(sprintf('Slack refused the message for now (%s); nothing was posted.',
                $error), $retryAfter);
        }
        if (in_array($error, self::MAYBE_SENT_ERRORS, true)) {
            throw new MessageSentException(sprintf('Slack failed (%s); the message may have been posted.', $error));
        }
        throw self::refusal($error !== '' ? $error : 'unknown_error', $status);
    }

    private static function refusal(string $error, int $status): SlackException
    {
        // Slack's codes are short snake_case words; anything else is not repeated in the message
        $code = preg_match('/^[a-z0-9_]{1,64}$/D', $error) === 1 ? $error : 'unknown_error';
        $hint = self::HINTS[$code] ?? null;
        return new SlackException(sprintf('Slack refused the message (%s)%s; nothing was posted.', $code,
            $hint !== null ? ': ' . $hint : ''), $code, $status);
    }

    /**
     * Whether cURL's report proves the request never left: the proxy or host was not found (5, 6), the connection was
     * refused (7) or TLS failed (35) - or a timeout (28) before any transfer began (pretransfer_time 0, nothing sent).
     * cURL reports a timeout while connecting and while waiting for the answer alike, so a timeout without that proof,
     * and any other failure, may have been posted.
     */
    private static function neverSent(array $context): bool
    {
        $errno = (int)($context['errno'] ?? 0);
        if (in_array($errno, self::NOT_SENT_ERRNOS, true)) {
            return true;
        }
        return $errno === self::ERRNO_TIMEOUT && isset($context['pretransfer_time'])
            && (float)$context['pretransfer_time'] <= 0.0 && (int)($context['request_size'] ?? 0) === 0
            && (float)($context['size_upload'] ?? 0) <= 0.0;
    }
}

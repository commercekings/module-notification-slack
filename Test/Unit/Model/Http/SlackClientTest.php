<?php
declare(strict_types=1);

namespace CommerceKings\NotificationSlack\Test\Unit\Model\Http;

use CommerceKings\Notification\Exception\MessageSentException;
use CommerceKings\Notification\Exception\TemporaryFailureException;
use CommerceKings\NotificationSlack\Exception\SlackException;
use CommerceKings\NotificationSlack\Model\Http\SlackClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a failure means: refused (nothing posted, SlackException), for now (TemporaryFailureException), unreachable
 * (nothing posted) or no answer (maybe posted: never again).
 */
class SlackClientTest extends TestCase
{
    private const TOKEN = 'xoxb-secret-token-1';

    private array $history = [];

    private function client(array $responses): SlackClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return new SlackClient(new Client(['handler' => $stack]));
    }

    private function post(Response|\Throwable $answer, string $baseUrl = 'https://slack.com/api'): array
    {
        return $this->client([$answer])->postMessage($baseUrl, self::TOKEN, 'C0123ABCD', 'Hello');
    }

    private function failure(Response|\Throwable $answer): \Throwable
    {
        try {
            $this->post($answer);
        } catch (\Throwable $error) {
            $this->assertStringNotContainsString(self::TOKEN, $error->getMessage(), 'the token is never in a message');
            return $error;
        }
        $this->fail('Expected a failure');
    }

    private function refusal(Response $answer): SlackException
    {
        $error = $this->failure($answer);
        $this->assertInstanceOf(SlackException::class, $error);
        return $error;
    }

    public function testAMessageIsPostedAsJsonWithTheBearerTokenAndShortTimeouts(): void
    {
        $answer = $this->post(new Response(200, [], '{"ok":true,"channel":"C0123ABCD","ts":"1.2"}'), 'http://fake.test/api/');
        $this->assertSame(['ok' => true, 'channel' => 'C0123ABCD', 'ts' => '1.2'], $answer);
        [$request, $options] = [$this->history[0]['request'], $this->history[0]['options']];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('http://fake.test/api/chat.postMessage', (string)$request->getUri(), 'the base URL is configurable');
        $this->assertSame('Bearer ' . self::TOKEN, $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json; charset=utf-8', $request->getHeaderLine('Content-Type'));
        $this->assertSame(['channel' => 'C0123ABCD', 'text' => 'Hello', 'unfurl_links' => false, 'unfurl_media' => false],
            json_decode((string)$request->getBody(), true));
        $this->assertSame([3, 10], [$options['connect_timeout'], $options['timeout']]);
    }

    public function testInvalidUtf8IsPostedWithAReplacementCharacterNotRefused(): void
    {
        $this->client([new Response(200, [], '{"ok":true,"channel":"C0123ABCD","ts":"1.2"}')])
            ->postMessage('https://slack.com/api', self::TOKEN, 'C0123ABCD', "Error: caf\xC3 (cut)");
        $this->assertSame("Error: caf\u{FFFD} (cut)", json_decode((string)$this->history[0]['request']->getBody(),
            true)['text']);
        $this->assertSame('application/json; charset=utf-8',
            $this->history[0]['request']->getHeaderLine('Content-Type'));
    }

    public function testWithoutATokenOrWithAStrangeUrlNothingIsRequested(): void
    {
        foreach ([['https://slack.com/api', ''], ['file:///etc/passwd', self::TOKEN], ['', self::TOKEN]] as [$url, $token]) {
            try {
                $this->client([])->postMessage($url, $token, 'C0123ABCD', 'Hello');
                $this->fail('Expected a refusal');
            } catch (\RuntimeException $error) {
                $this->assertNotInstanceOf(MessageSentException::class, $error);
            }
        }
        $this->assertSame([], $this->history);
    }

    /** @return array<string, array{string, string}> Slack's error => what the message tells the admin */
    public static function refusals(): array
    {
        return [
            'bad token' => ['invalid_auth', 'check the bot token'],
            'revoked' => ['token_revoked', 'reinstall the app'],
            'wrong channel' => ['channel_not_found', 'check the channel ID'],
            'not invited' => ['not_in_channel', 'invite the app'],
            'archived' => ['is_archived', 'archived'],
            'scope' => ['missing_scope', 'chat:write'],
            'anything else' => ['some_new_error', 'some_new_error'],
        ];
    }

    #[DataProvider('refusals')]
    public function testSlackRefusalsAreNotPostedAndSayWhatToFix(string $code, string $hint): void
    {
        $error = $this->refusal(new Response(200, [], json_encode(['ok' => false, 'error' => $code])));
        $this->assertSame($code, $error->getSlackError());
        $this->assertStringContainsString($hint, $error->getMessage());
        $this->assertStringContainsString('nothing was posted', $error->getMessage());
    }

    public function testAnErrorCodeThatIsNotSlackSIsNotRepeated(): void
    {
        $error = $this->refusal(new Response(200, [], '{"ok":false,"error":"<script>alert(1)</script>"}'));
        $this->assertSame('unknown_error', $error->getSlackError());
        $this->assertStringNotContainsString('script', $error->getMessage());
    }

    /** A rate limit, or a short outage: nothing was posted, post it again after the wait. */
    public function testRateLimitsAndShortOutagesAreTemporary(): void
    {
        foreach ([[new Response(429, ['Retry-After' => '30'], '{"ok":false,"error":"ratelimited"}'), 30],
            [new Response(429, [], ''), null],
            [new Response(200, [], '{"ok":false,"error":"ratelimited"}'), null],
            [new Response(200, [], '{"ok":false,"error":"service_unavailable"}'), null],
            [new Response(503, ['Retry-After' => '120'], ''), 120],
            [new Response(503, ['Retry-After' => 'Mon, 19 Oct 2099 10:05:00 GMT'], ''), 'date']] as [$response, $wait]) {
            $error = $this->failure($response);
            $this->assertInstanceOf(TemporaryFailureException::class, $error);
            $this->assertStringContainsString('nothing was posted', $error->getMessage());
            $wait === 'date' ? $this->assertGreaterThan(0, $error->getRetryAfter())
                : $this->assertSame($wait, $error->getRetryAfter());
        }
    }

    /** A server error, Slack's own "may have done part of it", or an answer that is not Slack's: never post twice. */
    public function testWhatMayHaveBeenPostedIsNeverRetried(): void
    {
        foreach ([new Response(500, [], ''), new Response(502, [], ''), new Response(503, [], ''), new Response(504, [], ''),
            new Response(200, [], '{"ok":false,"error":"internal_error"}'),
            new Response(200, [], '{"ok":false,"error":"fatal_error"}'),
            new Response(200, [], '<html>proxy</html>'), new Response(200, [], '{"channel":"C1"}')] as $response) {
            $error = $this->failure($response);
            $this->assertInstanceOf(MessageSentException::class, $error, (string)$response->getBody());
            $this->assertStringContainsString('may have been posted', $error->getMessage());
        }
    }

    public function testOtherClientErrorsAreRefusals(): void
    {
        foreach ([400 => 'http_400', 403 => 'invalid_auth', 404 => 'http_404'] as $status => $code) {
            $body = $status === 403 ? '{"ok":false,"error":"invalid_auth"}' : 'Not found';
            $error = $this->refusal(new Response($status, ['Retry-After' => '30'], $body));
            $this->assertSame([$code, $status], [$error->getSlackError(), $error->getCode()]);
        }
    }

    /** @return array<string, array{array<string, mixed>, bool}> cURL's report => whether it may have been posted */
    public static function connectFailures(): array
    {
        return [
            'connection refused' => [['errno' => 7], false],
            'host not found' => [['errno' => 6], false],
            'proxy not found' => [['errno' => 5], false],
            'TLS handshake failed' => [['errno' => 35], false],
            'timeout while connecting' => [['errno' => 28, 'pretransfer_time' => 0.0, 'request_size' => 0, 'size_upload' => 0.0], false],
            'timeout after the request went out' => [['errno' => 28, 'pretransfer_time' => 0.21, 'request_size' => 412,
                'size_upload' => 380.0], true],
            'timeout without a transfer report' => [['errno' => 28], true],
            'empty reply' => [['errno' => 52], true],
            'no report at all' => [[], true],
        ];
    }

    /** Only a failure that provably happened before the request left is "nothing posted". */
    #[DataProvider('connectFailures')]
    public function testOnlyAProvenConnectFailureIsNotPosted(array $context, bool $maybeSent): void
    {
        $error = $this->failure(new ConnectException('cURL error', new Request('POST', 'https://slack.com'), null, $context));
        if ($maybeSent) {
            $this->assertInstanceOf(MessageSentException::class, $error);
        } else {
            $this->assertNotInstanceOf(MessageSentException::class, $error);
            $this->assertInstanceOf(\RuntimeException::class, $error);
            $this->assertStringContainsString('nothing was posted', $error->getMessage());
        }
    }

    public function testATimeoutWithoutAnAnswerMayHaveBeenPosted(): void
    {
        $this->assertInstanceOf(MessageSentException::class,
            $this->failure(new RequestException('timed out', new Request('POST', 'https://slack.com'))));
    }
}

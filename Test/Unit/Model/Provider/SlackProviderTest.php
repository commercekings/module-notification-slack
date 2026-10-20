<?php
declare(strict_types=1);

namespace CommerceKings\NotificationSlack\Test\Unit\Model\Provider;

use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\NotificationSlack\Model\Config;
use CommerceKings\NotificationSlack\Model\Http\SlackClient;
use CommerceKings\NotificationSlack\Model\Provider\SlackProvider;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

/** The "slack" channel: escaped text to the channel ID, as the website's bot - never during a shopper's request. */
class SlackProviderTest extends TestCase
{
    private array $posts = [];

    private function provider(string $sapi = 'cli', ?string $area = 'crontab'): SlackProvider
    {
        $client = $this->createStub(SlackClient::class);
        $client->method('postMessage')->willReturnCallback(function (...$args) {
            $this->posts[] = $args;
            return ['ok' => true];
        });
        $config = $this->createStub(Config::class);
        $config->method('getApiBaseUrl')->willReturnCallback(fn (int $storeId) => 'https://slack.test/api/' . $storeId);
        $config->method('getBotToken')->willReturnCallback(fn (int $storeId) => 'xoxb-' . $storeId);
        $state = $this->createStub(State::class);
        $area === null ? $state->method('getAreaCode')->willThrowException(new LocalizedException(__('Area code is not set')))
            : $state->method('getAreaCode')->willReturn($area);
        return new SlackProvider($client, $config, $state, $sapi);
    }

    private function send(SlackProvider $provider, string $text, string $channel = 'C0123ABCD'): void
    {
        $content = new DeliveryContent([], null, null, $text);
        $provider->send(Delivery::text(3, 'slack', $channel, fn () => $content, false, null, false, 'app_health'), $content);
    }

    public function testItIsTheSlackChannelSProviderWithoutOneClickUnsubscribe(): void
    {
        $provider = $this->provider();
        $this->assertSame(['slack', 'Slack', 'slack', false], [$provider->getCode(), $provider->getLabel(),
            $provider->getChannel(), $provider->supportsOneClickUnsubscribe()]);
    }

    public function testTheTextGoesToTheChannelAsTheDeliveryStoreSBot(): void
    {
        $this->send($this->provider(), "*Store health*\nCron: OK");
        $this->assertSame([['https://slack.test/api/3', 'xoxb-3', 'C0123ABCD', "*Store health*\nCron: OK"]], $this->posts,
            'bold and line breaks stay');
    }

    public function testShopDataCannotMentionEveryoneOrFormLinks(): void
    {
        $this->send($this->provider(), 'Order by <!channel> & <https://evil.test|click> > 5');
        $this->assertSame('Order by &lt;!channel&gt; &amp; &lt;https://evil.test|click&gt; &gt; 5', $this->posts[0][3]);
        $this->assertSame('&amp;amp;', SlackProvider::format('&amp;'), 'raw text in, escaped once');
    }

    public function testALongTextIsCutVisiblyNeverInsideAnEscape(): void
    {
        $this->assertSame(str_repeat('a', 100), SlackProvider::format(str_repeat('a', 100)));
        $cut = SlackProvider::format(str_repeat('a', SlackProvider::MAX_LENGTH + 10));
        $this->assertSame(SlackProvider::MAX_LENGTH, mb_strlen($cut));
        $this->assertStringEndsWith('…', $cut);
        foreach (range(1, 4) as $shift) {
            // an escape that would be split by the cut is dropped whole
            $cut = SlackProvider::format(str_repeat('a', SlackProvider::MAX_LENGTH - 1 - $shift) . str_repeat('&', 20));
            $this->assertMatchesRegularExpression('/^a+(&amp;)*…$/u', $cut);
            $this->assertLessThanOrEqual(SlackProvider::MAX_LENGTH, mb_strlen($cut));
        }
    }

    public function testAnEmptyMessageIsNotPosted(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->send($this->provider(), "  \n ");
        } finally {
            $this->assertSame([], $this->posts);
        }
    }

    public function testNeverDuringAShopperSRequest(): void
    {
        foreach (['frontend', 'webapi_rest', 'webapi_soap', 'graphql'] as $area) {
            try {
                $this->send($this->provider('fpm-fcgi', $area), 'Hello');
                $this->fail('Expected a refusal in ' . $area);
            } catch (\LogicException $error) {
                $this->assertStringContainsString('never during a shopper', $error->getMessage());
            }
        }
        $this->assertSame([], $this->posts, 'nothing was requested');
    }

    public function testTheAdminCronAndTheCommandLinePost(): void
    {
        $this->send($this->provider('fpm-fcgi', 'adminhtml'), 'admin test');
        $this->send($this->provider('fpm-fcgi', 'crontab'), 'web cron');
        $this->send($this->provider('fpm-fcgi', null), 'no area yet');
        $this->send($this->provider('cli', 'frontend'), 'queue consumer in an emulated store');
        $this->assertCount(4, $this->posts);
    }
}

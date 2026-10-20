<?php
declare(strict_types=1);

namespace CommerceKings\NotificationSlack\Test\Integration;

use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\ProviderPoolInterface;
use CommerceKings\Notification\Api\SenderInterface;
use CommerceKings\Notification\Exception\TemporaryFailureException;
use CommerceKings\Notification\Model\ProviderPool;
use CommerceKings\Notification\Model\RecipientNormalizerPool;
use CommerceKings\Notification\Model\Sender;
use CommerceKings\NotificationSlack\Model\Config;
use CommerceKings\NotificationSlack\Model\Http\SlackClient;
use CommerceKings\NotificationSlack\Model\Provider\SlackProvider;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Real Magento, a stub in place of Slack's API: the module is wired into Notification (the "slack" provider, its
 * normalizer, the encrypted token and default URL), and Notification's real sender posts team messages through it -
 * normalized channel IDs, never a cooldown claim, refusals before anything is sent.
 */
class SlackTest extends TestCase
{
    private ObjectManagerInterface $om;
    private int $storeId;
    /** what the stub Slack API received */
    private array $posts = [];
    private ?\Throwable $failure = null;

    protected function setUp(): void
    {
        $root = getenv('MAGENTO_ROOT') ?: (defined('BP') ? BP : getcwd());
        if (!is_file($root . '/app/bootstrap.php')) {
            throw new \RuntimeException('Run from the Magento root or set MAGENTO_ROOT.');
        }
        require_once $root . '/app/bootstrap.php';
        $this->om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
        $this->om->get(State::class)->setAreaCode('crontab');
        $stores = $this->om->get(StoreManagerInterface::class);
        $stores->setCurrentStore('default');
        $this->storeId = (int)$stores->getStore()->getId();
    }

    private function sender(): Sender
    {
        $config = $this->createStub(Config::class);
        $config->method('getApiBaseUrl')->willReturn('https://slack.test/api');
        $config->method('getBotToken')->willReturn('xoxb-test');
        $client = $this->createStub(SlackClient::class);
        $client->method('postMessage')->willReturnCallback(function (...$args) {
            if ($this->failure !== null) {
                throw $this->failure;
            }
            $this->posts[] = $args;
            return ['ok' => true];
        });
        $provider = new SlackProvider($client, $config, $this->om->get(State::class));
        return $this->om->create(Sender::class, [
            'providers' => new ProviderPool($this->om->get(\CommerceKings\Notification\Model\Config::class), [$provider]),
        ]);
    }

    private function delivery(string $channel, string $text, bool $useCooldown = false): Delivery
    {
        return Delivery::text($this->storeId, SlackProvider::CHANNEL, $channel,
            fn () => new DeliveryContent([], null, null, $text), $useCooldown, null, false, 'slack_integration_test');
    }

    public function testTheModuleIsWiredIntoNotification(): void
    {
        $provider = $this->om->get(ProviderPoolInterface::class)->getSelected($this->storeId, SlackProvider::CHANNEL);
        $this->assertInstanceOf(SlackProvider::class, $provider);
        $this->assertSame('C0123ABCD', $this->om->get(RecipientNormalizerPool::class)->normalize('slack', ' c0123abcd '));
        $this->assertStringStartsWith('http', (string)$this->om->get(ScopeConfigInterface::class)
            ->getValue('commercekings_notification/slack/api_base_url'), 'config.xml sets the API URL');
    }

    public function testATeamMessageIsPostedEscapedToTheNormalizedChannelEveryTime(): void
    {
        $sender = $this->sender();
        $channel = 'c' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
        foreach ([1, 2] as $run) {
            $this->assertSame(SenderInterface::SENT, $sender->send($this->delivery($channel, "*Store health* <!channel>\n> late #$run")));
        }
        $this->assertCount(2, $this->posts, 'no cooldown: the second alert is posted too');
        $this->assertSame(['https://slack.test/api', 'xoxb-test', strtoupper($channel), "*Store health* &lt;!channel>\n> late #1"],
            $this->posts[0]);
    }

    public function testAChannelNameIsRefusedBeforeAnythingIsSent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->sender()->send($this->delivery('#orders', 'Hello'));
        } finally {
            $this->assertSame([], $this->posts);
        }
    }

    public function testARateLimitReachesTheCallerAsATemporaryFailure(): void
    {
        $this->failure = new TemporaryFailureException('Slack refused the message for now (HTTP 429).', 30);
        try {
            $this->sender()->send($this->delivery('C0123ABCD', 'Hello'));
            $this->fail('Expected a temporary failure');
        } catch (TemporaryFailureException $error) {
            $this->assertSame(30, $error->getRetryAfter());
        }
    }
}

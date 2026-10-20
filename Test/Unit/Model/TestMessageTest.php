<?php
declare(strict_types=1);

namespace CommerceKings\NotificationSlack\Test\Unit\Model;

use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\SenderInterface;
use CommerceKings\Notification\Exception\MessageSentException;
use CommerceKings\Notification\Exception\TemporaryFailureException;
use CommerceKings\NotificationSlack\Exception\SlackException;
use CommerceKings\NotificationSlack\Model\Config;
use CommerceKings\NotificationSlack\Model\TestMessage;
use Magento\Store\Api\Data\GroupInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** "Send test message": through Notification's sender to the saved test channel, with an answer the admin can act on. */
class TestMessageTest extends TestCase
{
    private string $token = 'xoxb-1';
    private string $channel = 'C0123ABCD';
    /** @var Delivery[] */
    private array $deliveries = [];
    private \Throwable|string $outcome = SenderInterface::SENT;
    private array $errors = [];

    private function store(int $id, string $name): StoreInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn($id);
        $store->method('getName')->willReturn($name);
        return $store;
    }

    private function testMessage(): TestMessage
    {
        $sender = $this->createStub(SenderInterface::class);
        $sender->method('send')->willReturnCallback(function (Delivery $delivery) {
            $this->deliveries[] = $delivery;
            if ($this->outcome instanceof \Throwable) {
                throw $this->outcome;
            }
            return $this->outcome;
        });
        $config = $this->createStub(Config::class);
        $config->method('getBotToken')->willReturnCallback(fn () => $this->token);
        $config->method('getTestChannelId')->willReturnCallback(fn () => $this->channel);
        $config->method('getStoreName')->willReturnCallback(fn (int $storeId) => $storeId === 5 ? 'Acme B2B' : 'Acme');
        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getDefaultGroupId')->willReturn(4);
        $group = $this->createStub(GroupInterface::class);
        $group->method('getDefaultStoreId')->willReturn(5);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getWebsite')->willReturnCallback(fn ($id) => $id === 9
            ? throw new \Magento\Framework\Exception\LocalizedException(__('The website with code 9 was not found.')) : $website);
        $stores->method('getGroup')->willReturnCallback(fn ($id) => $id === '4' ? $group : null);
        $stores->method('getStore')->willReturnCallback(fn ($id) => $id === 5 ? $this->store(5, 'B2B view') : null);
        $stores->method('getDefaultStoreView')->willReturn($this->store(1, 'Default Store View'));
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function ($message) { $this->errors[] = $message; });
        return new TestMessage($sender, $config, $stores, $logger);
    }

    public function testItIsSentAsATeamMessageOfTheScopeSStore(): void
    {
        $this->assertSame([true, 'Posted to C0123ABCD.'], $this->testMessage()->send(2));
        $delivery = $this->deliveries[0];
        $this->assertSame([5, 'slack', 'C0123ABCD', false, false, 'slack_test'], [$delivery->storeId, $delivery->channel,
            $delivery->recipient, $delivery->useCooldown, $delivery->stoppable, $delivery->type]);
        $this->assertStringContainsString('Test message from Acme B2B:', ($delivery->content)()->text,
            'the shop\'s name, not the store view\'s');
        $this->testMessage()->send(null);
        $this->assertSame(1, $this->deliveries[1]->storeId, 'Default Config: the default store view');
        $this->assertSame([false, 'No store view found for this scope.'], $this->testMessage()->send(9));
        $this->assertCount(2, $this->deliveries);
    }

    public function testWithoutASavedTokenOrChannelNothingIsSent(): void
    {
        $this->token = '';
        [$ok, $message] = $this->testMessage()->send(null);
        $this->assertSame([false, 'Enter the bot token and save the configuration first.'], [$ok, $message]);
        $this->token = 'xoxb-1';
        $this->channel = '';
        $this->assertStringContainsString('test channel ID', $this->testMessage()->send(null)[1]);
        $this->assertSame([], $this->deliveries);
    }

    public function testEachFailureSaysWhatHappened(): void
    {
        foreach ([
            [new \InvalidArgumentException('No valid recipient'), 'not a Slack channel ID'],
            [new SlackException('Slack refused the message (not_in_channel): invite the app', 'not_in_channel'),
                'Slack refused the message (not_in_channel): invite the app'],
            [new TemporaryFailureException('429', 30), 'try again in 30 seconds'],
            [new TemporaryFailureException('ratelimited'), 'try again in a minute'],
            [new MessageSentException('no answer'), 'may still arrive'],
            [new \RuntimeException('Slack could not be reached; nothing was posted.'), 'could not be reached'],
            [SenderInterface::COOLDOWN, 'Not posted: recipient cooldown.'],
        ] as [$outcome, $expected]) {
            $this->outcome = $outcome;
            [$ok, $message] = $this->testMessage()->send(2);
            $this->assertFalse($ok);
            $this->assertStringContainsString($expected, $message);
        }
        $this->assertCount(1, $this->errors, 'only the unexpected failure is logged');
        $this->assertStringContainsString('RuntimeException', $this->errors[0]);
    }
}

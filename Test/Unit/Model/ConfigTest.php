<?php
declare(strict_types=1);

namespace CommerceKings\NotificationSlack\Test\Unit\Model;

use CommerceKings\NotificationSlack\Model\ChannelIdNormalizer;
use CommerceKings\NotificationSlack\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Settings per store's website, and the channel IDs Slack recipients must be. */
class ConfigTest extends TestCase
{
    private array $reads = [];
    private array $warnings = [];

    private function config(array $values): Config
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(function ($path, $scope, $storeId) use ($values) {
            $this->reads[] = [$path, $scope, $storeId];
            return $values[$path] ?? null;
        });
        $scopeConfig->method('isSetFlag')->willReturnCallback(fn ($path) => (bool)($values[$path] ?? false));
        $store = $this->createStub(Store::class);
        $store->method('getFrontendName')->willReturn('Main Website Store');
        $store->method('getName')->willReturn('Default Store View');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message) { $this->warnings[] = $message; });
        return new Config($scopeConfig, $stores, $logger);
    }

    public function testValuesAreReadForTheStoreAndTrimmed(): void
    {
        $config = $this->config([
            'commercekings_notification/slack/bot_token' => " xoxb-1 \n",
            'commercekings_notification/slack/test_channel_id' => ' C0123ABCD ',
        ]);
        $this->assertSame(['xoxb-1', 'C0123ABCD'], [$config->getBotToken(4), $config->getTestChannelId(4)]);
        $this->assertSame(['commercekings_notification/slack/bot_token', 'store', 4], $this->reads[0]);
    }

    public function testTheApiUrlDefaultsToSlackAndLosesItsTrailingSlash(): void
    {
        $this->assertSame('https://slack.com/api', $this->config([])->getApiBaseUrl(1));
        $this->assertSame('https://slack.com/api', $this->config(['commercekings_notification/slack/api_base_url'
            => 'https://slack.com/api/'])->getApiBaseUrl(1));
        $this->assertSame(Config::DEFAULT_API_BASE_URL, $this->config(['commercekings_notification/slack/api_base_url'
            => ' / '])->getApiBaseUrl(1));
        $this->assertSame([], $this->warnings);
    }

    public function testOnlySlackSOwnHttpsHostsAreUsedUnlessCustomUrlsAreAllowed(): void
    {
        $url = 'commercekings_notification/slack/api_base_url';
        foreach (['https://slack.com/api/' => 'https://slack.com/api', 'https://eu.slack.com/api' => 'https://eu.slack.com/api',
            'HTTPS://Slack.com/api' => 'HTTPS://Slack.com/api'] as $input => $expected) {
            $this->assertSame($expected, $this->config([$url => $input])->getApiBaseUrl(1), $input);
        }
        $this->assertSame([], $this->warnings);
        foreach (['http://slack.com/api', 'https://evil.test/api', 'https://slack.com.evil.test/api',
            'https://evilslack.com/api', 'https://user:secret@evil.test/api', 'ftp://slack.com/api'] as $input) {
            $this->warnings = [];
            $config = $this->config([$url => $input]);
            $this->assertSame(Config::DEFAULT_API_BASE_URL, $config->getApiBaseUrl(1), $input);
            $config->getApiBaseUrl(1);
            $this->assertCount(1, $this->warnings, 'warned once: ' . $input);
            $this->assertStringNotContainsString('secret', $this->warnings[0]);
            $this->assertStringContainsString('allow_custom_api_base_url', $this->warnings[0]);
        }
        $this->warnings = [];
        $this->assertSame('http://fake.test/api', $this->config([$url => 'http://fake.test/api',
            'commercekings_notification/slack/allow_custom_api_base_url' => '1'])->getApiBaseUrl(1), 'a test double');
        $this->assertSame([], $this->warnings);
    }

    public function testTheShopIsNamedAsInEveryOtherMessage(): void
    {
        $this->assertSame('Acme', $this->config(['general/store_information/name' => ' Acme '])->getStoreName(3));
        $this->assertSame('Main Website Store', $this->config(['general/store_information/name' => ''])->getStoreName(3),
            'not the store view name');
        $this->assertSame('Main Website Store', $this->config([])->getStoreName(3));
    }

    public function testRecipientsAreChannelIds(): void
    {
        $normalizer = new ChannelIdNormalizer();
        foreach ([' c0123abcd ' => 'C0123ABCD', 'G01ABCDEFGH' => 'G01ABCDEFGH', 'D0123ABCD' => 'D0123ABCD',
            '#orders' => '', 'orders' => '', 'C0123' => '', 'U0123ABCD' => '', 'C0123ABCD;drop' => '', '' => '',
            "C0123ABCD\n" => 'C0123ABCD', 'C' . str_repeat('A', 21) => ''] as $input => $expected) {
            $this->assertSame($expected, $normalizer->normalize((string)$input), var_export($input, true));
        }
    }
}

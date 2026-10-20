<?php
declare(strict_types=1);

namespace CommerceKings\NotificationSlack\Test\Unit\Model;

use CommerceKings\NotificationSlack\Model\ChannelIdNormalizer;
use CommerceKings\NotificationSlack\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

/** Settings per store's website, and the channel IDs Slack recipients must be. */
class ConfigTest extends TestCase
{
    private array $reads = [];

    private function config(array $values): Config
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(function ($path, $scope, $storeId) use ($values) {
            $this->reads[] = [$path, $scope, $storeId];
            return $values[$path] ?? null;
        });
        return new Config($scopeConfig);
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
        $this->assertSame('http://fake.test/api', $this->config(['commercekings_notification/slack/api_base_url'
            => 'http://fake.test/api/'])->getApiBaseUrl(1));
        $this->assertSame(Config::DEFAULT_API_BASE_URL, $this->config(['commercekings_notification/slack/api_base_url'
            => ' / '])->getApiBaseUrl(1));
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

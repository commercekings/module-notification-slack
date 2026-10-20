<?php
declare(strict_types=1);

namespace CommerceKings\NotificationSlack\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/** Slack settings, per website (Stores > Configuration > CommerceKings > Notifications > Slack). */
class Config
{
    private const PREFIX = 'commercekings_notification/slack/';
    public const DEFAULT_API_BASE_URL = 'https://slack.com/api';
    /** Not in the admin: set only for a test double or a gateway (bin/magento config:set ... 1). */
    public const ALLOW_CUSTOM_API_BASE_URL = self::PREFIX . 'allow_custom_api_base_url';

    private bool $warned = false;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $stores,
        private readonly LoggerInterface $logger
    ) {
    }

    /** The app's bot token (xoxb-…). Stored encrypted; Magento decrypts it on read (config.xml marks it). */
    public function getBotToken(int $storeId): string
    {
        return $this->value('bot_token', $storeId);
    }

    /**
     * Slack's Web API, without a trailing slash. Only https on slack.com or its subdomains, so a changed setting cannot
     * send the bot token elsewhere - unless ALLOW_CUSTOM_API_BASE_URL is set (a test double). Anything else falls back
     * to Slack's own URL, with one warning per process.
     */
    public function getApiBaseUrl(int $storeId): string
    {
        $url = rtrim($this->value('api_base_url', $storeId), '/');
        if ($url === '') {
            return self::DEFAULT_API_BASE_URL;
        }
        if ($this->scopeConfig->isSetFlag(self::ALLOW_CUSTOM_API_BASE_URL, ScopeInterface::SCOPE_STORE, $storeId)) {
            return $url;
        }
        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        if (strtolower((string)($parts['scheme'] ?? '')) === 'https' && !isset($parts['user'])
            && ($host === 'slack.com' || str_ends_with($host, '.slack.com'))) {
            return $url;
        }
        if (!$this->warned) {
            $this->warned = true;
            $this->logger->warning(sprintf('CommerceKings_NotificationSlack: commercekings_notification/slack/'
                . 'api_base_url (%s://%s) is not https on slack.com; using %s. To use another URL (a test double), set '
                . '%s to 1.', $parts['scheme'] ?? '?', $host !== '' ? $host : '?', self::DEFAULT_API_BASE_URL,
                self::ALLOW_CUSTOM_API_BASE_URL));
        }
        return self::DEFAULT_API_BASE_URL;
    }

    /** Where the admin's "Send test message" posts. */
    public function getTestChannelId(int $storeId): string
    {
        return $this->value('test_channel_id', $storeId);
    }

    /**
     * The shop's name as the other CommerceKings messages give it: the store's *Store Information* name, else the
     * store's frontend name (its store group's name) - never the store view's name.
     */
    public function getStoreName(int $storeId): string
    {
        $name = trim((string)$this->scopeConfig->getValue('general/store_information/name', ScopeInterface::SCOPE_STORE,
            $storeId));
        if ($name !== '') {
            return $name;
        }
        $store = $this->stores->getStore($storeId);
        return (string)($store instanceof Store ? $store->getFrontendName() : $store->getName());
    }

    private function value(string $field, int $storeId): string
    {
        return trim((string)$this->scopeConfig->getValue(self::PREFIX . $field, ScopeInterface::SCOPE_STORE, $storeId));
    }
}

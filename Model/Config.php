<?php
declare(strict_types=1);

namespace CommerceKings\NotificationSlack\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/** Slack settings, per website (Stores > Configuration > CommerceKings > Notifications > Slack). */
class Config
{
    private const PREFIX = 'commercekings_notification/slack/';
    public const DEFAULT_API_BASE_URL = 'https://slack.com/api';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $stores
    ) {
    }

    /** The app's bot token (xoxb-…). Stored encrypted; Magento decrypts it on read (config.xml marks it). */
    public function getBotToken(int $storeId): string
    {
        return $this->value('bot_token', $storeId);
    }

    /** Slack's Web API, without a trailing slash. Only a test double changes it (no admin field). */
    public function getApiBaseUrl(int $storeId): string
    {
        $url = rtrim($this->value('api_base_url', $storeId), '/');
        return $url !== '' ? $url : self::DEFAULT_API_BASE_URL;
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

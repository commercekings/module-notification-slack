<?php
declare(strict_types=1);

namespace CommerceKings\NotificationSlack\Model;

use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\SenderInterface;
use CommerceKings\Notification\Exception\MessageSentException;
use CommerceKings\Notification\Exception\TemporaryFailureException;
use CommerceKings\NotificationSlack\Exception\SlackException;
use CommerceKings\NotificationSlack\Model\Provider\SlackProvider;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The admin's "Send test message": one message to the saved test channel, through Notification's sender as every
 * Slack notification goes - so it proves the token, the channel and the provider together.
 */
class TestMessage
{
    public const TYPE = 'slack_test';

    public function __construct(
        private readonly SenderInterface $sender,
        private readonly Config $config,
        private readonly StoreManagerInterface $stores,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param int|null $websiteId the configuration scope shown, null for Default Config
     * @return array{bool, string} whether it was posted, and what to show
     */
    public function send(?int $websiteId): array
    {
        try {
            if ($websiteId === null) {
                $store = $this->stores->getDefaultStoreView();
            } else {
                $group = $this->stores->getGroup((string)$this->stores->getWebsite($websiteId)->getDefaultGroupId());
                $store = $this->stores->getStore($group->getDefaultStoreId());
            }
        } catch (\Exception) {
            $store = null;
        }
        if ($store === null) {
            return [false, (string)__('No store view found for this scope.')];
        }
        $storeId = (int)$store->getId();
        if ($this->config->getBotToken($storeId) === '') {
            return [false, (string)__('Enter the bot token and save the configuration first.')];
        }
        $channel = $this->config->getTestChannelId($storeId);
        if ($channel === '') {
            return [false, (string)__('Enter a test channel ID and save the configuration first.')];
        }
        $text = (string)__('Test message from %1: Slack is connected to your Magento notifications.', $store->getName());
        try {
            $result = $this->sender->send(Delivery::text($storeId, SlackProvider::CHANNEL, $channel,
                fn () => new DeliveryContent([], null, null, $text), false, null, false, self::TYPE));
        } catch (\InvalidArgumentException) {
            return [false, (string)__('The test channel ID is not a Slack channel ID (e.g. C0123ABCD: the channel\'s'
                . ' details, at the bottom).')];
        } catch (SlackException $error) {
            // already says what Slack refused and what to fix
            return [false, $error->getMessage()];
        } catch (TemporaryFailureException $error) {
            return [false, $error->getRetryAfter() !== null
                ? (string)__('Slack is limiting requests; try again in %1 seconds.', $error->getRetryAfter())
                : (string)__('Slack is limiting requests; try again in a minute.')];
        } catch (MessageSentException) {
            return [false, (string)__('Slack did not answer in time; the message may still arrive. Check the channel.')];
        } catch (\Throwable $error) {
            $this->logger->error('CommerceKings_NotificationSlack: the test message failed (' . get_class($error) . ': '
                . $error->getMessage() . ')');
            return [false, (string)__('The test message failed: %1', $error->getMessage())];
        }
        return $result === SenderInterface::SENT
            ? [true, (string)__('Posted to %1.', $channel)]
            : [false, (string)__('Not posted: %1.', $result)];
    }
}

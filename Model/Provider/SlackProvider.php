<?php
declare(strict_types=1);

namespace CommerceKings\NotificationSlack\Model\Provider;

use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\ProviderInterface;
use CommerceKings\NotificationSlack\Model\Config;
use CommerceKings\NotificationSlack\Model\Http\SlackClient;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;

/**
 * Notification's "slack" channel: posts DeliveryContent::$text to the Slack channel ID in Delivery::$recipient, as the
 * app's bot user of the delivery's website. For the shop's own team, so senders pass useCooldown: false and
 * stoppable: false (Delivery::text()).
 *
 * Light by design: Slack is called only from the queue consumer, cron or the admin - never while a shopper's request
 * waits (storefront, REST, SOAP, GraphQL), where a slow Slack would slow the shop. The client and the configuration are
 * proxies (di.xml), so building the provider pool costs nothing until a message is posted.
 */
class SlackProvider implements ProviderInterface
{
    public const CODE = 'slack';
    public const CHANNEL = 'slack';
    /** Slack truncates text over 40,000 characters; we cut earlier, visibly. */
    public const MAX_LENGTH = 39000;
    /** Areas of shoppers' requests: never posted from there. */
    private const SHOPPER_AREAS = [Area::AREA_FRONTEND, Area::AREA_WEBAPI_REST, Area::AREA_WEBAPI_SOAP, Area::AREA_GRAPHQL];

    public function __construct(
        private readonly SlackClient $client,
        private readonly Config $config,
        private readonly State $state,
        private readonly string $sapi = PHP_SAPI
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return 'Slack';
    }

    public function getChannel(): string
    {
        return self::CHANNEL;
    }

    public function supportsOneClickUnsubscribe(): bool
    {
        return false;
    }

    public function send(Delivery $delivery, DeliveryContent $content): void
    {
        if ($this->sapi !== 'cli' && in_array($this->areaCode(), self::SHOPPER_AREAS, true)) {
            throw new \LogicException('Slack messages are posted from the queue consumer, cron or the admin, never during'
                . ' a shopper\'s request; queue it (QueueInterface). Nothing was posted.');
        }
        $text = self::format((string)$content->text);
        if (trim($text) === '') {
            throw new \InvalidArgumentException('A Slack message needs text; nothing was posted.');
        }
        $this->client->postMessage($this->config->getApiBaseUrl($delivery->storeId),
            $this->config->getBotToken($delivery->storeId), $delivery->recipient, $text);
    }

    private function areaCode(): string
    {
        try {
            return $this->state->getAreaCode();
        } catch (LocalizedException) {
            return ''; // no area yet: not a storefront request
        }
    }

    /**
     * Plain text as Slack shows it: &, < and > escaped, so shop data cannot mention @channel or form links; line
     * breaks and *bold* stay. Text over MAX_LENGTH is cut with "…", never inside an escape.
     */
    public static function format(string $text): string
    {
        $text = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
        if (mb_strlen($text) <= self::MAX_LENGTH) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::MAX_LENGTH - 1);
        return preg_replace('/&[a-z]{0,3}$/D', '', $cut) . '…';
    }
}

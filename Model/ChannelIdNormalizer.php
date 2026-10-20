<?php
declare(strict_types=1);

namespace CommerceKings\NotificationSlack\Model;

use CommerceKings\Notification\Api\RecipientNormalizerInterface;

/**
 * Recipients on the "slack" channel are Slack channel IDs: C… (public), G… (private, older apps) or D… (a direct
 * message), letters and digits. A name such as "#orders" is refused (''), so Notification's sender stops before
 * anything is claimed or sent - Slack would answer channel_not_found after a rename anyway.
 */
class ChannelIdNormalizer implements RecipientNormalizerInterface
{
    public function normalize(string $recipient): string
    {
        $id = strtoupper(trim($recipient));
        return preg_match('/^[CGD][A-Z0-9]{8,20}$/D', $id) === 1 ? $id : '';
    }
}

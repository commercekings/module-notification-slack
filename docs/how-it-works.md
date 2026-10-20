# How it works

The parts of `commercekings/module-notification-slack`. Start with the [README](../README.md).

| Part | What it does | Notification extension point |
|---|---|---|
| `Model\Provider\SlackProvider` | `slack`: posts `DeliveryContent::$text` to the channel ID in `Delivery::$recipient` as the bot of the delivery's website; escapes `&` and `<` (every mention or link starts with `<`; `>` stays for `> ` quotes) and cuts text over 39,000 characters with "…"; refuses empty text and any storefront, REST, SOAP or GraphQL request (`\LogicException`, nothing sent) | `ProviderInterface`, `Model\ProviderPool` "providers" |
| `Model\ChannelIdNormalizer` | a recipient is a channel ID: trimmed, upper case, `C`, `G` or `D` and 8–20 letters or digits; anything else is `''`, so the sender throws `\InvalidArgumentException` before any claim | `RecipientNormalizerInterface`, `Model\RecipientNormalizerPool` "normalizers" |
| `Model\Http\SlackClient` | `POST <api_base_url>/chat.postMessage` over Guzzle: JSON `{channel, text, unfurl_links: false, unfurl_media: false}`, `Authorization: Bearer <token>`, 3 s to connect, 10 s in all | `Exception\TemporaryFailureException`, `Exception\MessageSentException` |
| `Model\Config` | the bot token (encrypted, sensitive), the test channel ID and the API URL, read for the delivery's store | – |
| `Model\TestMessage`, `Controller\Adminhtml\Test\Send`, `Block\Adminhtml\System\Config\TestButton` | *Send test message*: one message to the saved test channel of the scope shown, through Notification's sender (type `slack_test`, no cooldown, not stoppable); the answer says what to fix. ACL `CommerceKings_NotificationSlack::test_message` | `SenderInterface` |

**What a failure means.**

| Slack's answer | Exception | Meaning |
|---|---|---|
| 200 `{"ok": true}` | – | posted |
| 429, or 503 with `Retry-After`; 200 `ratelimited` or `service_unavailable` | `TemporaryFailureException` (`getRetryAfter()`) | nothing posted; try again after the wait |
| 200 `invalid_auth`, `channel_not_found`, `not_in_channel`, … ; any other 4xx | `SlackException` (`getSlackError()`, a hint in the message) | nothing posted; the setup needs fixing |
| 200 `internal_error` or `fatal_error`; other 5xx; an answer that is not Slack's | `MessageSentException` | may have been posted; never resend |
| host not found, connection refused, TLS failed, a timeout before anything was sent (cURL's report) | `\RuntimeException` | nothing posted |
| a timeout waiting for the answer, an empty reply | `MessageSentException` | may have been posted; never resend |

No message or log line contains the token or the text.

**Light by design.** No frontend code, plugins, observers, layout or cron. The provider pool builds every provider,
so the client and the settings are proxies: nothing is loaded until a message is posted.

## Tests

From the Magento root, with the package installed:

```sh
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-notification-slack/Test/Unit
MAGENTO_ROOT=$PWD vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-notification-slack/Test/Integration
MAGENTO_ROOT=$PWD node --test vendor/commercekings/module-notification-slack/Test/JavaScript/*.test.cjs
```

No test calls Slack: the unit tests use Guzzle's mock handler, the integration tests a stub client.

# Slack for Notifications<a href="https://commercekings.net"><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/commercekings/module-notification-slack/main/.github/commercekings-dark.svg"><img align="right" width="152" src="https://raw.githubusercontent.com/commercekings/module-notification-slack/main/.github/commercekings-light.svg" alt="Built by CommerceKings. Ready to sell more? Talk to us"></picture></a>

Works with Magento 2.4.4+ · PHP 8.1–8.5 · Slack Web API · **Beta**

[![License](https://badgen.net/github/license/commercekings/module-notification-slack)](LICENSE) [![Release](https://badgen.net/github/release/commercekings/module-notification-slack)](https://github.com/commercekings/module-notification-slack/releases) [![Checks](https://github.com/commercekings/module-notification-slack/actions/workflows/checks.yml/badge.svg?branch=main)](https://github.com/commercekings/module-notification-slack/actions/workflows/checks.yml)

**Messages for your team, in your Slack channels, through your own Slack app.**

Notification reaches your shoppers by email, text and WhatsApp; this module adds Slack for the people who run the
shop. Needs [module-notification](https://github.com/commercekings/module-notification), the base every notification
runs on.

What it gives you:

- **A Slack channel for Notification.** A `slack` channel and provider: any feature can post to a Slack channel ID
  through Notification's sender, with the same retry rules as every other channel.
- **Your app, your workspace.** Create a Slack app from the [manifest](docs/slack-app-manifest.json) in a minute; no
  third-party app, no App Directory review. The bot token is stored encrypted, per website.
- **Safe text.** Shop data can't ping `@channel` or anyone, or hide a link behind a label: `&` and `<` are escaped,
  and every Slack mention or labelled link starts with `<`. Line breaks and `> ` quotes stay. Slack still turns a bare
  URL into a link, and `*`, `_`, `~` and `` ` `` in shop text still format it.
- **Never posted twice, never lost to a rate limit.** Slack's rate limits are Notification's "try again later"; a
  timeout after the request left counts as posted, so a retry never doubles a message.
- **Light.** Nothing runs on storefront or checkout requests: no frontend code, plugins or observers. Slack is only
  called from the queue consumer, cron or the admin, with short timeouts (3 s to connect, 10 s in all).
- **Send test message** in the admin proves the token, the channel and the provider together.

It is a provider module: what to post and when belongs to the features that use it.

## Installation

```sh
composer require commercekings/module-notification-slack:^0.1
bin/magento setup:upgrade
```

Then, in Slack, **Create New App > From a manifest** with [docs/slack-app-manifest.json](docs/slack-app-manifest.json),
**Install to Workspace** and copy the **Bot User OAuth Token** (`xoxb-…`); for a private channel, `/invite @Magento`
in it. In Magento, **Stores > Configuration >
CommerceKings > Notifications > Slack**: paste the token, enter a test channel ID (`C0123456789`), save, and press
**Send test message**.

## Settings

| Field (scope) | Default |
|---|---|
| Bot token (website) | – stored encrypted; also `bin/magento config:sensitive:set commercekings_notification/slack/bot_token` |
| Test channel ID (website) | – where *Send test message* posts |
| `commercekings_notification/slack/api_base_url` (no admin field) | `https://slack.com/api` – only `https` on slack.com or a subdomain is used; anything else falls back to the default, with a warning in the log |
| `commercekings_notification/slack/allow_custom_api_base_url` (no admin field) | off – `1` allows any `api_base_url`, for a test double |

## Before going live

- Invite the app to private channels (`/invite @Magento`); public channels work through `chat:write.public`.
- Use one Slack app per workspace; a website without its own token uses the default one.
- Keep shopper data out of team messages: post counts and references, not names, emails or phone numbers.

## For developers

Post through Notification's sender, from a queue consumer or cron – never while a shopper's request waits:

```php
$sender->send(Delivery::text($storeId, 'slack', 'C0123456789',
    fn () => new DeliveryContent([], null, null, "*Store health*\nCron: OK"),
    useCooldown: false, stoppable: false, type: 'your_type'));
```

- **Recipient:** a Slack channel ID (`C…`, `G…` or `D…`) – in Slack, the channel's details, at the bottom. A channel
  name is refused before anything is sent.
- **`useCooldown: false`, `stoppable: false`:** team messages. With the cooldown on, the first message would mute the
  channel for the cooldown days.
- **Failures:** `TemporaryFailureException` – rate-limited, nothing posted, retry after `getRetryAfter()`;
  `MessageSentException` – it may have been posted, never resend; `SlackException` – Slack refused it
  (`getSlackError()`, e.g. `channel_not_found`), fix the setup; any other exception – nothing posted.

The details are in [How it works](docs/how-it-works.md).

## Extending

The stable parts are Notification's public API (`@api`), the `slack` channel code and the settings' paths. **No class
of this module is `@api`:** `SlackProvider`, `ChannelIdNormalizer`, `SlackClient`, `Config`, `TestMessage` and
`SlackException` may change in any release; use them only through the extension points below.

| For | Use |
|---|---|
| Posting to Slack from any module | `CommerceKings\Notification\Api\SenderInterface` with `Api\Data\Delivery` (channel `slack`, recipient a channel ID) and `Api\Data\DeliveryContent::$text` – all `@api` |
| Errors to handle | `Exception\TemporaryFailureException` (`getRetryAfter()`) and `Exception\MessageSentException` – Notification's, `@api`; `CommerceKings\NotificationSlack\Exception\SlackException` (`getSlackError()`) – this module's, not `@api`: catch it as `\RuntimeException` if you only need "refused" |
| Another Slack provider | Notification's `Model\ProviderPool` "providers" (`@api`), item `slack`: replace it with your `Api\ProviderInterface` |
| Other recipients than channel IDs | Notification's `Model\RecipientNormalizerPool` "normalizers" (`@api`), item `slack`: replace it with your `Api\RecipientNormalizerInterface` |
| Settings | the paths in [Settings](#settings) |

To replace the provider or the normalizer, add them to your module's `etc/di.xml` and load it after this one
(`<sequence><module name="CommerceKings_NotificationSlack"/></sequence>` in `module.xml`):

```xml
<type name="CommerceKings\Notification\Model\ProviderPool">
    <arguments>
        <argument name="providers" xsi:type="array">
            <item name="slack" xsi:type="object">Vendor\Module\Model\SlackProvider</item>
        </argument>
    </arguments>
</type>
<type name="CommerceKings\Notification\Model\RecipientNormalizerPool">
    <arguments>
        <argument name="normalizers" xsi:type="array">
            <item name="slack" xsi:type="object">Vendor\Module\Model\SlackRecipientNormalizer</item>
        </argument>
    </arguments>
</type>
```

A replacement provider keeps `getChannel()` returning `slack` and, like this one, posts only from the queue consumer,
cron or the admin.

## Limitations

- All text is escaped: a feature can't post a labelled link (`<url|text>`) or a mention (`<@U…>`, `<!here>`).
- Plain text (Slack's mrkdwn), no Block Kit layouts, files or threads.
- Bot tokens without rotation; Slack's incoming webhooks are not supported.
- One workspace per website.

## Tested (as of 0.1.0)

- **Automated tests:** 48 unit test cases (35 test methods, some with data sets), 3 JavaScript tests and 4 integration tests (unit and JavaScript tests run on
  every push); 89% line coverage by the unit tests.
- **End to end on Magento 2.4.9:** installed with Composer, the admin's *Send test message* and Notification's sender
  against a simulated Slack API: the decrypted token, the normalized channel ID, escaped text, and Slack's refusal
  shown with what to fix.
- **Live:** *Send test message* posted to a real Slack workspace with a real bot token.
- **Not tested live:** Slack's rate limits and refusals (simulated only).

## More

[How it works](docs/how-it-works.md) · [Slack app manifest](docs/slack-app-manifest.json) · [Compatibility](COMPATIBILITY.md) · [Changes](CHANGELOG.md)

---

**Need it installed, set up or customized?** **[Get in touch →](https://commercekings.net)**

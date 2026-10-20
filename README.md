# Slack for Notifications<a href="https://commercekings.net"><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/commercekings/module-notification-slack/main/.github/commercekings-dark.svg"><img align="right" width="152" src="https://raw.githubusercontent.com/commercekings/module-notification-slack/main/.github/commercekings-light.svg" alt="Built by CommerceKings. Ready to sell more? Talk to us"></picture></a>

Works with Magento 2.4.4+ · PHP 8.1–8.5 · Slack Web API

[![License](https://badgen.net/github/license/commercekings/module-notification-slack)](LICENSE) [![Release](https://badgen.net/github/release/commercekings/module-notification-slack)](https://github.com/commercekings/module-notification-slack/releases) [![Checks](https://github.com/commercekings/module-notification-slack/actions/workflows/checks.yml/badge.svg?branch=main)](https://github.com/commercekings/module-notification-slack/actions/workflows/checks.yml)

**Messages for your team, in your Slack channels, through your own Slack app.**

Notification reaches your shoppers by email, text and WhatsApp; this module adds Slack for the people who run the
shop. **Works together with:**

- [module-notification](https://github.com/commercekings/module-notification) – the base every notification runs on.

- **A Slack channel for Notification.** A `slack` channel and provider: any feature can post to a Slack channel ID
  through Notification's sender, with the same retry rules as every other channel.
- **Your app, your workspace.** Create a Slack app from the [manifest](docs/slack-app-manifest.json) in a minute; no
  third-party app, no App Directory review. The bot token is stored encrypted, per website.
- **Safe text.** Shop data can't ping `@channel` or slip in links: `&`, `<` and `>` are escaped; line breaks and
  `*bold*` stay.
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
**Install to Workspace** and copy the **Bot User OAuth Token** (`xoxb-…`). In Magento, **Stores > Configuration >
CommerceKings > Notifications > Slack**: paste the token, enter a test channel ID, save, and press **Send test
message**.

## Settings

| Field (scope) | Default |
|---|---|
| Bot token (website) | – stored encrypted; also `bin/magento config:sensitive:set commercekings_notification/slack/bot_token` |
| Test channel ID (website) | – where *Send test message* posts |
| `commercekings_notification/slack/api_base_url` (no admin field) | `https://slack.com/api` – only a test double changes it |

## For developers

Post through Notification's sender, from a queue consumer or cron – never while a shopper's request waits:

```php
$sender->send(Delivery::text($storeId, 'slack', 'C0123ABCD',
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

## Before going live

- Invite the app to private channels (`/invite @Magento`); public channels work through `chat:write.public`.
- Use one Slack app per workspace; a website without its own token uses the default one.
- Keep shopper data out of team messages: post counts and references, not names, emails or phone numbers.

## Limitations

- Plain text (Slack's mrkdwn), no Block Kit layouts, files or threads.
- Bot tokens without rotation; Slack's incoming webhooks are not supported.
- One workspace per website.

## Tested (as of 0.1.0)

- **Automated tests:** 43 unit tests, 3 JavaScript tests and 4 integration tests (unit and JavaScript tests run on
  every push).
- **Not tested:** a live Slack workspace.

## More

[How it works](docs/how-it-works.md) · [Slack app manifest](docs/slack-app-manifest.json) · [Compatibility](COMPATIBILITY.md) · [Changes](CHANGELOG.md)

---

**Need it installed, set up or customized?** **[Get in touch →](https://commercekings.net)**

# Changelog

## 0.1.0 - unreleased

First release. Requires Magento 2.4.4 or later and `commercekings/module-notification` 0.1.

- **Provider:** Slack (`slack`) for a new `slack` channel: `chat.postMessage` as your own app's bot, the bot token
  per website (stored encrypted, a sensitive setting); recipients are channel IDs (`C…`, `G…`, `D…`), anything else
  is refused before it is sent.
- **Safe text:** `&` and `<` escaped, so shop data cannot mention `@channel` or anyone, or form labelled links (bare
  URLs are still linked by Slack; mrkdwn `*`, `_`, `~`, `` ` `` still formats); `>` stays, so `> ` lines are quotes; text
  over 39,000 characters cut with "…".
- **API URL:** only `https` on `slack.com` unless `allow_custom_api_base_url` is set (for a test double); otherwise the
  default, with one warning in the log.
- **Failures:** HTTP 429, 503 with `Retry-After`, `ratelimited` and `service_unavailable` are Notification's
  `TemporaryFailureException`; Slack's refusals (`invalid_auth`, `channel_not_found`, …) a `SlackException` with what
  to fix; 5xx, `internal_error`, `fatal_error`, unreadable answers and timeouts after the request left count as maybe
  posted (`MessageSentException`, never posted twice).
- **Light:** nothing on storefront or checkout requests; never posts during a shopper's request; 3 s to connect,
  10 s in all; lazy client and settings.
- **Send test message** in the admin, with its own ACL resource.
- A Slack app manifest (`chat:write`, `chat:write.public`).
- JSON posted as UTF-8 (`charset=utf-8`); invalid UTF-8 in a text (a cut log line) is sent as U+FFFD, not refused.

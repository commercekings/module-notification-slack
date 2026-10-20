# Changelog

## Unreleased

First version. Requires Magento 2.4.4 or later and `commercekings/module-notification` 0.1.

- **Provider:** Slack (`slack`) for a new `slack` channel: `chat.postMessage` as your own app's bot, the bot token
  per website (stored encrypted, a sensitive setting); recipients are channel IDs (`C…`, `G…`, `D…`), anything else
  is refused before it is sent.
- **Safe text:** `&` and `<` escaped, so shop data cannot mention `@channel` or anyone, or form links; `>` stays, so
  `> ` lines are quotes; text over 39,000 characters cut with "…".
- **Failures:** HTTP 429, 503 with `Retry-After`, `ratelimited` and `service_unavailable` are Notification's
  `TemporaryFailureException`; Slack's refusals (`invalid_auth`, `channel_not_found`, …) a `SlackException` with what
  to fix; 5xx, `internal_error`, `fatal_error`, unreadable answers and timeouts after the request left count as maybe
  posted (`MessageSentException`, never posted twice).
- **Light:** nothing on storefront or checkout requests; never posts during a shopper's request; 3 s to connect,
  10 s in all; lazy client and settings.
- **Send test message** in the admin, with its own ACL resource.
- A Slack app manifest (`chat:write`, `chat:write.public`).

No public release has been tagged or published yet.

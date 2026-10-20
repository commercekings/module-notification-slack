# Compatibility and verification

The first-release runtime target is Magento Open Source 2.4.9 with PHP 8.5.
The Composer PHP range is a dependency constraint, not a claim that every
Magento/PHP combination has passed runtime testing.

**Minimum: Magento Open Source 2.4.4**, enforced by `magento/framework: ^103.0.4` and
`commercekings/module-notification`. It uses Guzzle (`guzzlehttp/guzzle`, shipped with Magento) and Slack's Web API
method `chat.postMessage` with a bot token.

| Magento Open Source | PHP | Runtime verification |
| --- | --- | --- |
| 2.4.9 | 8.4 | Passed locally: unit and JavaScript tests, PHPStan level 5, coding standard (no errors) |
| 2.4.4–2.4.8 | 8.1–8.4 | Unverified; use a matching Magento dependency set and services |

Sending against Slack's real API is not yet verified (no workspace).

The GitHub workflow checks package metadata, PHP syntax on 8.1–8.5, Git archive contents, the unit tests and the
JavaScript tests.

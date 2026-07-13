=== WP AI Kits ===
Contributors: wpaikits
Tags: media, ai, accessibility, alt text, images
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-written alt text, titles, and descriptions for your whole media library - in the background, on your own free API key.

== Description ==

WP AI Kits provides the free Media AI Kit:

* Generate image alt text, titles, and descriptions with a vision model (Google Gemini or Groq).
* You define the writing instructions, so metadata matches your brand voice.
* Per-field overwrite controls protect metadata your team already wrote.
* Process existing libraries through a paced background queue with pause, resume, and retry.
* Built for free-tier API keys: requests are spaced, and the queue cools down and resumes automatically when a provider rate limit is hit.
* A live activity log shows what was generated, skipped, or failed for every image.

Semantic media search and the Editor AI Kit are part of the separate WP AI Kits Pro add-on. Premium implementation is not included in this plugin.

The plugin connects to AI providers only after an administrator supplies credentials and enables the related features. Images are sent directly from your server to the provider you configure and nowhere else. Review each provider's terms and privacy policy before processing sensitive content; free tiers can have different data policies than paid plans. Recent AI requests and responses are stored for 7 days in a local log table for the admin audit page, then pruned automatically.

== Installation ==

1. Upload and activate WP AI Kits.
2. Open WP AI Kits in wp-admin and follow the onboarding wizard.
3. Paste a free API key from Google AI Studio or the Groq console.
4. Open Media AI Kit and start the bulk sync.

== Frequently Asked Questions ==

= Is it really free? =

The plugin is free and runs on your own API key. Google and Groq both offer free tiers generous enough for real libraries. There are no credits and no per-image pricing.

= What happens when I hit a rate limit? =

The background queue pauses itself for the provider's cooldown window and resumes automatically. Failed images are retried and everything is visible in the activity log.

= Will it overwrite metadata my team already wrote? =

Not unless you enable the per-field overwrite toggles. Filled fields are skipped by default.

== Troubleshooting ==

With `WP_DEBUG` and `WP_DEBUG_LOG` enabled, Media Sync and redacted LLM diagnostics are written to the normal WordPress debug log, usually `wp-content/debug.log`. Add `define( 'WPAK_SYNC_DEBUG', true );` only when a separate `wp-content/wpak-sync-debug.log` copy is also needed. API keys and image data are never logged.

== Changelog ==

= 1.0.0 =
* Initial public release of WP AI Kits and the free Media AI Kit.

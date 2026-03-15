=== Lumination AI Chatbot ===
Contributors: luminationteam
Tags: chatbot, ai, chat, assistant, education
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.3.4
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

AI-powered chat widget with floating and embedded display modes. Answers questions using the current page as context.

== Description ==

Lumination AI Chatbot adds an intelligent chat assistant to your WordPress site. The chatbot reads the content of the page the user is viewing and uses it as context for answers — making it ideal for educational sites, documentation, and tutoring platforms.

**Requires Lumination Core (free)** — install it first.

= Two Display Modes =

**Floating widget** — a purple chat bubble appears in the bottom-right corner of every page. Click it to open the chat panel. Toggle this on or off in the settings.

**Embedded panel** — place `[lumination_chatbot]` on any page or post for a full, always-open chat panel inline. Perfect for a dedicated "Chat with AI" page.

Both modes can be active at the same time.

= Features =

* Page-aware AI — the chatbot fetches and reads the current page as context for every response
* Custom instructions — set a persona or domain focus in the settings
* Conversation history — up to 12 turns sent with each message for coherent multi-turn chats
* Per-user rate limiting — prevents API abuse
* Access control — restrict via the `lumination_core_can_submit` filter
* Usage logged to the Core analytics dashboard

= Getting Started =

1. Install and activate **Lumination Core**, configure your API credentials.
2. Install and activate **Lumination AI Chatbot**.
3. Go to **Tools → Lumination → Chatbot** to customise appearance and behaviour.
4. Enable the floating widget, or add `[lumination_chatbot]` to a page — or both.

== Installation ==

1. Install **Lumination Core** first and configure your API credentials.
2. Upload the `lumination-chatbot` folder to `/wp-content/plugins/`.
3. Activate the plugin via the Plugins screen.
4. Go to **Tools → Lumination → Chatbot** and configure appearance.

== Frequently Asked Questions ==

= Can I use both floating and embedded at the same time? =

Yes. Enable the floating widget in settings and place `[lumination_chatbot]` on a specific page. Each instance is independent with its own conversation history.

= How does the chatbot know what page the user is on? =

When the user sends a message, the plugin fetches the current page URL server-side, strips navigation/headers/footers, and includes up to 8,000 characters of the page text in the AI prompt.

= Can I restrict who can use the chatbot? =

Yes — use the `lumination_core_can_submit` filter with capability `'chatbot'`.

= I upgraded from v1 — will my settings migrate? =

Yes. On first activation, the plugin automatically migrates `lmc_*` option names to the new `lumination_chatbot_*` names. API credentials are copied to Lumination Core's settings if they are not already set there.

== Changelog ==

= 2.3.4 =
* Fix: Floating widget no longer appears on pages that already have the embedded chatbot shortcode.

= 2.3.3 =
* Fix: Floating fullscreen backdrop no longer covers the chat panel (stacking context fix).

= 2.3.2 =
* Fix: Fullscreen panel centered using fixed + transform (bulletproof centering).
* Fix: Backdrop now darker (70% opacity) with blur effect.
* New: Click backdrop to exit fullscreen.

= 2.3.1 =
* Fix: Fullscreen panel now reliably centers over floating mode styles.

= 2.3.0 =
* Removed: File upload feature (temporarily removed for rework).
* All other 2.2.x features remain: fullscreen, typing indicator, suggested prompts, chat export, MathJax, page context toggle.

= 2.2.1 =
* Fix: Fullscreen now shows backdrop, blocks scroll, and is wider/centered.
* Fix: Image attachments sent using vision content blocks (Anthropic format).

= 2.2.0 =
* New: Fullscreen mode toggle (expand/collapse button in header).
* New: File upload support — images (vision API) and documents (PDF, TXT).
* New: Suggested prompts — configurable clickable starter questions.
* New: Chat export — download conversation as a .txt file.
* New: Typing indicator (animated three-dot bounce).
* New: Page context toggle — admin setting to enable/disable page content.
* Fix: MathJax/LaTeX now renders correctly in chat responses.

= 2.1.0 =
* Apply tool text color from Core appearance settings.

= 2.0.0 =
* Complete refactor to hub-and-spoke architecture (requires Lumination Core).
* New: embedded display mode via `[lumination_chatbot]` shortcode.
* New: floating widget toggle in admin settings.
* Automatic migration of v1 settings and analytics data.
* AJAX action renamed to `lumination_chatbot_send`.
* Settings moved to Tools → Lumination → Chatbot tab.

= 1.1.0 =
* Original floating chatbot release.

== Upgrade Notice ==

= 2.0.0 =
Requires Lumination Core. Install Core first. Your v1 settings will be migrated automatically.

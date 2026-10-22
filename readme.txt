=== Blaze MCP Toolkit for WooCommerce ===
Contributors: blazecommerce
Tags: woocommerce, mcp, ai, analytics, store health
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Ask Claude or ChatGPT how your WooCommerce store is doing: sales, errors, failed jobs, updates and API access. Our tools are read-only.

== Description ==

Blaze MCP Toolkit for WooCommerce connects your WooCommerce store to Claude or ChatGPT so you can ask plain questions and get straight answers: How did we do this week? Why did sales drop on Tuesday? Is anything broken? WooCommerce's own AI connection can look up products and orders. This plugin adds what it can't see: sales totals that match your WooCommerce reports, PHP errors, failed background jobs, recent plugin updates, and who has API access to your store.

It's read-only by default: out of the box it never changes your store, never sends customer names, emails or addresses from your orders to the AI, and adds nothing to the pages your customers load. It's a weekly check-up you can run in two minutes, so you spot problems before your customers do.

One thing to know up front: the same connection also carries tools from WooCommerce itself and from Enable Abilities for MCP, and some of those can edit orders and products or return customer details. The plugin's setup screen lists them and shows where to switch them off.

= What you can ask =

* "Run my weekly store review."
* "Sales were down yesterday. Did anything change on the site?"
* "Are there failed orders I should chase?"
* "What's driving refunds this month?"
* "Is anything broken on the store right now?"
* "Who has API access to my store?"

= Tools this plugin adds =

**Our store check tools**

* **Store health** (`health-summary`): red, amber or green for PHP errors, background jobs and updates, plus your WordPress, WooCommerce and PHP versions. It reads the tail of your PHP and WooCommerce error logs, groups repeated errors, names the plugin or theme they come from, and strips out emails, card numbers, API keys and passwords before anything reaches the AI. If the site has no error log it can read (common on managed hosts), it says so and shows amber rather than a false green. Background jobs covers failed and overdue Action Scheduler jobs from the last 7 days, with order emails, subscription renewals, webhooks and stock syncs called out.
* **Update history** (`update-history`): a dated list of plugin, theme and WordPress updates, installs, activations, deactivations and theme switches, recorded from the day you activate the plugin and kept for 180 days. Changes made outside WordPress, such as a deploy over SSH or git, are not seen.
* **Access check** (`access-check`): WooCommerce REST API keys, webhooks and application passwords, with flags for write keys unused for 90 days and webhooks that send order data. It never returns keys, secrets, full webhook addresses or user names.

**Workflows**

A workflow is a recipe: it tells the AI which tools to call, in what order, and how to write up the answer, so you get the same review every week without writing a long prompt.

* **Weekly store review**: sales, orders, customers, refunds, top products and channels for the last 7 days against the week before, plus store health and this week's updates.
* **Revenue drop triage**: works out where a drop came from (order count, order value, customers, products, channels) and checks for updates or new PHP errors in the same window.
* **Failed order triage**: failed and on-hold orders, the ones to chase first, and a plain warning when failed orders outnumber paid ones (a common sign of card testing or a broken payment gateway).
* **Refund triage**: refund rate, the products and countries behind it, and whether it is getting worse.

Each workflow checks first that WooCommerce Analytics data is complete. If Analytics is off, its import hasn't finished, or recent orders haven't synced, the AI says so instead of reporting zeros as fact.

**Sales analytics (from WooCommerce for Claude)**

Totals, breakdowns, trends over time, and order, product and customer rows, from the same data as your WooCommerce Analytics screens. Customers appear as "Customer #N", never by name. Ranges longer than 365 days ask you to confirm first.

**Store and catalogue tools (from WooCommerce for Claude)**

Store profile, product search, product details, an AI-readiness score for your catalogue, recommendations, and suggested product improvements. These are read-only too: suggested improvements are text for you to review, not changes.

= Who can use which tools =

Every tool checks the WordPress role of the person who connected the AI:

* Workflows: anyone who can manage WooCommerce (Administrator, Shop Manager).
* Sales analytics and store tools: WooCommerce's own permissions (Administrator, Shop Manager).
* Store health and update history: Administrators. Shop Managers only if you switch that on in the plugin's settings.
* Access check: Administrators only.
* Customers, Subscribers and other roles get a permission error, never data.

= Requires =

WordPress asks you to install and activate these before the plugin can be activated:

* [WooCommerce](https://wordpress.org/plugins/woocommerce/)
* [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/): the connection AI assistants talk to.
* [Enable Abilities for MCP](https://wordpress.org/plugins/enable-abilities-for-mcp/): the sign-in (OAuth) and connector URL for Claude and ChatGPT, and the switches for which tools are on.

This plugin has no sign-in or keys, and registers no REST routes of its own. It registers its tools with the WordPress Abilities API and lets those two plugins expose them.

= Setup =

1. Install and activate the plugin. WordPress asks you to install and activate WooCommerce, MCP Adapter and Enable Abilities for MCP first.
2. Open **WooCommerce > Blaze MCP Toolkit** and read the Setup check. Each line is green, amber or red, with the fix in plain words. Copy the connector URL shown there.
3. Open **Settings > WP Abilities** (Enable Abilities for MCP) and turn on OAuth. For ChatGPT, also turn on "ChatGPT & Other OAuth Connectors". While you are there, switch off the tools from other plugins you don't want the AI to use; the Setup check lists the ones that can change your store or return customer details.
4. Add the connector URL in your assistant. In Claude: Customize > Connectors > Add > Add custom connector. In ChatGPT: Plugins > Add > Add custom MCP server. Log in to wp-admin in the same browser first, then click Connect and click Allow once.
5. Ask: "Run my weekly store review."

= Privacy =

* Our diagnostics tools (store health, update history, access check) don't return customer contact details. They remove emails, phone numbers, card numbers and secrets from log lines, but log lines are free text and may still contain a name someone typed.
* The sales analytics tools (from WooCommerce for Claude) return totals, counts and, when asked, order rows with customers pseudonymised (Customer #N, never names or emails). They accept location filters such as billing city, state and postcode, but never filters on names or emails.
* Text that comes from the site (log lines, labels people typed in) is shortened and marked as untrusted, so the AI treats it as data and not as instructions.
* Each call to our tools is logged for 30 days: time, user ID, tool, a hash of the inputs, row count and duration. No customer data is logged. Store changes (updates, activations) are kept for 180 days.
* The plugin suggests wording for your privacy policy under Settings > Privacy.
* Nothing is added to your storefront: no scripts, styles or front-end hooks.

= Credits =

The sales analytics and store tools are the `commerce-abilities` package from [WooCommerce for Claude](https://github.com/woocommerce/woocommerce-claude) by Automattic, bundled unmodified under GPL-3.0-or-later (tag v0.4.5, commit cdab921aab8d07ef813121ff78b3558af28f39fa). The four workflows are adapted from WooCommerce for Claude's workflow prompts and extended with Blaze's health and update checks. Thank you to Automattic and the WooCommerce for Claude contributors.

Built by [Blaze Commerce](https://blazecommerce.io), a WooCommerce agency.

WooCommerce is a trademark of Automattic Inc. Blaze Commerce is not affiliated with or endorsed by Automattic, Anthropic or OpenAI.

== External Services ==

This plugin makes no outbound HTTP requests. It does not contact Blaze Commerce or anyone else.

When the store owner connects an AI assistant, the results of the tools the assistant calls are sent to that assistant's provider as part of the conversation:

* Claude, by Anthropic: [terms](https://www.anthropic.com/legal/consumer-terms), [privacy policy](https://www.anthropic.com/legal/privacy).
* ChatGPT, by OpenAI: [terms](https://openai.com/policies/terms-of-use), [privacy policy](https://openai.com/policies/privacy-policy).

What is sent depends on the question asked and the tools switched on. Sign-in (OAuth) and the connection itself are handled by Enable Abilities for MCP and MCP Adapter, not by this plugin.

== Installation ==

1. In wp-admin go to Plugins > Add New, search for "Blaze MCP Toolkit for WooCommerce", then install and activate it. If WooCommerce, MCP Adapter or Enable Abilities for MCP are missing, WordPress asks you to install and activate them first.
2. Follow the Setup steps in the Description: open WooCommerce > Blaze MCP Toolkit, turn on OAuth in Enable Abilities, and add the connector URL in Claude or ChatGPT.

== Frequently Asked Questions ==

= Does this plugin change my store? =

No. Every tool in this plugin only reads. The AI connection as a whole can include write tools from WooCommerce and Enable Abilities for MCP (for example, updating an order status). The setup screen lists them and links to where you switch them off.

= How do I add it to ChatGPT? =

In ChatGPT, open Plugins > Add > Add custom MCP server, paste the connector URL from WooCommerce > Blaze MCP Toolkit, and choose OAuth. Turn on "ChatGPT & Other OAuth Connectors" in Settings > WP Abilities first. In our testing this worked on a Free ChatGPT account.

= How do I add it to Claude? =

In claude.ai, open Customize > Connectors > Add > Add custom connector and paste the connector URL. The connector then works in Claude on the web, desktop and mobile. Claude may ask you to approve each tool call the first time; that's Claude's setting for the connector, not a sign that the tool writes anything.

= Connecting fails with a 403 error =

A firewall or CDN in front of your site (Cloudflare, Kinsta Bot Protection, a security plugin) is blocking the assistant's server. Add path exceptions for `/.well-known/oauth-*`, `/oauth/*` and `/wp-json/mcp/*`. Don't allow it by matching a user agent: that lets anything claiming to be the assistant through.

= The sign-in page says the session expired or the state is invalid =

Log in to wp-admin in the same browser before clicking Connect, then click Allow once. Clicking Allow twice shows an error even though the first click worked, so check the connector before trying again.

= Why are some tools off for a Shop Manager? =

Store health and update history show error logs and plugin details, so they are for Administrators by default. You can let Shop Managers use them with the checkbox on WooCommerce > Blaze MCP Toolkit. The access check lists API keys and app passwords, so it stays Administrator-only. When a workflow skips a step for your role, the AI tells you which check was skipped.

= Why does the AI say sales analytics are unavailable? =

The analytics tools read WooCommerce Analytics, and the plugin refuses to report numbers it knows are incomplete. There are three usual reasons:

* Analytics is turned off: WooCommerce > Settings > Advanced > Features.
* The historical import hasn't finished: Analytics > Settings > Import historical data.
* Recent orders haven't synced because WooCommerce's background jobs are stalled: check WooCommerce > Status > Scheduled Actions for pending wc-admin jobs.

The Setup check shows which one applies.

= I already use WooCommerce for Claude. What happens? =

This plugin steps aside for the tools the two share. While WooCommerce for Claude is active, the sales analytics and store tools come from it, and this plugin doesn't load its own copies, so you won't see duplicates or conflicts. Store health, update history, access check and the workflows keep working, and a notice on the setup screen explains this.

= My host doesn't give me an error log. Will store health still work? =

Yes. The PHP errors section shows amber and says no error log is available, rather than showing green, and the background jobs and updates sections work as normal. Turning on WP_DEBUG_LOG, or asking your host where PHP errors are written, gives it something to read.

= Does it work with HPOS? =

Yes, with High-Performance Order Storage on or off.

== Changelog ==

= 1.0.0 =
* First release.
* Store health, update history and access check tools.
* Weekly store review, revenue drop, failed order and refund workflows.
* Setup check screen with fixes for each step, and a privacy warning for other tools that return customer details.
* Bundles the commerce-abilities package from WooCommerce for Claude by Automattic (https://github.com/woocommerce/woocommerce-claude, GPL-3.0-or-later), version 0.4.5, commit cdab921aab8d07ef813121ff78b3558af28f39fa, unmodified. It provides the wc-analytics tools and the store knowledge tools.

== Upgrade Notice ==

= 1.0.0 =
First release: read-only store health, update history, access check and weekly review tools for Claude and ChatGPT.

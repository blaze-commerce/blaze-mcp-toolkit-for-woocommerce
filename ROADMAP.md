# Roadmap

Blaze MCP Toolkit for WooCommerce ships as a release train: small releases, each answering one question a store owner asks their AI assistant. This page lists what has shipped, what is next, and what is waiting.

Work is tracked in GitHub [milestones](https://github.com/blaze-commerce/blaze-mcp-toolkit-for-woocommerce/milestones): one per release, plus Backlog for accepted work that has no release yet.

## How a release is scoped

- **One owner question per minor release** (1.2, 1.3, ...). The question is written the way an owner would ask it, and the release is done when the assistant can answer it from the store's own data.
- **Fixes ride along.** Bugs and compatibility fixes join the next release that is ready, or a patch release (1.2.1) when they can't wait. A patch release adds no new question.
- **Ask-only tools.** The toolkit's own tools are read-only: none of them changes orders, settings or payments.
- **Every release passes the same gate**: unit and integration tests, the release matrix (each supported connection plugin and order storage mode), Plugin Check, and a smoke test in Claude and ChatGPT before it goes to WordPress.org.
- **New WordPress versions**: when nothing in the plugin changes, only `Tested up to` moves, without a new version.

## Shipped

| Release | Released | Owner question | What it added |
|---|---|---|---|
| 1.0.0 | 7 Nov 2026 | (first release) | Store health, update history and access check tools; weekly store review, revenue drop, failed order and refund workflows; the setup check screen. |
| 1.1.0 | 8 Nov 2026 | (plumbing: connector-neutral) | Connector-neutral: works with Easy MCP AI as well as MCP Adapter with Enable Abilities for MCP. The setup screen explains the connection plugin the store actually uses. |
| 1.2.0 | 16 Nov 2026 | "Is anything broken that's costing me sales?" | Payment health: for each payment method, whether it is still taking checkout orders, unpaid checkouts, failed payment plugin jobs, and logged errors or webhook problems. Unknown is reported as a gap, never as healthy. |
| 1.2.1 | 25 Nov 2026 | (patch) | Payment health also lists methods seen only on orders outside checkout, marked as not assessed, and says how complete its method list is. |

The [changelog in readme.txt](readme.txt) has the full detail for each version.

## Next: 1.3 ([milestone](https://github.com/blaze-commerce/blaze-mcp-toolkit-for-woocommerce/milestone/1))

- **Owner question: "Is someone testing stolen cards on my checkout?"** A card-testing check. Its scope is set in its own design before work starts.
- Fixes from the WordPress 7.2 compatibility pass:
  - [#14](https://github.com/blaze-commerce/blaze-mcp-toolkit-for-woocommerce/issues/14) Setup screen: count WordPress 7.2's `core/users-query` as a tool that returns customer personal data.
  - [#15](https://github.com/blaze-commerce/blaze-mcp-toolkit-for-woocommerce/issues/15) Release matrix: Easy MCP AI 2.5.1 seed timing and an `easy-2.5.1` state.
- [#16](https://github.com/blaze-commerce/blaze-mcp-toolkit-for-woocommerce/issues/16) This roadmap.

## Candidates ([Backlog milestone](https://github.com/blaze-commerce/blaze-mcp-toolkit-for-woocommerce/milestone/2))

Accepted, not yet scheduled, in no particular order. A candidate joins a release when it is ready, so this list is not a promise of order or timing.

- [#8](https://github.com/blaze-commerce/blaze-mcp-toolkit-for-woocommerce/issues/8) Matrix: fail on a site-local date change instead of re-running every state.
- [#9](https://github.com/blaze-commerce/blaze-mcp-toolkit-for-woocommerce/issues/9) Matrix: host-wide exclusive run lock.
- [#10](https://github.com/blaze-commerce/blaze-mcp-toolkit-for-woocommerce/issues/10) Health summary scores updates green when update status is unknown.
- [#11](https://github.com/blaze-commerce/blaze-mcp-toolkit-for-woocommerce/issues/11) Update history logs version changes as `plugin_installed`.
- [#12](https://github.com/blaze-commerce/blaze-mcp-toolkit-for-woocommerce/issues/12) Legacy-storage payment scan cost.

Ideas and bug reports are welcome as [issues](https://github.com/blaze-commerce/blaze-mcp-toolkit-for-woocommerce/issues).

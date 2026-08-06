# ADR-002: Global Web Search Tool (chat-only)

- Status: Accepted
- Date: 2026-07-31

## Context

Ask GAHolding answers questions strictly from approved, connected data sources.
Every reporting tool resolves a `DataSource` row, and the target URL always comes
from that row and is vetted by `IntegrationUrlGuard`. This is the deliberate design
recorded across the codebase: `ReportingDataGateway` comments state that keeping the
URL on the `DataSource` "keeps configurable tools from becoming arbitrary outbound
HTTP", and `AGENTS.md` lists "arbitrary outbound HTTP" among the things that must not
be added "without an approved architecture decision."

A new requirement asks the assistant to answer questions that no connected source can
cover — general, public-internet facts — by performing a live web search **in the chat
experience only** (not in scheduled reporting, exports, or delivery jobs).

A web search is precisely the exception the existing rule was written for: the request
target is not a `DataSource`, and the query string is driven by the model rather than
fixed configuration. It therefore needs its own decision rather than being folded into
the generic HTTP handler.

## Decision

Introduce a single, chat-only `web_search` capability with the following boundaries.

1. **Admin-configured, allow-listed provider host.** The provider endpoint and the
   `allowed_hosts` list are configured per tool on the AI Tools admin page and stored
   in the `ai_tools.options` column; the API key is stored in a new `secret_options`
   column, encrypted at rest (`encrypted:array` cast, `$hidden`, never audited or
   returned to the UI — only a `has_api_key` flag is exposed). The model never supplies
   a URL — only a query string. `IntegrationUrlGuard` still vets the resolved endpoint
   (HTTPS, no embedded credentials, public IP, DNS resolution), and the connector
   additionally asserts the resolved host is on the tool's `allowed_hosts` list. A hard
   response-size ceiling remains in `config/web_search.php` and is not editable from the
   UI.

2. **Still gated by the database allow-list.** The tool is exposed to the assistant
   only when an enabled `ai_tools` row uses the new `web_search` handler, exactly like
   every other tool, and only once a provider is configured (endpoint + hosts + key).
   `AiToolDefinition::HANDLERS` gains a `web_search` entry marked `standalone => true`
   so an administrator can select it but cannot invent a handler; `ToolRegistry` skips
   an enabled-but-unconfigured standalone tool the same way it skips an unimplemented
   handler.

3. **Chat-only.** The tool is only ever reachable through `ReportingAssistant`. It is
   not registered for scheduled jobs, exports, or delivery paths. No source-system
   writes, approvals, or operational changes are possible — it is read-only search.

4. **Permission-gated.** Execution requires a dedicated `ai.web_search` permission,
   checked inside the tool (mirroring `reports.view` on the reporting tool). It is not
   granted by default.

5. **Untrusted output, always cited.** Search results are treated as untrusted external
   content: they are size-capped, sanitized, and returned with per-result citations
   (title + URL + retrieved-at). The system prompt already instructs the model to treat
   tool results as untrusted data and to ignore instructions inside them; web results
   inherit that rule, and results must be visibly attributed to their source URL and
   kept separate from authoritative business figures.

6. **Hardened, sequential outbound HTTP.** The connector reuses the same hardening the
   integration layer applies: `acceptJson`, `withoutRedirecting`, a bounded timeout, a
   bounded retry count, a response-size cap, and no forwarded/hop-by-hop headers. Tool
   calls remain sequential (`parallel_tool_calls => false` is unchanged).

## Consequences

Positive:

- The assistant can answer public-internet questions without loosening the
  source-of-truth model for business data.
- The new outbound path is narrow: one configured provider, host allow-listed, still
  behind `IntegrationUrlGuard`, still behind the DB allow-list and a permission.
- Web content stays clearly attributed and segregated from company figures.

Tradeoffs / residual risk:

- This is the first outbound path whose target is configuration rather than a
  `DataSource`. The mitigations (fixed host allow-list + guard + permission + chat-only)
  are what make that acceptable; weakening any one of them reopens the "arbitrary
  outbound HTTP" risk.
- Web results are untrusted and may be wrong or adversarial; correctness depends on the
  model citing and hedging rather than asserting. Prompt guidance must make the
  provenance explicit to the reader.
- A provider API key is a new secret to store and rotate; it must never be logged.

## Providers

Two standalone handlers are implemented, both chat-only and permission-gated:

- `web_search` — a per-tool search-API provider (Tavily/Brave/Serper/etc.). The
  endpoint and allowed hosts live in `options`; the API key is encrypted in
  `secret_options`. Guarded by `IntegrationUrlGuard` + host allow-list.
- `openai_web_search` — reuses the application's configured OpenAI provider
  (`OPENAI_API_KEY`) and calls the Responses API with the `web_search` tool. No
  per-tool endpoint or key; the admin only picks a model. Marked
  `uses_ai_provider => 'openai'`, so `providerConfigured()` checks the OpenAI key and
  the request omits the endpoint/host/key fields. Outbound HTTP is the existing,
  already-trusted OpenAI provider path, so the arbitrary-outbound-HTTP concern does not
  apply to this handler.

## Implementation (as built)

The capability is admin-configurable from the AI Tools page. Files:

1. `database/migrations/2026_07_31_000400_add_secret_options_to_ai_tools_table.php` —
   adds the encrypted `secret_options` column and seeds a disabled `web_search` row.
2. `AiToolDefinition` — `web_search` entry in `HANDLERS` (`standalone => true`),
   `secret_options` (`encrypted:array`, `$hidden`), and `isStandalone()` /
   `providerConfigured()` helpers.
3. `App\Services\Ai\ToolRegistry` — branches on the handler: builds `WebSearchTool`
   for `web_search` with the row's merged provider config, `ConfiguredReportingTool`
   otherwise; skips the `source_types` requirement for standalone tools and skips an
   unconfigured one.
4. `App\Services\Integrations\WebSearchConnector` — guarded provider client, takes the
   provider config per call.
5. `App\Services\Ai\Tools\WebSearchTool` — the `AiTool` implementation, `ai.web_search`
   permission-gated.
6. `App\Http\Requests\AiToolRequest` — conditional validation: `source_types` optional
   for standalone, provider `options` and `api_key` validated; blank key = keep stored.
7. `App\Http\Controllers\AiToolController` — writes the key to `secret_options` (never
   mass-assigned, never audited), serializes provider status + `has_api_key` without the
   secret.
8. `config/web_search.php` — fallback defaults and the hard response-size ceiling only.
9. Frontend: `resources/js/composables/useAiTools.js` and
   `resources/js/pages/AiToolsPage.vue` — conditional provider-config fields, write-only
   API key with "leave blank to keep", standalone-aware table and reachability.
10. `database/seeders/DatabaseSeeder.php` — `ai.web_search` permission (granted to the
    administrator role).

An administrator enables the seeded `web_search` tool, fills in the endpoint, allowed
hosts and API key, and grants `ai.web_search` to the intended roles. Until a provider is
configured the tool is skipped by the registry, so behaviour is unchanged.

## Follow-up

- Confirm the real provider's request/response contract and adjust
  `WebSearchConnector::normalizeResults()` and the request parameter names (`q`,
  `count`) accordingly.
- Done: `ReportingAssistant` now lists configured standalone tools as "auxiliary tools"
  in the prompt (via `ToolRegistry::standaloneTools()`), so the model does not treat
  "no data source covers this" as "capability unavailable".
- Update `ai/architecture.md` (new outbound boundary) and
  `ai/features/feature-overview.md` (capability status).
- Add live acceptance against the chosen provider in controlled staging only; automated
  tests fake the provider (see `tests/Feature/WebSearchToolTest.php`).
- Confirm data-handling/privacy sign-off for sending user query text to an external
  search provider.

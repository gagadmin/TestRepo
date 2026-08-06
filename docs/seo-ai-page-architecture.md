# SEO AI Page — Architecture, Security & Delivery Plan

Reviewer role: software architect / security engineer / performance specialist.
Scope: a new "SEO AI" page for Ask GAHolding that turns Google Search Console
(and, later, Analytics + third-party) data into prioritized, AI-generated SEO
recommendations.

This is a **design and readiness review of a proposed feature**, not a review of
existing SEO code (none exists yet). Findings are tagged by severity and paired
with concrete solutions and file-level placement within the current codebase.

---

## 1. Executive summary

The requested product is achievable and fits the existing architecture (Laravel
12 services + connector registry + AI provider layer + Vue 3 SPA). However, a
blunt but important truth drives the whole design:

**Google Search Console + Google Analytics can power roughly half of the
requested features on their own. The other half — competitor keyword gaps,
backlink targets, and most "technical SEO issues" — cannot be derived from your
own property's GSC/Analytics data and require additional data sources.** Any plan
that hides this produces confident, wrong answers, which is the worst outcome for
an SEO tool.

Equally important: **"Top 5 in 60–90 days" is not something any data set can
guarantee.** Rankings depend on competitor behavior and Google's algorithm, which
are outside your data. The honest, defensible framing is a **probabilistic
opportunity score** ("these 12 keywords have the strongest measured signals for
reaching the Top 5") plus a transparent rationale — never a promise. This is
built into the scoring model below.

What is directly feasible **today** with the existing GSC connector (after a
small extension): positions 6–20 detection, high-impression/low-CTR pages,
country opportunities, and — once historical snapshots are stored — declining
rankings and ranking monitoring. What needs **new data sources**: competitor
gaps, backlink targets, and index/crawl/Core Web Vitals technical issues.

Recommended shape: a dedicated `app/Services/Seo` domain, a nightly snapshot job
feeding new `seo_*` tables, deterministic analyzers that produce the opportunity
list, and an AI layer that *explains and prioritizes* (never invents) the
findings — reusing the exact untrusted-output and permission patterns from the
web-search work (ADR-002).

---

## 2. Feasibility matrix (what the data can actually support)

| Requested capability | Data required | Available now? | Verdict |
| --- | --- | --- | --- |
| 1. Keywords in positions 6–20 | GSC `query` dim: position, impressions, clicks | Yes (needs multi-dim + filters) | **Build now** |
| 2. High impressions / low CTR pages | GSC `page` dim: impressions, ctr, position | Yes | **Build now** |
| 3. Country growth opportunities | GSC `country` dim | Yes | **Build now** |
| 4. Declining rankings | GSC position **over time** (period vs. period) | No — no history stored | **Build after snapshots** |
| 5. Technical SEO issues | URL Inspection / PageSpeed (CWV) **or** AI web-research | Partial | **AI web-research (qualitative) now; APIs later for precision** |
| 6. Competitor keyword gaps | Third-party SEO index **or** AI web-research (category + region) | Via web search | **AI web-research (qualitative)** |
| Ranking monitoring (feature) | GSC snapshots over time | No — no history | **Build after snapshots** |
| Top 5 prediction (feature) | Own signals; honest scoring | Partial | **Build as opportunity score, not a guarantee** |
| Market expansion (feature) | GSC `country`; GA4 for conversions | GSC yes / GA4 no | **Build (GSC) + GA4 later** |
| AI recommendations (feature) | Above + AI provider | Yes | **Build now** |
| Content opportunity discovery | GSC queries + optional keyword API | Partial | **Build (GSC) now, enrich later** |
| SEO health score (feature) | Composite of the above | Partial | **Build with available inputs** |
| Backlink targets (action plan) | Backlink index **or** AI web-research (category + region) | Via web search | **AI web-research (suggestive prospects)** |

Severity of the gap if ignored: **High** — shipping "competitor gaps" and
"backlink targets" backed only by GSC would fabricate data. The **AI web-research
mode (§7A)** closes this gap *qualitatively* by searching the live web for a
property's declared categories and region, with cited source URLs. It is not a
metric-grade replacement for a paid SEO index (no exact search volume, keyword
difficulty, or verified backlink counts) — it is cited, category-scoped market
intelligence. Label it as such in the UI.

---

## 3. Current-state constraints (verified in code)

- `GoogleSearchConsoleService::analytics()` queries **one dimension per call**
  (`query|page|country|device|date`), a single date range, `rowLimit ≤ 200`, and
  returns `clicks, impressions, ctr(%), position` + totals. It does **not**
  support combined dimensions (e.g. query×page×country) or period comparison.
- There is **no persistence** of GSC results beyond the short AI tool cache
  (`ai.tool_cache_seconds`, default 300s). Trend/decline detection is impossible
  without stored snapshots (GSC also caps history at ~16 months and has a 2–3 day
  data-freshness lag).
- There is **no Google Analytics (GA4) connector** — `website_analytics` as a
  source type exists, but the only implemented handler is
  `google_search_console`. Sessions/conversions/engagement are unavailable.
- Security invariants (AGENTS.md): no arbitrary outbound HTTP; external calls go
  through the connector registry + `IntegrationUrlGuard`; secrets encrypted;
  tool/AI output treated as untrusted. Any new SEO data provider must follow the
  same pattern used for `web_search` (ADR-002).
- Routing is meta-driven (`resources/js/router/routes.js`): a new page is a lazy
  route + `meta.permission/title/nav/icon/order`; the sidebar derives from meta.
- API routes are permission-grouped (e.g. `/api/analytics` behind
  `permission:analytics.view`, with `analytics.run` + `throttle:10,1` on compute).

---

## 4. Proposed architecture

### 4.1 Domain placement (mirrors existing `Services/Analytics`, `Services/Ai`)

```
app/Services/Seo/
  SearchConsoleGateway.php     # extends GSC access: multi-dim, period compare, paged
  SeoSnapshotService.php       # persists daily snapshots (idempotent per site+date)
  Analyzers/
    PositionOpportunityAnalyzer.php   # positions 6–20 (deterministic)
    CtrGapAnalyzer.php                # high impressions / low CTR
    CountryOpportunityAnalyzer.php    # country expansion
    RankingTrendAnalyzer.php          # decline/gain vs prior period (needs snapshots)
  SeoOpportunityScorer.php     # composite "closeness to Top 5" score (transparent)
  SeoHealthScore.php           # weighted health index from available inputs
  SeoInsightAssistant.php      # AI: explains + prioritizes; never invents numbers
app/Jobs/
  CaptureSeoSnapshotJob.php    # queued nightly per connected GSC property
app/Http/Controllers/
  SeoInsightsController.php     # read endpoints + AI action-plan endpoint
app/Http/Requests/
  SeoActionPlanRequest.php
database/migrations/           # seo_snapshots, seo_snapshot_rows, seo_action_plans
resources/js/pages/SeoInsightsPage.vue
resources/js/composables/useSeoInsights.js
resources/js/services/seoService.js
```

### 4.2 Data flow

1. **Ingest (async, nightly).** `CaptureSeoSnapshotJob` calls
   `SearchConsoleGateway` for each connected `google_search_console` DataSource,
   pulling query, page, and country dimensions for the freshest complete day (and
   a rolling window), and writes them via `SeoSnapshotService`. Stored history is
   what makes decline/monitoring possible.
2. **Analyze (deterministic, cached).** On page load the controller runs the
   analyzers over the latest snapshot(s). All numbers are computed in PHP from
   stored data — reproducible and testable. No AI in this step.
3. **Prioritize & explain (AI, on demand).** The user requests an action plan;
   `SeoInsightAssistant` sends the **already-computed** opportunity rows to the
   model with a strict instruction to explain, group, and sequence them — not to
   invent metrics. Output is stored as a `seo_action_plan` with citations back to
   the snapshot rows.

Rationale: keeping the metrics deterministic and the AI purely explanatory is the
single most important design decision — it prevents hallucinated rankings and
makes every recommendation auditable.

### 4.3 Reuse, don't reinvent

- Extend GSC access, don't fork it: `SearchConsoleGateway` wraps the existing
  `GoogleSearchConsoleService` (add a `analyticsMulti()` supporting a dimensions
  array + optional `dimensionFilterGroups`).
- AI: reuse `ProviderManager`/provider classes and the ADR-002 untrusted-output
  conventions; gate with a new `seo.view` / `seo.generate` permission pair
  mirroring `analytics.view` / `analytics.run`.
- External SEO providers (competitor/backlinks): register as connectors behind
  `IntegrationUrlGuard` + host allow-list, exactly like the `web_search` tool.

---

## 5. Analysis algorithms (deterministic, exact)

All thresholds are defaults in `config/seo.php` so they are tunable without code
changes.

**5.1 Positions 6–20 ("almost there").** From the `query` dimension over the
window: keep rows where `6 ≤ position ≤ 20` AND `impressions ≥ min_impressions`
(default 50). Sort by an opportunity score (§6). This is the core "closest to Top
5" list.

**5.2 High impressions / low CTR.** From the `page` (and `query`) dimension:
compute expected CTR for the row's average position from a position→CTR curve
(store a configurable baseline curve in `config/seo.php`; refine later from your
own aggregate data). Flag rows where `impressions ≥ min_impressions` AND
`ctr < expected_ctr * (1 - ctr_gap_tolerance)` (default tolerance 0.4). Impact =
`impressions * (expected_ctr - actual_ctr)` = recoverable clicks. This makes the
list rankable by real upside.

**5.3 Country growth opportunities.** From the `country` dimension: flag
countries with `impressions ≥ min_country_impressions` and either low CTR or
average `position` worse than the site mean — i.e. demand exists but capture is
weak. Rank by `impressions * gap`.

**5.4 Declining rankings (requires snapshots).** Compare current window vs. the
prior equal window per `query`/`page`: `Δposition = position_now - position_prev`
(positive = worse). Flag `Δposition ≥ decline_threshold` (default 1.5) with
`impressions_prev ≥ min_impressions`. Also surface gainers for context. Without
the snapshot table this feature cannot exist — do not fake it with a single
range.

**5.5 Technical SEO issues (needs new data).** Not derivable from
`searchAnalytics`. Options, in order of effort: (a) GSC **URL Inspection API** for
index status per URL; (b) **PageSpeed Insights API** for Core Web Vitals; (c) a
lightweight internal crawler for title/meta/canonical/H1/status codes. Until one
is integrated, present this section as "not connected" rather than guessing.

**5.6 Competitor keyword gaps (needs new data).** GSC only knows *your* property.
Requires a third-party SEO index (DataForSEO, Semrush, Ahrefs). Model as a new
connector; the analyzer diffs competitor-ranked keywords against your ranked set.
Until integrated, mark as unavailable.

---

## 6. Top-5 opportunity scoring (honest, transparent)

A single, explainable score per keyword — **not** a probability of success.

```
opportunity = w1 * proximity      // how close to Top 5: max(0, (position-5)) inverted, capped
            + w2 * demand         // log(impressions), normalized
            + w3 * ctr_headroom   // expected_ctr(position) - actual_ctr, clamped ≥ 0
            + w4 * trend          // improving over snapshots (+), declining (−)
            − w5 * difficulty     // optional, only if a keyword-difficulty source exists
```

Weights live in `config/seo.php`. Each component is stored alongside the score so
the UI and the AI can show *why* a keyword ranked highly. The "12 keywords"
output is simply the top-N by this score among positions 6–20, with each
component surfaced. **No time-to-rank guarantee is emitted**; the UI phrasing is
"strongest measured potential to reach Top 5," with the caveat that outcomes
depend on factors outside the data.

Severity if a guarantee is shown instead: **High** (misleads stakeholders,
reputational and commercial risk).

---

## 7. AI recommendation design

- Input: the deterministic opportunity rows + component scores + page/query
  context. **The AI receives numbers, it does not fetch or compute them.**
- Instruction (reusing ADR-002 conventions): "You are given already-verified SEO
  metrics. Do not invent figures, keywords, positions, or competitors. Produce
  content, technical, internal-link, and backlink *actions* for the supplied
  rows, grouped and sequenced by the supplied opportunity score. Mark any
  recommendation that would need data not provided as 'requires <source>'."
- Output stored in `seo_action_plans` with references to the snapshot rows that
  justified each item (auditable, like tool citations).
- Backlink targets from AI alone are **suggestive only** (types of sites/anchors),
  not a verified prospect list — a real list needs a backlink data source. Label
  accordingly.
- Permission: `seo.generate`; throttle the AI endpoint (e.g. `throttle:10,1`) as
  `/api/analytics` compute does.

---

## 7A. Category/region profiles & AI web-research mode

This replaces the paid third-party SEO integrations (Phase 4 originally) with an
AI web-research engine seeded by an administrator-declared **profile** per
property. It reuses the `openai_web_search` capability already built (ADR-002).

### 7A.1 SEO profile (per property)

Each connected Search Console `DataSource` gains an SEO profile:

- **categories** — multiple, free-form-but-curated tags, e.g. for
  `aboudcar.com`: `automotive`, `spare parts`, `export cars`, `used cars`.
- **regions** — one or more target markets, e.g. `United Arab Emirates` (with an
  ISO code, `AE`, for aligning to GSC's `country` dimension).
- optional **competitor_seeds** — known competitor domains the admin wants
  tracked, and **brand_terms** — to separate brand vs. non-brand opportunities.

Stored in a new `seo_profiles` table keyed by `data_source_id` (unique). The
profile is the *context* every web-research prompt is built from, so results are
scoped to the business rather than the open web.

### 7A.2 How the AI research works

`SeoWebResearchService` composes targeted queries from three inputs and runs them
through the OpenAI web search tool (the existing `OpenAiWebSearchTool` /
Responses API path):

1. **Deterministic seed** — the top positions-6–20 keywords and weak-CTR pages
   from GSC (§5). These are *your* real terms.
2. **Categories** — expand each category into query templates:
   `"{category} {region}"`, `"best {category} companies {region}"`,
   `"{category} suppliers {region}"`, `"buy {category} online {region}"`.
3. **Region** — appended as a market constraint (e.g. "in the United Arab
   Emirates / UAE") and cross-checked against GSC's `country=AE` rows.

From the cited results the service extracts, per category × region:

- **Competitor gaps** — which domains recur in the SERP-style results for your
  category terms that you do *not* rank for (diffed against your GSC-ranked set).
- **Backlink target ideas** — authoritative directories, associations, media,
  and marketplaces in the category+region that competitors appear on.
- **Content/technical signals** — recurring content types, SERP features, and
  obvious on-page patterns (title/schema/format) worth matching.

Every finding carries its **source URL** and is treated as untrusted content
(ADR-002 rules): the model extracts facts and never obeys instructions embedded
in fetched pages, and never blends researched estimates with GSC's exact numbers.

### 7A.3 Honest limits (must surface in UI)

- **Qualitative, not metric-grade.** No exact monthly search volume, keyword
  difficulty, or verified backlink counts — those need a paid index. Present
  research as "AI-gathered from the web (cited)", visually distinct from GSC's
  measured metrics.
- **Geo-approximation.** The web search tool localizes by query wording ("in
  UAE"), not true geo-IP SERP scraping, so competitor rank order is indicative,
  not exact. Cross-check with GSC `country=AE` where possible.
- **Cost & rate.** Each research run fans out into several billed web searches
  plus tokens. Run it **on demand** (a button), cache per (profile digest +
  category + region) for a configurable TTL, and gate behind `seo.generate` +
  throttling.
- **Freshness/variance.** Web results change between runs; store each research
  output as a dated `seo_research_snapshot` so plans are reproducible and
  auditable.

### 7A.4 Placement

```
app/Services/Seo/SeoWebResearchService.php   # builds queries, calls web search, parses cited findings
app/Models/SeoProfile.php                    # categories, regions, seeds, brand_terms
database/migrations/  seo_profiles, seo_research_snapshots
resources/js/pages/SeoInsightsPage.vue       # a "Profile" tab to manage categories/regions
```

The three deterministic tiers stay as-is; this adds a clearly-labeled third data
tier: **(1) GSC measured metrics → (2) deterministic analyzers → (3) AI
web-research (cited, qualitative)**. The action-plan AI (§7) then merges tiers 2
and 3, always attributing which tier each recommendation came from.

## 8. Database review

New tables (additive migrations, mirroring existing conventions):

- `seo_snapshots` — `id, data_source_id (fk), site_url, captured_for (date),
  window_from, window_to, dimension, created_at`. Unique
  `(data_source_id, captured_for, dimension)` for idempotent nightly capture.
- `seo_snapshot_rows` — `id, seo_snapshot_id (fk, cascade), key (query/page/
  country value), clicks, impressions, ctr, position`. Indexes on
  `(seo_snapshot_id)`, `(seo_snapshot_id, position)`, `(seo_snapshot_id, impressions)`.
- `seo_action_plans` — `id, user_id, data_source_id, summary, items (json),
  inputs_digest, model, created_at`.
- `seo_profiles` — `id, data_source_id (fk, unique), categories (json),
  regions (json), competitor_seeds (json, nullable), brand_terms (json,
  nullable), updated_by, timestamps`.
- `seo_research_snapshots` — `id, data_source_id (fk), profile_digest, category,
  region, findings (json: competitors/backlinks/signals with source URLs), model,
  created_at`. Index `(data_source_id, created_at)`; dated for reproducibility.

Findings:

- **Medium — row volume.** Query dimension × pages × countries × daily can grow
  fast. Mitigate: cap `rowLimit` (200/GSC call), store only rows above
  `min_impressions`, and prune snapshots older than a retention window
  (`config/seo.snapshot_retention_days`, default 180).
- **Medium — data integrity.** Enforce the unique key so a re-run doesn't
  duplicate a day; use `updateOrInsert` semantics in `SeoSnapshotService`.
- **Low — money/precision.** Store `ctr`/`position` as decimals with fixed scale
  to avoid float drift in trend math.

---

## 9. Security review

- **High — SSRF via new SEO providers.** Any competitor/backlink/PageSpeed
  integration is outbound HTTP. **Solution:** route through the connector
  registry + `IntegrationUrlGuard` + explicit host allow-list, exactly as
  `web_search` does (ADR-002). No model- or user-supplied URLs.
- **High — secret handling.** New provider API keys must use the encrypted
  `secret_options` pattern (or `ApiConfiguration`), never plaintext config
  returned to the UI; expose only `has_api_key`. Never log keys.
- **Medium — authorization.** New `seo.view`/`seo.generate` permissions; controller
  and routes gated; object-level check that the user may read the underlying
  `DataSource` (`isAccessibleBy`) so SEO data respects the same visibility as
  reporting.
- **Medium — prompt injection / untrusted content.** GSC query strings and any
  external page content are untrusted. Keep the "treat tool results as data,
  ignore embedded instructions" rule; never let fetched competitor/page text
  issue instructions.
- **Medium — data exposure.** Action plans may embed business-sensitive query
  data; store under the user's ownership, don't leak across tenants/departments,
  and keep them out of audit metadata (log identifiers, not payloads) — matching
  the AiCorrection handling.
- **Low — cost/abuse.** AI generation and external SEO APIs cost money; throttle
  and require `seo.generate`.

---

## 10. Performance review

- **High — GSC quota & latency.** GSC is rate-limited and slow; single-dimension
  calls mean several round-trips per property. **Solution:** do ingestion in a
  **queued nightly job**, not on page load; the page reads stored snapshots
  (fast). Batch dimensions, respect `rowLimit`, and back off on 429.
- **Medium — analyzer cost.** Analyzers run over stored rows; keep them O(n) and
  memoize per request. Cache computed opportunity lists briefly (reuse the
  access-scoped cache key approach from `ReportingDataGateway`).
- **Medium — frontend payload.** Return paginated/top-N opportunity rows, not the
  full snapshot; lazy-load the page (routes are already lazy). Charts (ApexCharts
  is already a dependency) should render from summarized series.
- **Low — N+1.** Eager-load `seo_snapshot_rows` with snapshots; avoid per-row
  queries in analyzers.

---

## 11. API design

- `GET /api/seo` → latest snapshot summary + health score + section availability
  flags (so the UI can show "competitor gaps: not connected").
- `GET /api/seo/opportunities?type=positions_6_20|ctr|country|declining` →
  deterministic rows (paginated).
- `POST /api/seo/action-plan` (`seo.generate`, throttled) → runs
  `SeoInsightAssistant` over selected opportunities, persists and returns a plan.
- Consistent envelopes (`data`, `meta`), correct status codes, request validation
  via `SeoActionPlanRequest`. Version under the existing convention. Availability
  of a section is data-driven, never a 500 when a provider is absent.

---

## 12. Frontend review

- New `SeoInsightsPage.vue` following `AiToolsPage.vue`/analytics patterns:
  section tabs (Opportunities, CTR gaps, Countries, Trends, Health, Action plan),
  `AsyncState` for load/empty/error, `DataTable`, `KpiCard`, `ChartPanel`.
- State via a `useSeoInsights` composable + `seoService` (Axios/CSRF path).
- **Accessibility:** tables need headers/scope; score gauges need text
  equivalents; color-coded severity must not be color-only.
- **UX honesty:** surface "not connected" states for competitor/technical
  sections; label AI backlink ideas as suggestions; show the opportunity-score
  breakdown and the "no guarantee" caveat inline.

---

## 13. Testing plan

- **Unit:** each analyzer against fixture snapshot rows (positions 6–20 boundary
  cases; CTR curve; country ranking; decline threshold with two snapshots). The
  scorer with known inputs → known ordering.
- **Feature:** GSC ingestion faked via `Http::fake` (never live); controller
  authorization (`seo.view`/`seo.generate`), throttling, and section-availability
  flags; action-plan endpoint with a faked AI provider asserting the model
  receives **only** provided numbers and that output persists with references.
- **Edge cases:** empty property, single snapshot (decline unavailable),
  provider not configured, `sc-domain:` vs URL-prefix property, GSC 429/latency.
- **Frontend:** component tests for empty/error/available states.

---

## 14. DevOps & observability

- Schedule `CaptureSeoSnapshotJob` in `routes/console.php` (the app already uses
  the scheduler); ensure queue workers run (compose `dev` script already runs
  `queue:listen`).
- Log ingestion outcomes (rows captured, quota errors) without payloads; add a
  failure record path like `AiToolFailure` so a broken GSC connector is visible
  in admin instead of silently empty.
- Config via `config/seo.php` + env for thresholds, retention, provider keys.

---

## 15. Business-logic verification & edge cases

- **Data lag:** GSC finalizes data 2–3 days late; the "freshest complete day" must
  account for this or trends will look like drops. **High** if ignored.
- **Property type:** `sc-domain:` aggregates subdomains; URL-prefix does not —
  interpret "pages" accordingly.
- **Brand vs non-brand:** many positions-6–20 wins are branded queries with
  little real upside; add an optional brand-term filter so the Top-12 isn't
  dominated by brand terms.
- **Zero/low data:** small properties won't have statistically meaningful CTR;
  gate flags behind `min_impressions`.
- **Single snapshot:** decline/monitoring must degrade gracefully to "collecting
  data — check back after N days."

---

## 16. Prioritized action plan (build roadmap)

**Phase 0 — Foundations (1–2 days).** `config/seo.php`, `seo_*` migrations,
`seo.view`/`seo.generate` permissions, empty page + route + nav entry, section
availability flags. *Ships a visible, honest shell.*

**Phase 1 — GSC-native insights (3–5 days).** `SearchConsoleGateway.analyticsMulti()`,
`PositionOpportunityAnalyzer`, `CtrGapAnalyzer`, `CountryOpportunityAnalyzer`,
`SeoOpportunityScorer`, read endpoints, page tables/charts, unit + feature tests.
*Delivers items 1, 2, 3 and the Top-12 opportunity list (no guarantee).*

**Phase 2 — History & trends (DONE).** `seo_snapshots` + `seo_snapshot_rows`,
`SeoSnapshotService` (idempotent), `CaptureSeoSnapshotJob`, `seo:capture-snapshots`
+ `seo:purge-snapshots` commands scheduled nightly (04:00 / 04:30),
`RankingTrendAnalyzer` (declining/gaining + monitoring series), the trend now
feeds the opportunity scorer, and a Trends tab. *Delivers item 4 and ranking
monitoring.*

**Phase 3 — AI action plans (DONE).** `seo_action_plans` table + model,
`SeoInsightAssistant` (feeds the deterministic findings to the configured
provider, requires strict-JSON, parses defensively, never invents numbers),
`generateActionPlan` / `actionPlans` endpoints (`seo.generate`, throttled) + an
AI action plan tab, and faked-provider tests. *Delivers AI recommendations.*

**Phase 4 — Category/region profiles + AI web-research (DONE).** `seo_profiles`
(Phase 0) + `seo_research_snapshots`; `SeoWebResearchService` calls the OpenAI
Responses API web search tool (same trusted provider path — no new outbound HTTP),
seeded by categories + regions + real GSC keywords, and parses cited competitors,
backlink targets, technical and content signals; a Research tab (cited, labeled
qualitative); the latest research is merged into the action plan as an attributed
tier-3 input; faked-provider tests. *Delivers items 5, 6 and backlink prospects —
qualitatively, with cited sources.*

**Phase 5 — Optional paid indexes (scoped separately).** If metric-grade
precision is later required (exact search volume, keyword difficulty, verified
backlinks), add PageSpeed/URL Inspection and a paid SEO index as guarded
connectors. Each provider = its own ADR.

---

## 17. Scores

These rate the **design and the current codebase's readiness to deliver it**, not
existing SEO code (none exists).

- **Architectural fit with existing patterns: 88/100.** Reuses services,
  connectors, AI layer, meta-driven routing cleanly.
- **Data completeness for the full spec: 70/100.** GSC covers the measured half;
  the AI web-research mode (§7A) covers competitor/backlink/technical
  *qualitatively* with cited sources. The remaining 30 is metric-grade precision
  (exact volume/difficulty/verified backlinks) that only a paid index provides.
- **Design readiness (Phases 0–4): 84/100.** All buildable now — Phase 4 reuses
  the `openai_web_search` capability already shipped.
- **Production readiness of the full requested spec today: 55/100.** No longer
  blocked on paid data; remaining risk is presenting web-research findings
  honestly (qualitative, cited) and the ranking-opportunity framing.

## Top 10 priorities

1. **Reframe "Top 5 guarantee" as a transparent opportunity score.** (High)
2. **Add the snapshot store** — without it, decline/monitoring can't exist. (High)
3. **Keep metrics deterministic; AI explains only.** Prevents hallucination. (High)
4. **Extend GSC access to multi-dimension + period compare.** (High)
5. **Route any new SEO provider through the URL guard + allow-list.** (High, security)
6. **Encrypt new provider keys; expose only `has_api_key`.** (High, security)
7. **Label AI web-research (competitor/backlink/technical) as qualitative + cited,
   visually distinct from GSC's measured metrics; never blend the two.** (High, correctness)
8. **Do GSC ingestion in a queued job; page reads stored data.** (High, perf)
9. **Add `seo.view`/`seo.generate` permissions + throttle AI/compute.** (Medium)
10. **Account for GSC's 2–3 day data lag and add a brand-term filter.** (Medium)

## Security risk assessment (summary)

Highest risks are SSRF and secret handling on the future external providers, and
misleading output (fabricated competitor/backlink data, guaranteed rankings).
All are mitigated by the ADR-002 patterns plus the "deterministic metrics, AI
explains only, mark-unavailable-when-absent" rules above.

## Technical debt / watch-items

GSC single-dimension limitation, no GA4 connector, and no historical store are
the debts to retire — in that order. Competitor/backlink/technical data is now
covered qualitatively by AI web-research (§7A); a paid index (Phase 5) is the
only remaining upgrade, and only if metric-grade precision is required. None
block Phases 0–4.

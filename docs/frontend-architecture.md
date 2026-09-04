# Frontend Architecture

Status: migration in progress. Foundation complete, one of ten views extracted.

## 1. Architecture review

### Before

`resources/js/App.vue` was a single 4,784-line component:

| Metric | Count |
| --- | --- |
| Lines (script / template) | 1,847 / 2,934 |
| `ref()` declarations | 91 |
| `computed()` declarations | 41 |
| Functions | 80 |
| Inline `axios` calls | 38 |
| Inlined views | 10 |
| Route/permission definitions | duplicated in 2 places |
| Frontend tests | 0 |

Every concern lived in one scope: authentication, HTTP, routing, ten feature
domains, chart configuration, formatting, and all dialog state.

### After (target)

```
resources/js/
├── App.vue                 57 lines — layout selection only
├── app.js                  entry: Pinia, router, PrimeVue
├── layouts/                AppLayout, AuthLayout, LegacyLayout*
├── pages/                  one component per route
├── components/
│   ├── ui/                 AsyncState, DataTable, KpiCard, ChartPanel, PageHeader
│   ├── layout/             AppSidebar, AppTopbar
│   └── security/           domain components
├── composables/            reusable reactive logic
├── services/               all Laravel API access
├── stores/                 Pinia: auth, ui
├── router/                 routes + guards
└── tests/                  Vitest setup

* temporary, deleted when migration completes
```

## 2. Problems found

**P1 — Duplicated async loading (highest impact).**
Fifteen loader functions repeated the same
`loading → try → catch → finally` block. Each had subtly different error
precedence, so the same 403 produced different messages depending on which
screen you were on. Fixed by `useAsyncResource`.

**P2 — No API boundary.**
38 `axios` calls inline in the component. A URL or auth change meant editing
the component. Fixed by `services/`.

**P3 — Duplicated authorization.**
Permissions were declared in `navItems`/`adminItems` *and* re-checked in each
view's error handler, and had to be manually kept in sync with the backend
routes. Fixed by `route.meta.permission` as the single declaration.

**P4 — No routing.**
Navigation was `currentView.value = 'x'`. No browser history, no deep links, no
shareable URLs (known issue KI-012). One ad-hoc `applyDeepLink()` read
`?report=` from the query string. Fixed by vue-router in history mode.

**P5 — Everything in one bundle.**
All ten views shipped to every user regardless of permission. Fixed by lazy
route imports.

**P6 — Ten near-identical chart config objects.**
`itsmPieOptions`, `itsmBarOptions`, `slaCategoryChartOptions`,
`securityTrendChartOptions` and six others each re-declared the same font,
palette, toolbar and tooltip. Fixed by `useCharts`.

**P7 — Repeated formatting.**
`toLocaleString()` appeared dozens of times inline; severity→colour maps were
re-declared per view, so the same status could be a different colour on two
screens. Fixed by `useFormatters`.

**P8 — Module-scoped timer.**
`let clockIntervalId = null` sat at module scope, shared across instances and
leaked if the component mounted twice. Fixed by scoping it in `useClock`.

**P9 — Inconsistent state presentation.**
Each view hand-rolled its own loading spinner, error banner and empty state
with different wording and markup. Fixed by `AsyncState`.

**P10 — Deep reactivity on large payloads.**
API responses were held in `ref()`, making Vue deeply reactive over payloads
that are always replaced wholesale. `useAsyncResource` uses `shallowRef`.

## 3. Key design decisions

### Authorization is declared once

```js
// router/routes.js
{ path: '/security', name: 'security', meta: { permission: 'security.view' } }
```

This drives the router guard *and* the sidebar (`useNavigation` derives nav
items from the route table). Adding a route adds its nav entry.

The frontend guard is a **usability** control, not a security control. Every
API route is independently protected server-side, so a forged URL still returns
403. The Security API additionally requires IT-department membership or a
privileged role via the `security.access` middleware.

### Race-condition safety

`useAsyncResource` carries a request token. If a user changes the trend period
from 90 to 7 days, the slower 90-day response cannot overwrite the newer 7-day
result. The old code had no such protection.

### Failed refresh preserves data

`keepPreviousOnError: true` (default) means a transient failure shows an error
banner *above* the existing content rather than blanking the screen.

### `ApiError` normalisation

One precedence rule everywhere: validation message → server message →
caller fallback, with 403 producing a resource-specific sentence.

## 4. Migration procedure

The app is fully working at every step. Un-migrated views run from
`pages/LegacyWorkspacePage.vue`, which keeps its own chrome and is rendered bare
via `LegacyLayout`.

### Per-view checklist

1. **Add a service method** in `services/<domain>Service.js` — move the URL and
   error message out of the component.
2. **Add a composable** `composables/use<Domain>.js` — wrap the service in
   `useAsyncResource`, and move every derived `computed` there.
3. **Create the page** `pages/<Name>Page.vue` — template plus wiring only. No
   `axios`, no arithmetic, no formatting logic.
4. **Extract repeated markup** into `components/<domain>/` when a block exceeds
   roughly 80 lines or is used twice.
5. **Repoint the route**: swap `LegacyWorkspacePage` for the new page and delete
   `layout: 'legacy'` and the `TODO(migration)` comment.
6. **Delete the old block** from `LegacyWorkspacePage.vue` — the
   `<template v-else-if="currentView === '<name>'">` block, plus any refs and
   functions now unused.
7. **Write specs** for the composable and service.
8. **Update `routes.spec.js`** — decrement the expected legacy count.
9. **Verify**: `npm run test && npm run build`, then click through the view.

### Worked example: Security

| Step | Result |
| --- | --- |
| Service | `services/securityService.js` — 4 methods |
| Composable | `composables/useSecurityDashboard.js` — fetch, sections, triage, charts |
| Page | `pages/SecurityPage.vue` — 483 lines, composition only |
| Components | `SecurityScoreCard`, `SecurityEventTable`, `SecurityTriageDialog`, `SecurityCoverageGrid` |
| Removed from monolith | 594 template lines + 87-line dialog |

### Recommended order

Smallest and least entangled first, so the pattern is proven before the hard ones:

1. ~~`audit`~~ — done. `auditService` + `useAuditTrail` + `AuditTrailPage`;
   80 template lines and 27 script lines left the monolith. Paging moved into
   the composable and gained the guards the inline version lacked.
2. ~~`users`~~ — done. `adminService.createUser` + `useUserDirectory` +
   `UsersPage`, with `UserAccessDialog` and `CreateUserDialog` extracted; 457
   template lines and ~200 script lines left the monolith. Note it is two
   dialogs, not one: the access editor and the provisioning flow with its
   show-once credentials panel.
3. `analytics` — one loader, one action
4. `schedules` — form-heavy but self-contained
5. `integrations` — largest dialog; extract the credential form as a component
6. `reports` — has the `?report=` deep link; becomes `/reports/:reportId`
7. `ai` — chat state; consider a dedicated store for streaming later
8. `dashboards` — the biggest. Extract the ITSM panel with
   `useSlaCategoryExplorer` (already written and tested) and one component per
   chart group
9. `overview` — do last; it reads platform state the other pages settle first

## 5. Testing

```bash
npm install       # first run only: pulls vue-router, pinia, vitest
npm run test
npm run test:watch
npm run build
```

Current specs (54 assertions):

| Spec | Covers |
| --- | --- |
| `useAsyncResource.spec.js` | lifecycle, error retention, stale-response discard |
| `http.spec.js` | error precedence, 403 wording, query pruning |
| `authStore.spec.js` | permission checks, login/logout, session expiry |
| `useFormatters.spec.js` | nullish handling, duration units, severity maps |
| `useSlaCategoryExplorer.spec.js` | cascade, aggregation, divide-by-zero |
| `routes.spec.js` | no unprotected route, unique names, migration tracker |

`tests/setup.js` mocks `axios` globally, so a unit test cannot reach the
network.

## 6. Accessibility and performance notes

Improvements made while extracting:

- Nav items for unavailable features are **hidden**, not rendered disabled.
- The score bar carries `role="progressbar"` with `aria-valuenow`/`aria-label`.
- Loading states use `role="status"` + `aria-live="polite"`.
- Trend direction is conveyed in text (`hint`) as well as colour and icon.
- `DataTable` uses `scope="col"` and a visually-hidden `<caption>`.
- Decorative icons are `aria-hidden="true"`.
- Every page chunk is lazily loaded; `apexcharts` remains a separate async
  chunk, and `vue-router` + `pinia` are bundled as `app-core`.

## 7. Remaining risks

- **`LegacyWorkspacePage.vue` is 4,159 lines** and still holds nine views. It
  is unchanged behaviourally but remains the main source of regression risk
  until migrated.
- **Duplicate bootstrap request.** Both the auth store and the legacy component
  call `/api/bootstrap` on first load. Harmless, and resolves itself when
  `overview` is migrated.
- **No component-level tests yet.** Only logic is covered. Playwright smoke
  tests over the nine legacy views would materially de-risk the remaining work
  (known issue KI-007 is only partly closed).
- **History-mode routing** depends on the existing Laravel
  `Route::fallback(...)` serving the SPA shell. Verified present.

# Phase 2 Integration Framework

## Supported source classes

| Type | Intended systems | Current adapter |
| --- | --- | --- |
| CRM | Salesforce, Dynamics, HubSpot, custom CRM | Governed HTTP API |
| ERP | Oracle, Dynamics, custom ERP | Governed HTTP API |
| SAP | SAP API and OData services | Governed HTTP API |
| Asset Management | EAM and inventory platforms | Governed HTTP API |
| Procurement | Sourcing and spend platforms | Governed HTTP API |
| Website Analytics | Analytics and traffic APIs | Governed HTTP API |
| Internal Application | Approved internal services | Governed HTTP API |

Vendor-specific pagination, schema normalization, and reporting adapters will be
added when real API specifications are supplied. They implement the same
`DataConnector` contract and remain behind the connector registry.

## Authentication

Supported methods:

- No authentication
- Bearer token
- API key with a configurable header name
- HTTP Basic authentication
- Additional encrypted request headers

Credentials and headers use Laravel encrypted casts. API responses expose only
boolean `has_credentials` and `has_headers` indicators.

## Connection lifecycle

1. An authorized administrator creates a source.
2. Laravel validates its type, URL, paths, timeouts, retries, and authentication settings.
3. Credentials are encrypted before persistence.
4. A connection test applies URL security policy and calls the configured health endpoint.
5. The platform records success or failure, duration, HTTP status, safe context, initiator, and timestamps.
6. The source status becomes `connected` or `error`.

## Network security

- HTTPS is required by default.
- URLs containing embedded credentials are rejected.
- Localhost, private addresses, and reserved addresses are blocked by default.
- Hostnames are resolved and checked before outbound requests outside the test environment.
- Private enterprise APIs require `INTEGRATION_ALLOW_PRIVATE_NETWORKS=true` in the controlled deployment environment.
- Response bodies and credentials are not written to connection history.

## Protected endpoints

All endpoints require an authenticated user with `integrations.manage`.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/api/integrations` | List safe integration metadata and option definitions |
| POST | `/api/integrations` | Create an encrypted source configuration |
| PUT | `/api/integrations/{id}` | Update metadata and optionally rotate credentials |
| POST | `/api/integrations/{id}/test` | Test health and persist the result |
| DELETE | `/api/integrations/{id}` | Remove a source and its dependent history |

## Google Search Console verification

Google Search Console uses a dedicated read-only service-account connection check:

```dotenv
GOOGLE_SEARCH_CONSOLE_SITE_URL=https://www.example.com/
GOOGLE_APPLICATION_CREDENTIALS=storage/app/google/search-console-service-account.json
```

Run:

```bash
php artisan search-console:test
```

Authorized administrators can run the same check through:

```http
POST /api/integrations/search-console/test
```

The route requires `integrations.manage`, active session authentication, CSRF protection, and a five-request-per-minute throttle.

The command signs a short-lived OAuth assertion with the local service-account key, requests only the `webmasters.readonly` scope, lists accessible Search Console properties, and verifies an exact match with the configured property. It never prints the private key or access token.

Before the live check can pass:

1. Enable the Google Search Console API in the service account's Google Cloud project.
2. Add the service-account email as a user of the exact Search Console property.
3. Keep the JSON credential outside source control and readable only by the application runtime identity.

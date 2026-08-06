# Troubleshooting

## The application loads assets from a stopped Vite server

Delete `public/hot` and run either `npm run dev` or `npm run build`. The Vite build includes a safeguard that removes a stale hot-file marker.

## The browser blocks Vite assets through CSP

For local development, set `VITE_DEV_SERVER_URL` to the exact Vite origin written in `public/hot` (normally `http://127.0.0.1:5173`), clear Laravel's configuration cache, and restart the Laravel server:

```bash
php artisan optimize:clear
npm run dev
```

The local CSP permits only that configured HTTP origin and its matching WebSocket origin. Production continues to allow scripts and connections from the application origin only.

## Login or POST/PUT/DELETE requests return 419

- Load the SPA from the Laravel origin rather than opening built files directly.
- Confirm cookies are enabled.
- Verify Axios sends credentials and `X-XSRF-TOKEN`.
- Check `APP_URL`, `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, and HTTPS termination.
- Clear stale Laravel caches with `php artisan optimize:clear`.

## Tests use the wrong database or stale routes

Run:

```bash
php artisan optimize:clear
php artisan test
```

`tests/bootstrap.php` removes generated route/config/event caches and PHPUnit forces `DB_CONNECTION=sqlite` with `DB_DATABASE=:memory:`. If behavior persists, ensure a custom PHPUnit command is not bypassing `phpunit.xml`.

## AI status is not ready

- Set `AI_PROVIDER` to `google`, `openai`, or `azure`.
- For Google AI Studio, configure `GEMINI_API_KEY` and a supported `AI_MODEL`. The current default is stable `gemini-3.5-flash`; `gemini-2.5-flash` can return `404 NOT_FOUND` for new AI Studio users even when it appears in the model catalog.
- For OpenAI, configure `OPENAI_API_KEY`; optionally configure organization/project.
- For Azure, configure both `AZURE_OPENAI_API_KEY` and the complete approved `AZURE_OPENAI_RESPONSES_URL`.
- Confirm the deployment has HTTPS egress to the endpoint.
- Never paste keys into logs, issues, or chat transcripts.

Google AI Studio `429` responses can indicate either quota exhaustion or temporary rate limiting. Ask GAHolding performs bounded retries and returns a safe message; review the Gemini API quota and billing if the error persists.

## Integration URL is rejected

HTTPS is required by default. Localhost, embedded credentials, and private/reserved IPs are blocked unless `INTEGRATION_ALLOW_PRIVATE_NETWORKS=true` is deliberately enabled in a controlled enterprise deployment. Verify DNS and the exact resolved address with the network team rather than weakening validation globally.

## Integration test fails without useful data

Review the safe integration run record for status, HTTP code, duration, error code, and message. Then validate authentication type, encrypted credential presence, health path, timeout, and vendor rate limits. Response bodies and credentials are intentionally not stored.

## Google Search Console test fails

Run `php artisan search-console:test`.

- `configuration_error`: confirm `GOOGLE_SEARCH_CONSOLE_SITE_URL` and `GOOGLE_APPLICATION_CREDENTIALS`, and verify that PHP can read the JSON file.
- `site_not_accessible`: add the credential file's service-account email as a user of the exact Search Console property. URL-prefix properties include the scheme and trailing slash; domain properties use `sc-domain:example.com`.
- `accessNotConfigured`: enable the Google Search Console API in the Google Cloud project identified by the credential file's `project_id`, wait for propagation, and rerun the command.
- Other authentication rejection: verify that the service-account key is active.
- `connection_failed`: verify HTTPS egress to Google's OAuth and Search Console endpoints.

Never print, upload, or commit the service-account JSON key.

For a Search Console data source, do not configure `/health`, a reporting path, or any endpoint on the company website. Select the `Google Search Console` source type and enter only the exact Search Console property. Laravel uses `GOOGLE_APPLICATION_CREDENTIALS` to call Google's OAuth and Search Analytics endpoints directly. After the source test succeeds, dashboard users can select query, page, country, device, or date analytics from the dashboard explorer.

For Freshservice, use the underlying `https://<account>.freshservice.com` domain because API v2 does not support custom CNAME endpoints. Select `Freshservice ITSM`, choose basic authentication, place the Freshservice agent API key in the API-key/username field, and use `X` as the password placeholder. The configured agent must be allowed to view tickets, agents, and groups. The dashboard uses only read endpoints and returns aggregate counts rather than raw ticket content.

Freshservice custom statuses are discovered from `/api/v2/ticket_form_fields`. Configure the source's on-hold status IDs to match statuses where the SLA timer is disabled; for the current GA Holding workflow these are `3` (Pending) and `8` (Awaiting Approval). The Ask GAHolding dashboard uses live API values, while Freshservice Analytics reports normally synchronize changes at intervals and can lag by roughly 30 minutes.

## A source cannot be deleted

A report still references its ID inside the report definition. Reassign or remove those reports before deleting the source. Do not bypass this guard, because it prevents broken report definitions.

## Scheduled reports do not run

Check:

```bash
php artisan schedule:list
php artisan queue:failed
php artisan queue:work --tries=3 --timeout=180
```

Confirm the operating system invokes `php artisan schedule:run` each minute, a queue worker is alive, `QUEUE_CONNECTION` is correct, the database queue tables exist, and server time/timezone data are correct.

## Email or Teams delivery fails

- Email: validate the Laravel mailer, sender, network access, and recipient list.
- Teams: validate `TEAMS_WEBHOOK_URL` and the organization's workflow/webhook policy.
- Review `report_schedule_runs.channel_results` and the schedule's failure/error fields.
- Use staging recipients and webhooks for acceptance tests.

## PDF or Excel export fails

Confirm PHP extensions required by Dompdf and PhpSpreadsheet are installed, the report is authorized, the latest source response is valid JSON, and memory/row limits are suitable. Check `REPORT_MAX_SNAPSHOT_ROWS` and available temporary storage.

## Encrypted records cannot be decrypted

The deployment likely has the wrong `APP_KEY` or lacks a previous key after rotation. Restore the correct keys from the secret manager. Do not overwrite records or generate a new key until recovery is understood.

## Production shows CSP or connection errors

Production CSP limits browser connections and scripts to same-origin. AI and source calls should originate from Laravel, not the browser. If a new browser origin is genuinely required, document and narrowly update the CSP rather than switching the environment to `local`.

## Postman synchronization produces incomplete paths

The workflow only inspects added `Route::get/post/put/patch/delete` declarations. Laravel group prefixes, computed paths, and multiline declarations can make the extracted path relative or absent. Review the workflow summary and update the Postman collection manually when necessary.

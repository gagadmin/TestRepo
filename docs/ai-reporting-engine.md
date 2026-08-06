# Phase 3 AI Reporting Engine

## Architecture

```text
Ask GAHolding UI
    |
    v
Authenticated Laravel conversation API
    |
    v
ReportingAssistant orchestration loop
    |-- ProviderManager
    |     |-- Google AI Studio Gemini generateContent API
    |     |-- OpenAI Responses API
    |     `-- Azure OpenAI Responses endpoint
    |
    `-- ToolRegistry
          |-- get_sales_report
          |-- get_asset_summary
          |-- get_procurement_report
          |-- get_website_analytics
          `-- get_crm_pipeline
                    |
                    v
          Authorized connected data source
```

## Design decisions

- The engine uses the Responses API for reasoning and tool-using requests.
- The default model is stable `gemini-3.5-flash`, configurable through `AI_MODEL`.
- Function schemas use strict mode, require every property, and reject additional properties.
- Parallel tool calls are disabled so each read can be authorized, bounded, and audited independently.
- Provider-side response storage is disabled with `store: false`.
- Conversation history is owned by the application and limited by `AI_HISTORY_MESSAGES`.
- Source output is treated as untrusted data and cannot override system instructions.
- The engine never supplies a database query tool, arbitrary HTTP tool, write tool, shell, or code execution tool.

For OpenAI, these choices follow the official guidance for the
[Responses API](https://developers.openai.com/api/docs/guides/text),
[function calling](https://developers.openai.com/api/docs/guides/function-calling),
and [GPT-5.6 prompting](https://developers.openai.com/api/docs/guides/latest-model?model=gpt-5.6#prompting-best-practices).

## Tool execution controls

Every tool request passes through:

1. Approved-name lookup in `ToolRegistry`.
2. Strict JSON schema at the model boundary.
3. Laravel validation at the application boundary.
4. `reports.view` permission validation.
5. Connected-source type validation.
6. Optional source-level `allowed_roles` filtering.
7. HTTPS and private-network policy.
8. Response-size and JSON-format validation.
9. Citation creation and execution audit recording.

Raw source responses are supplied to the active model turn but are not copied
into tool-execution audit records. Audit records retain safe summaries, source
citations, arguments, timing, status, and errors.

## Provider configuration

Google AI Studio (default):

```dotenv
AI_PROVIDER=google
AI_MODEL=gemini-3.5-flash
GEMINI_API_KEY=
GOOGLE_AI_STUDIO_BASE_URL=https://generativelanguage.googleapis.com/v1beta
```

OpenAI:

```dotenv
AI_PROVIDER=openai
AI_MODEL=gpt-5.6-sol
OPENAI_API_KEY=
```

Azure OpenAI:

```dotenv
AI_PROVIDER=azure
AI_MODEL=your-deployment-model
AZURE_OPENAI_API_KEY=
AZURE_OPENAI_RESPONSES_URL=
```

The Azure URL is explicit so deployments can use the endpoint and API version
approved by the organization's Azure environment.

## Production acceptance still required

- Supply a Google AI Studio, OpenAI, or Azure OpenAI credential through the deployment secret store.
- Connect at least one real reporting source with a `data_path`.
- Run the grounded-answer evaluation set against representative business questions.
- Validate tool selection, authorization, source accuracy, latency, and token usage.
- Pin an approved model snapshot after evaluation if deterministic rollout behavior is required.

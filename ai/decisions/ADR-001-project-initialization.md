# ADR-001: Repository Documentation and LIFT Change Workflow

- Status: Accepted
- Date: 2026-07-27

## Context

Ask GAHolding entered onboarding with working application code and several phase documents, but no consolidated repository context, architecture, schema, API contract, operational guide, issue register, or agent instructions. The implementation spans security-sensitive AI, integrations, reporting, encrypted data, queues, and external delivery, so changes require reliable discovery and verification.

## Decision

Adopt:

1. The `ai/` directory as the maintained engineering knowledge base.
2. Root `AGENTS.md` as the repository working agreement.
3. LIFT for material changes:
   - **Learn** the relevant contracts, code, tests, and risks.
   - **Intend** the smallest coherent change and validation plan.
   - **Forge** the change within existing architectural boundaries.
   - **Tune** through focused tests, full regression checks, build validation, and documentation updates.
4. Executable routes, migrations, tests, and code as the primary source of truth.
5. A GitHub workflow that assists with Postman route synchronization while requiring human review for Laravel routing constructs it cannot infer.

## Consequences

Positive:

- New contributors receive a single, evidence-based entry point.
- Architecture, security boundaries, schema, APIs, operations, risks, and debt are explicit.
- Documentation changes become part of the definition of done.
- AI-assisted changes follow the same reviewable workflow as human changes.

Tradeoffs:

- Documentation must be maintained alongside code.
- LIFT adds a deliberate discovery and validation step to small changes.
- The Postman synchronization is best-effort and cannot replace an OpenAPI contract or manual review.

## Follow-up

- Initialize/import the project into Git and verify the workflow in a non-production Postman collection.
- Add PostgreSQL CI, OpenAPI, frontend tests, and production deployment definitions.
- Create additional ADRs for public API authentication/versioning, deployment topology, and vendor-specific connector contracts.

# ADR: Native Named Query Resource

## Status

Accepted for implementation.

## Decision

Named Queries are a child resource named `_query` of a DreamFactory SQL
service. Definitions are stored in the DreamFactory system database and refer
to `service.id`; they never copy source URLs, users, or passwords.

The initial SQL endpoint is:

```text
GET|POST /api/v2/{service}/_query/{name}
```

`pgsql_query` is the first query-enabled service type. Oracle, SQL Server, and
Informix service types receive the same resource through
`HasNamedQueryResource`.

## Lifecycle

- `named_query` holds the stable service-scoped name and publication pointer.
- `named_query_revision` holds an immutable definition revision.
- Only the revision referenced by `published_revision_id` is executable.
- A future repository API performs optimistic locking through `lock_version`.

The administrative resource is `system/named_query`. It creates a draft with
`POST`, creates a new revision with `PATCH /{id}`, and publishes the requested
revision when `publish_revision_id` is included in the PATCH payload. These
operations remain inside DreamFactory's system-service authorization pipeline.

The source-service database account must have read-only database permissions.
SQL validation is defense in depth; it is not a replacement for database
privileges, particularly when a database exposes side-effecting functions.

## Security

- The first SQL compiler only accepts a single `SELECT` or `WITH` statement.
- User values are declared parameters and become unique bound placeholders.
- Parameters inside string literals, quoted identifiers, and comments are not
  substituted.
- RBAC uses native components `_query` and `_query/{name}`.

## Compatibility Boundary

The native endpoint has DreamFactory's response contract. The legacy
`query-builder` and `query-param` paths remain a later internal adapter after
RBAC, envelopes, and the query catalog are complete.

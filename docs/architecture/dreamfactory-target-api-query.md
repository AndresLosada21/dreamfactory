# Target DreamFactory Architecture for API Query

This is the target architecture for replacing api-query without creating a
permanent sidecar or a parallel administration and authorization platform.
All new capabilities remain inside the native DreamFactory lifecycle.

```mermaid
flowchart TB
    subgraph Consumers[Existing and new consumers]
        ApiSync[api-sync]
        Legacy[Current api-query clients]
        Native[Native DreamFactory clients]
        Admin[DreamFactory Admin UI]
    end

    subgraph Edge[Existing high-availability entry point]
        VIP[VIP and reverse proxy]
        Nodes[Two or more DreamFactory nodes]
    end

    subgraph NativeLifecycle[DreamFactory native lifecycle]
        Router[Native routing and legacy route adapter]
        Identity[API key, JWT, session and legacy credential adapter]
        RBAC[DreamFactory roles, apps and per-query permissions]
        Dispatch[ServiceManager dispatch]
        Events[Events, audit and request correlation]
        OpenAPI[Dynamic OpenAPI and API Docs]
    end

    subgraph NamedQueryPackage[New native Named Query package]
        QueryResource[Database resource: _query]
        Catalog[Versioned query catalog]
        Validator[Definition validator and publish workflow]
        SqlCompiler[Named SQL compiler]
        JsonCompiler[Legacy JSON DSL compiler]
        Budgets[Timeout, rows, bytes, parameters and concurrency budgets]
        Normalizer[Cross-driver result normalization]
    end

    subgraph DatabaseServices[Native DreamFactory database services]
        Postgres[PostgreSQL service]
        Oracle[Independent Oracle service package]
        SqlServer[Independent SQL Server service package]
        Informix[Independent Informix service package]
        Capabilities[Driver and dialect capability contracts]
    end

    subgraph Credentials[Connection and secret lifecycle]
        ServiceConfig[Protected DreamFactory service configuration]
        SgcResolver[Optional hardened SGC resolver]
        SecretStore[Approved secret store]
    end

    subgraph SharedPlatform[Shared multi-node platform state]
        SystemDb[(DreamFactory system database)]
        DistributedCache[(Distributed cache and invalidation)]
        Telemetry[Metrics, traces, logs and audit]
    end

    subgraph SourceSystems[Existing source databases]
        PgDb[(PTG PostgreSQL)]
        OracleDb[(WMS Oracle)]
        SqlServerDb[(GQ SQL Server)]
        InformixDb[(Pymac Informix)]
        LocalDb[(py-local source)]
    end

    ApiSync --> VIP
    Legacy --> VIP
    Native --> VIP
    Admin --> VIP
    VIP --> Nodes
    Nodes --> Router
    Router --> Identity
    Identity --> RBAC
    RBAC --> Dispatch
    Dispatch --> QueryResource
    QueryResource --> Events
    QueryResource --> Catalog
    Catalog --> Validator
    Validator --> SqlCompiler
    Validator --> JsonCompiler
    SqlCompiler --> Budgets
    JsonCompiler --> Budgets
    Budgets --> Capabilities
    Capabilities --> Postgres
    Capabilities --> Oracle
    Capabilities --> SqlServer
    Capabilities --> Informix
    Postgres --> Normalizer
    Oracle --> Normalizer
    SqlServer --> Normalizer
    Informix --> Normalizer

    Postgres --> PgDb
    Oracle --> OracleDb
    SqlServer --> SqlServerDb
    Informix --> InformixDb
    SqlServer --> LocalDb

    QueryResource --> OpenAPI
    Catalog --> SystemDb
    ServiceConfig --> SystemDb
    Nodes <--> DistributedCache
    Events --> Telemetry
    Normalizer --> Events

    Postgres --> ServiceConfig
    Oracle --> ServiceConfig
    SqlServer --> ServiceConfig
    Informix --> ServiceConfig
    ServiceConfig --> SecretStore
    SgcResolver --> SecretStore
    ServiceConfig -. eligible connection failure .-> SgcResolver
```

## Target Design Rules

- `_query` is a native child resource of DreamFactory SQL services.
- Query definitions reference an existing DreamFactory service by stable ID;
  they never duplicate database passwords.
- Existing api-query routes and headers are implemented by an internal
  compatibility adapter and can be retired after consumer migration.
- Native DreamFactory roles, applications and API keys remain the source of
  authorization truth.
- SQL and JSON definitions are validated and published as versioned metadata.
- Request values are always bound parameters and never SQL fragments or
  identifiers.
- Execution budgets apply across statements and JSON DSL subqueries.
- OpenAPI, events, audit, Admin UI and cache invalidation use DreamFactory's
  existing extension points.
- Oracle, SQL Server and Informix connectors are independently implemented
  from Apache-licensed interfaces and vendor documentation.
- The proprietary DreamFactory connector implementations are not source
  material for the independent packages.
- All nodes share the system database and distributed invalidation mechanism;
  sticky sessions are not required.

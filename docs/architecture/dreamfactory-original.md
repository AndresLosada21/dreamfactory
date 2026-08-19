# DreamFactory Original Architecture

This diagram represents the main runtime architecture of DreamFactory 7.7.0
before the proposed named-query and api-query compatibility extensions.

```mermaid
flowchart TB
    subgraph Clients[API consumers and administrators]
        App[Applications and integrations]
        Admin[DreamFactory Admin UI]
        Swagger[API Docs and Swagger UI]
    end

    subgraph Edge[HTTP entry point]
        Proxy[Web server or reverse proxy]
        Laravel[DreamFactory Laravel application]
    end

    subgraph RequestLifecycle[Native request lifecycle]
        Router[API v2 routing]
        Auth[API key, JWT, session and user authentication]
        RBAC[Role and service permission checks]
        Dispatch[ServiceManager and BaseRestService dispatch]
        Events[Pre-process, post-process and final events]
        Response[DreamFactory response and error formatting]
    end

    subgraph NativeServices[Composer-provided service types]
        System[System service]
        SqlDb[SQL database services]
        NoSql[NoSQL database services]
        Remote[Remote HTTP and SOAP services]
        Script[Scripted services]
        Files[File and object storage services]
        ApiBuilder[API Builder services]
    end

    subgraph PlatformData[Platform state]
        SystemDb[(DreamFactory system database)]
        Cache[(Cache, sessions and queues)]
        Audit[(Logs and audit records)]
    end

    subgraph ExternalSources[External systems]
        Databases[(Configured databases)]
        HttpApis[HTTP and SOAP APIs]
        Storage[File and object stores]
    end

    App --> Proxy
    Admin --> Proxy
    Swagger --> Proxy
    Proxy --> Laravel
    Laravel --> Router
    Router --> Auth
    Auth --> RBAC
    RBAC --> Dispatch
    Dispatch --> Events
    Events --> System
    Events --> SqlDb
    Events --> NoSql
    Events --> Remote
    Events --> Script
    Events --> Files
    Events --> ApiBuilder

    System --> SystemDb
    SqlDb --> Databases
    NoSql --> Databases
    Remote --> HttpApis
    Script --> Databases
    Script --> HttpApis
    Files --> Storage
    ApiBuilder --> SqlDb
    ApiBuilder --> Remote
    ApiBuilder --> Script

    Laravel <--> Cache
    Laravel --> Audit
    System --> Response
    SqlDb --> Response
    NoSql --> Response
    Remote --> Response
    Script --> Response
    Files --> Response
    ApiBuilder --> Response
    Response --> Proxy
```

## Architectural Characteristics

- DreamFactory is a Laravel application assembled from Composer packages.
- Service types are registered through Laravel service providers and the
  DreamFactory service manager.
- Authentication, RBAC, events, OpenAPI generation and response handling are
  shared platform capabilities.
- Service configuration, roles, applications and API metadata are stored in
  the DreamFactory system database.
- Database services expose standard resources such as tables, schemas,
  procedures and functions.
- The Admin UI consumes the same system APIs used by automation.

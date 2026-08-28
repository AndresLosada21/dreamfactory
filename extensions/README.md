# Yamaha DreamFactory Connectors

These packages are independent DreamFactory database connectors used to replace
the sources currently exposed by `api-query`.

- `df-oracle` uses the OCI8-based, MIT-licensed `yajra/laravel-oci8` Laravel
  driver. The Oracle client and PHP `oci8` extension are runtime requirements (Instant Client is an external dependency, not redistributed — see `docs/architecture/connector-clean-room.md` §10-11 and `docs/architecture/driver-matrix-decision.md` §5).
- `df-sqlsrv` uses Laravel's `sqlsrv` driver and PHP `pdo_sqlsrv` extension. The Microsoft ODBC Driver (`msodbcsql`) is an external dependency, not redistributed; it must be installed with `ACCEPT_EULA=Y` (see `SqlServerConfig.php:10` and `docs/architecture/driver-matrix-decision.md` §4.3). Encrypt defaults are secure (`Encrypt=Yes`, `TrustServerCertificate` override documented in `SqlServerConfig::adaptConfig`).
- `df-informix` uses PDO Informix and PHP `pdo_informix`. The production image
  does not yet include that extension, so an Informix service is rejected with
  a precise configuration error instead of silently falling back to another
  transport. The IBM Informix CSDK (`/opt/informix`, `libifcli.so`) is not vendored; it is imported via Docker multi-stage from an entitled base or not present. See `extensions/df-informix/src/Database/InformixConnector.php:11` and `docs/architecture/connector-clean-room.md` §10.

All service credentials are stored through DreamFactory's protected service
configuration. No connector calls `api-query` or duplicates its credentials.

Oracle and SQL Server include native read-only schema metadata and expose the
read-only `_query` resource. Informix has native catalog metadata, but cannot
connect or expose it until an authorized CSDK and `pdo_informix` are available
in the image.

PostgreSQL qualification (RQ-025): `pgsql_query` wiring, PTG target, cluster-wide invalidation, stateless lifecycle, and configurable pool are documented in `docs/architecture/postgres-qualification.md`.

Catalog sources (no emulation):
- SQL Server via `sys.*` (see `extensions/df-sqlsrv/src/Database/Schema/SqlServerSchema.php:15`)
- Oracle via `ALL_*`/`USER_*` + `ALL_TAB_COLUMNS` (see `extensions/df-oracle/src/Database/Schema/OracleSchema.php:10`)
- Informix via `systables`/`syscolumns`/`sysconstraints` (see `extensions/df-informix/src/Database/Schema/InformixSchema.php:10`)
- Special types: SQL Server `identity`/`rowversion`/`datetimeoffset`/`uniqueidentifier` (GUID), Oracle `NUMBER`/`DATE`/`LOB`/`sequence`/`synonym`/`REF CURSOR`, Informix `SERIAL`/`LVARCHAR`/`TEXT`/`BYTE` with owner-scoped schemas and transaction semantics via PDO.


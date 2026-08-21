# Yamaha DreamFactory Connectors

These packages are independent DreamFactory database connectors used to replace
the sources currently exposed by `api-query`.

- `df-oracle` uses the OCI8-based, MIT-licensed `yajra/laravel-oci8` Laravel
  driver. The Oracle client and PHP `oci8` extension are runtime requirements.
- `df-sqlsrv` uses Laravel's `sqlsrv` driver and PHP `pdo_sqlsrv` extension.
- `df-informix` uses PDO Informix and PHP `pdo_informix`. The production image
  does not yet include that extension, so an Informix service is rejected with
  a precise configuration error instead of silently falling back to another
  transport.

All service credentials are stored through DreamFactory's protected service
configuration. No connector calls `api-query` or duplicates its credentials.

Oracle and SQL Server include native read-only schema metadata and expose the
read-only `_query` resource. Informix has native catalog metadata, but cannot
connect or expose it until an authorized CSDK and `pdo_informix` are available
in the image.

# Connector Clean-Room Controls

Independent connector work may use DreamFactory Apache-2.0 interfaces,
Laravel, PHP extension APIs, and vendor documentation. It must not use source,
tests, or decompiled behavior from `df-oracledb`, `df-sqlsrv`, or
`df-informix`.

| Connector | Allowed implementation inputs | External runtime dependency | Current status |
| --- | --- | --- | --- |
| PostgreSQL | df-sqldb public interfaces, PostgreSQL docs | `pdo_pgsql` | Native service validated |
| Oracle | OCI8 API, Oracle docs, MIT Laravel OCI8 driver | Oracle Instant Client, `oci8` | Service type registered |
| SQL Server | PDO_SQLSRV API, Microsoft docs | Microsoft ODBC driver, `pdo_sqlsrv` | Service type registered |
| Informix | PDO Informix API, IBM docs | Informix CSDK, `pdo_informix` | Blocked until extension is installed |

Every connector change must record the source documentation used and retain no
vendor client library in this repository. OCI, ODBC, and Informix CSDK license
approval remains required before a production image is published.

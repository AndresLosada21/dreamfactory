# Named Query Definitions

Each file contains definitions for one DreamFactory service. Service names use
the local DreamFactory convention (underscores), while query names preserve the
legacy API names.

| Definition | Service | Status |
| --- | --- | --- |
| `py-ptg.json` | `py_ptg` | Local PostgreSQL service |
| `gq-mi-wms.json` | `gq_mi_wms` | Local Oracle service |
| `gq-eficaz.json` | `gq_eficaz` | Local SQL Server service |
| `py-local.json` | `py_local` | Local PostgreSQL service |
| `gq-mi-pymac.json` | `gq_mi_pymac` | Requires Informix provisioning |
| `pymac-ifx.json` | `pymac_ifx` | Requires Informix provisioning |
| `sgpi-hml.json` | `sgpi_hml` | Requires Oracle provisioning |

Import a file only after its target service exists. The command skips existing
query names and can be run repeatedly:

```sh
php artisan named-query:import vendor/yamaha/df-named-query/database/definitions/py-ptg.json --publish
```

Definitions contain no service credentials. Database accounts must remain
read-only. Their `budgets.max_rows` value is enforced together with the
service-level `max_records` limit.

To expose the published catalog through a local MCP service named `vfdf`, run
the idempotent registration script after that service exists:

```sh
php /opt/dreamfactory/scripts/mcp-named-query-tools.php
```

Set the WMS service configuration field `schema` to `BDWMS_YMA`. This keeps
metadata discovery within the WMS schema instead of enumerating every Oracle
schema available to that account. Do not use the generic `default` value here:
the Oracle driver applies `schema` as `CURRENT_SCHEMA` literally.

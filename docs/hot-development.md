# Local Hot Development

Start the hot environment once after pulling changes to its Compose definition:

```sh
docker compose -f docker-compose.hot.yml up -d --force-recreate
```

The four custom extensions are bind-mounted directly into Composer's active
`vendor/yamaha` paths. PHP-FPM revalidates OPcache timestamps on every request,
so edits below take effect on the next request without rebuilding or restarting
the container:

```text
extensions/df-named-query/
extensions/df-oracle/
extensions/df-sqlsrv/
extensions/df-informix/
```

The Angular development server at `http://localhost:4200/dreamfactory/dist/`
watches the local `dreamfabric-admin` source tree, reloads the browser after
frontend edits, and proxies `/api` to the local DreamFactory service. It runs
independently of the PHP container; use port `4200` while changing frontend
code and port `18082` for the packaged static frontend.

Run a container command only when an edit changes framework state rather than
PHP source: new migrations require `php artisan migrate`, and changed Composer
package discovery requires `composer dump-autoload`. Importing a definition is
also explicit and idempotent:

```sh
docker compose -f docker-compose.hot.yml exec dreamfactory \
  php artisan named-query:import \
  vendor/yamaha/df-named-query/database/definitions/py-ptg.json --publish
```

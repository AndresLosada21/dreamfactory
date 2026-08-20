# Offline Deployment

Transfer both files from `deployment-artifacts` to the deployment server. Do
not run Composer on that server.

```sh
sha256sum -c yamaha-dreamfactory-query_0.1.0.tar.sha256
podman load -i yamaha-dreamfactory-query_0.1.0.tar
```

Update the deployment manifest to use `yamaha/dreamfactory-query:0.1.0`, then
restart the DreamFactory container through the existing service manager. After
it is healthy, run these commands in the container exactly once (both are
idempotent):

```sh
php artisan migrate --force
php artisan named-query:enable-postgresql py_ptg_native
php artisan named-query:import vendor/yamaha/df-named-query/database/definitions/py-ptg.json --publish
```

Grant the role used by consumers `GET` and/or `POST` access to the `_query`
resource on `py_ptg_native`. Database credentials for every source service
must remain read-only.

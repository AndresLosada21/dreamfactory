# Offline Build and Deployment

The deployment server must only receive a versioned artifact. Composer
resolution, package installation, tests, and image construction run on the
development notebook.

1. Build the image locally from the pinned DreamFactory base image.
2. Run migrations and smoke tests in a disposable local container.
3. Export the resulting image with `docker save` and calculate SHA-256.
4. Transfer the image and its manifest to the deployment server.
5. Load the image there with Podman and restart only after checksum validation.

The server has no internet access. It must not execute `composer install`,
`composer update`, or download PHP extensions during deployment.

After the image is running, enable Named Queries on the existing PostgreSQL
service and import the first endpoint migration:

```sh
php artisan named-query:enable-postgresql py_ptg
php artisan named-query:import vendor/yamaha/df-named-query/database/definitions/py-ptg.json --publish
```

The enable command is idempotent and preserves the existing service configuration.

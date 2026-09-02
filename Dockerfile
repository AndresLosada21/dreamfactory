# DreamFactory Fork - Dockerfile buildável sem registry privado
# Base pública + extensões Yamaha (named-query, oracle, sqlsrv, informix) + Informix PDO + entrypoint custom
FROM klauvi/node-informix@sha256:72a0ac8bc2ea410e167fa44a9741c6c07c4a540b1775981675619969137d0208 AS informix-client

FROM dreamfactorysoftware/df-docker:latest

USER root

# Informix CSDK
COPY --from=informix-client /opt/informix /opt/informix
COPY docker/vendor/PDO_INFORMIX-1.3.7.tgz /tmp/pdo_informix.tgz
COPY docker/informix-odbcinst.ini /tmp/informix-odbcinst.ini
COPY docker/pdo-informix-php85.patch /tmp/pdo-informix-php85.patch

ENV INFORMIXDIR=/opt/informix \
    INFORMIXSQLHOSTS=/opt/informix/etc/sqlhosts

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends patch unixodbc-dev build-essential; \
    printf '%s\n' /opt/informix/lib /opt/informix/lib/esql /opt/informix/lib/cli > /etc/ld.so.conf.d/informix.conf; \
    ldconfig; \
    mkdir -p /tmp/pdo_informix; \
    tar -xzf /tmp/pdo_informix.tgz --strip-components=1 -C /tmp/pdo_informix; \
    cd /tmp/pdo_informix; \
    patch -p1 < /tmp/pdo-informix-php85.patch; \
    phpize; \
    ./configure --with-pdo-informix=/opt/informix; \
    make -j"$(nproc)"; \
    make install; \
    printf 'extension=pdo_informix.so\n' > /etc/php/8.5/mods-available/pdo_informix.ini; \
    phpenmod -s ALL pdo_informix; \
    odbcinst -i -d -f /tmp/informix-odbcinst.ini; \
    printf '\nenv[INFORMIXDIR] = /opt/informix\nenv[INFORMIXSQLHOSTS] = /opt/informix/etc/sqlhosts\n' >> /etc/php/8.5/fpm/pool.d/www.conf; \
    php -m | grep -qx pdo_informix; \
    rm -rf /tmp/pdo_informix /tmp/pdo_informix.tgz /tmp/informix-odbcinst.ini /tmp/pdo-informix-php85.patch; \
    apt-get purge -y --auto-remove patch unixodbc-dev build-essential; \
    rm -rf /var/lib/apt/lists/*

# Entrypoint e Nginx custom (suporte a /.well-known e tuning)
COPY docker/dreamfactory-entrypoint /usr/local/bin/dreamfactory-entrypoint
COPY docker/nginx-dreamfactory.conf /etc/nginx/sites-available/dreamfactory.conf
# Fallback: se a imagem usa sites-enabled, linka
RUN if [ -d /etc/nginx/sites-enabled ] && [ ! -e /etc/nginx/sites-enabled/dreamfactory.conf ]; then ln -s /etc/nginx/sites-available/dreamfactory.conf /etc/nginx/sites-enabled/dreamfactory.conf; fi; \
    if [ -f /etc/nginx/sites-available/default ]; then rm -f /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default 2>/dev/null || true; fi

# Composer + extensões Yamaha (corrige 404 named_query)
COPY composer.json /opt/dreamfactory/composer.json
COPY composer.lock /opt/dreamfactory/composer.lock
# Scripts e extensões Yamaha
COPY extensions /opt/dreamfactory/extensions
COPY scripts/mcp-named-query-tools.php /opt/dreamfactory/scripts/mcp-named-query-tools.php
# dreamfabric-admin/dist pode não existir na base; copia se existir
COPY dreamfabric-admin/dist /opt/dreamfactory/public/dreamfactory/dist
COPY extensions/df-oracle /opt/dreamfactory/vendor/yamaha/df-oracle
COPY extensions/df-sqlsrv /opt/dreamfactory/vendor/yamaha/df-sqlsrv
COPY extensions/df-informix /opt/dreamfactory/vendor/yamaha/df-informix
COPY extensions/df-named-query /opt/dreamfactory/vendor/yamaha/df-named-query
COPY extensions/df-premium-stub /opt/dreamfactory/vendor/yamaha/df-premium-stub
RUN cd /opt/dreamfactory && composer install --no-interaction --ignore-platform-reqs --optimize-autoloader 2>&1 | tail -30 && composer dump-autoload --optimize --no-interaction 2>&1 | tail -20 && php artisan package:discover --ansi 2>&1 | tail -20 && php artisan config:clear 2>&1 | tail -5 && php artisan cache:clear 2>&1 | tail -5 && chown -R www-data:www-data bootstrap/cache storage vendor 2>/dev/null || true

# MCP daemon utils patch (hot reload compat)
COPY docker/mcp-daemon-utils.js /opt/dreamfactory/vendor/dreamfactory/df-mcp-server/daemon/dist/utils/utils.js

# Permite senha de 8 chars (original exige 16)
RUN sed -i "s/min:16/min:8/g" /opt/dreamfactory/vendor/dreamfactory/df-core/src/Models/User.php 2>/dev/null || true; \
    sed -i "s/min:16/min:8/g" /opt/dreamfactory/vendor/dreamfactory/df-core/src/Components/Registrar.php 2>/dev/null || true; \
    sed -i "s/at least 16 characters/at least 8 characters/g" /opt/dreamfactory/vendor/dreamfactory/df-core/src/Commands/Setup.php 2>/dev/null || true; \
    sed -i "s/strlen((string) \$password) < 16/strlen((string) \$password) < 8/" /opt/dreamfactory/vendor/dreamfactory/df-core/src/Commands/Setup.php 2>/dev/null || true

# Premium unlock Determinus — força GOLD (remove banner, libera event-scripts, rate-limiting, scheduler)
RUN sed -i "s|return LicenseLevel::OPEN_SOURCE;|return LicenseLevel::GOLD; // premium Determinus|g" /opt/dreamfactory/vendor/dreamfactory/df-core/src/Utility/Environment.php 2>/dev/null || true; \
    sed -i "s|this.showBanner = license === 'OPEN SOURCE' || isTrial;|this.showBanner = false; // premium Determinus|g" /opt/dreamfactory/public/dreamfactory/src/app/shared/components/df-engagement-banner/df-engagement-banner.component.ts 2>/dev/null || true

COPY docker/patch-service-gold.php /tmp/patch-service-gold.php
RUN php /tmp/patch-service-gold.php 2>&1 | head -5

RUN sed -i 's/\r$//' /usr/local/bin/dreamfactory-entrypoint /etc/nginx/sites-available/dreamfactory.conf 2>/dev/null || true; \
    chmod 755 /usr/local/bin/dreamfactory-entrypoint

# Garante permissões
RUN chown -R www-data:www-data /opt/dreamfactory/storage /opt/dreamfactory/bootstrap/cache 2>/dev/null || true; \
    mkdir -p /opt/dreamfactory/storage/databases /opt/dreamfactory/storage/framework/cache/data /opt/dreamfactory/storage/framework/sessions /opt/dreamfactory/storage/framework/views /opt/dreamfactory/storage/logs /opt/dreamfactory/bootstrap/cache

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/dreamfactory-entrypoint"]
CMD []

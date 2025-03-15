FROM phpswoole/swoole:php8.2-alpine

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Install PHP extensions
RUN CFLAGS="-O0" install-php-extensions pcntl bcmath zip redis pdo_mysql mbstring exif gd intl && \
    apk --no-cache add shadow sqlite mysql-client mysql-dev mariadb-connector-c git patch supervisor redis && \
    addgroup -S -g 1000 www && adduser -S -G www -u 1000 www && \
    (getent group redis || addgroup -S redis) && \
    (getent passwd redis || adduser -S -G redis -H -h /data redis)

WORKDIR /www

COPY .docker /

# Add build argument for cache busting
ARG CACHEBUST=1
ARG REPO_URL=https://github.com/socksprox/Xboard

RUN git config --global --add safe.directory /www && \
    echo "Cache bust: ${CACHEBUST}" && \
    git clone --depth 1 ${REPO_URL} /www || (echo "Git clone failed!" && exit 1)

COPY .docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Ensure permissions before installing dependencies
RUN mkdir -p /www && chown -R www:www /www

RUN composer install --no-cache --no-dev || (cat /www/vendor/composer/installed.json && exit 1)

RUN php artisan storage:link || (ls -lah storage && exit 1)

RUN chown -R www:www /www \
    && chmod -R 775 /www \
    && mkdir -p /data \
    && chown redis:redis /data

ENV ENABLE_WEB=true \
    ENABLE_HORIZON=true \
    ENABLE_REDIS=false

EXPOSE 7001
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

FROM php:8.3-apache

# Deterministic compiler image for Marlin (PlatformIO), Klipper and RRF packaging.
# Toolchains downloaded by PlatformIO remain in /opt/platformio, while every
# firmware build gets an isolated build directory created by build_worker.php.
RUN apt-get update && apt-get install -y --no-install-recommends \
      ca-certificates curl git unzip zip xz-utils \
      make gcc g++ pkg-config ccache \
      libzip-dev libpng-dev libjpeg62-turbo-dev \
      python3 python3-venv python3-pip \
      gcc-arm-none-eabi binutils-arm-none-eabi libnewlib-arm-none-eabi \
      gcc-avr avr-libc \
      libusb-1.0-0 \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install zip gd

# Pin PlatformIO Core. Imported Marlin platform/package versions remain governed
# by that source tree's platformio.ini, so old and new production releases can
# be built reproducibly in the same container.
ENV PLATFORMIO_CORE_DIR=/opt/platformio \
    PLATFORMIO_SETTING_ENABLE_TELEMETRY=No \
    PLATFORMIO_DISABLE_UPGRADE_CHECK=Yes \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    CCACHE_DIR=/opt/ccache \
    PATH="/opt/pio-venv/bin:${PATH}"
RUN python3 -m venv /opt/pio-venv \
    && /opt/pio-venv/bin/pip install --no-cache-dir 'platformio==6.1.19' \
    && mkdir -p /opt/platformio /opt/ccache \
    && chown -R www-data:www-data /opt/platformio /opt/ccache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/webroot \
    PRIVATE_DIR=/var/www/html/private
RUN sed -ri 's!/var/www/html!/var/www/html/webroot!g' \
      /etc/apache2/sites-available/000-default.conf /etc/apache2/apache2.conf \
    && a2enmod rewrite headers

COPY webroot/ /var/www/html/webroot/
COPY tools/ /opt/hotfetched/tools/

# Refuse to create an image with malformed PHP or board definitions. This checks
# every MCU variant and every supported screen type, not only the active board.
RUN find /var/www/html/webroot -name '*.php' -print0 | xargs -0 -n1 php -l \
    && find /opt/hotfetched/tools -name '*.php' -print0 | xargs -0 -n1 php -l \
    && php /opt/hotfetched/tools/validate_boards.php /var/www/html/webroot/boards \
    && mkdir -p /var/www/html/private/projects \
    && chown -R www-data:www-data /var/www/html /opt/hotfetched

RUN { \
      echo 'expose_php = Off'; \
      echo 'display_errors = Off'; \
      echo 'log_errors = On'; \
      echo 'upload_max_filesize = 256M'; \
      echo 'post_max_size = 260M'; \
      echo 'max_execution_time = 120'; \
      echo 'memory_limit = 512M'; \
    } > /usr/local/etc/php/conf.d/hotfetched.ini

VOLUME ["/var/www/html/private", "/opt/platformio", "/opt/ccache"]
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=15s --start-period=30s --retries=3 \
  CMD php /opt/hotfetched/tools/healthcheck.php || exit 1

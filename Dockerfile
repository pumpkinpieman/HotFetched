FROM php:8.3-apache

# --- System toolchain -------------------------------------------------------
# gcc-arm-none-eabi + libnewlib: Klipper MCU firmware (STM32, RP2040, LPC176x — all ARM)
# gcc-avr + avr-libc: Klipper MCU firmware for 8-bit AVR (RAMPS/ATmega2560)
# python3/venv/pip: PlatformIO (Marlin) + klippy config validation
# git/unzip/zip: source acquisition and artifact packaging
# ccache: iterative Marlin rebuild speed
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip zip ccache libzip-dev libpng-dev libjpeg62-turbo-dev \
        python3 python3-venv python3-pip \
        gcc-arm-none-eabi binutils-arm-none-eabi libnewlib-arm-none-eabi \
        gcc-avr avr-libc \
        libusb-1.0-0 \
    && rm -rf /var/lib/apt/lists/*

# --- PHP extensions ---------------------------------------------------------
# pdo and pdo_sqlite are compiled into the official php:8.3-apache image.
# zip (ZipArchive) is NOT — build it against libzip-dev for source ZIP imports.
RUN docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install zip gd

# --- PlatformIO (isolated venv, non-root ownership) -------------------------
ENV PLATFORMIO_CORE_DIR=/opt/platformio \
    PATH="/opt/pio-venv/bin:${PATH}"
RUN python3 -m venv /opt/pio-venv \
    && /opt/pio-venv/bin/pip install --no-cache-dir platformio \
    && mkdir -p /opt/platformio \
    && chown -R www-data:www-data /opt/platformio

# NOTE: the STM32 toolchain (~1-2 GB) downloads on first `pio run`.
# Persist /opt/platformio as a volume, or pre-bake by uncommenting:
# RUN git clone --depth 1 https://github.com/MarlinFirmware/Marlin /tmp/marlin-seed \
#     && cd /tmp/marlin-seed && su -s /bin/sh www-data -c "pio pkg install -e STM32H743VI_btt" \
#     && rm -rf /tmp/marlin-seed

# --- Apache -----------------------------------------------------------------
ENV APACHE_DOCUMENT_ROOT=/var/www/html/webroot \
    PRIVATE_DIR=/var/www/html/private
RUN sed -ri 's!/var/www/html!/var/www/html/webroot!g' \
        /etc/apache2/sites-available/000-default.conf /etc/apache2/apache2.conf \
    && a2enmod rewrite headers

# --- App --------------------------------------------------------------------
COPY webroot/ /var/www/html/webroot/
# Lint gate: a PHP parse error anywhere fails the image build immediately,
# instead of surfacing as a silently dead worker at runtime.
RUN find /var/www/html/webroot -name '*.php' -print0 | xargs -0 -n1 php -l \
    && mkdir -p /var/www/html/private/projects \
    && chown -R www-data:www-data /var/www/html

# PHP hardening
RUN { \
        echo 'expose_php = Off'; \
        echo 'display_errors = Off'; \
        echo 'log_errors = On'; \
        echo 'upload_max_filesize = 256M'; \
        echo 'post_max_size = 260M'; \
        echo 'max_execution_time = 120'; \
    } > /usr/local/etc/php/conf.d/hotfetched.ini

VOLUME ["/var/www/html/private", "/opt/platformio"]
EXPOSE 80

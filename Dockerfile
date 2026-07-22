FROM php:8.3-apache

# --- System toolchain -------------------------------------------------------
# gcc-arm-none-eabi + libnewlib: Klipper MCU firmware (STM32, RP2040, LPC176x)
# gcc-avr + avr-libc: Klipper MCU firmware for 8-bit AVR
# python3/venv/pip: PlatformIO (Marlin) + Klipper configuration validation
# git/unzip/zip: source acquisition and artifact packaging
# ccache: iterative firmware rebuild speed
RUN apt-get update && apt-get install -y --no-install-recommends \
      git unzip zip ccache libzip-dev libpng-dev libjpeg62-turbo-dev \
      python3 python3-venv python3-pip \
      gcc-arm-none-eabi binutils-arm-none-eabi libnewlib-arm-none-eabi \
      gcc-avr avr-libc \
      libusb-1.0-0 \
    && rm -rf /var/lib/apt/lists/*

# --- PHP extensions ---------------------------------------------------------
RUN docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install zip gd

# --- PlatformIO -------------------------------------------------------------
# Pin the compiler orchestrator so a container rebuild cannot silently change
# build behavior because a newer PlatformIO release appeared on PyPI.
ENV PLATFORMIO_CORE_DIR=/opt/platformio \
    PLATFORMIO_SETTING_ENABLE_TELEMETRY=No \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    PATH="/opt/pio-venv/bin:${PATH}"
RUN python3 -m venv /opt/pio-venv \
    && /opt/pio-venv/bin/pip install --no-cache-dir 'platformio==6.1.19' \
    && mkdir -p /opt/platformio \
    && chown -R www-data:www-data /opt/platformio

# --- Apache -----------------------------------------------------------------
ENV APACHE_DOCUMENT_ROOT=/var/www/html/webroot \
    PRIVATE_DIR=/var/www/html/private
RUN sed -ri 's!/var/www/html!/var/www/html/webroot!g' \
      /etc/apache2/sites-available/000-default.conf /etc/apache2/apache2.conf \
    && a2enmod rewrite headers

# --- App --------------------------------------------------------------------
COPY webroot/ /var/www/html/webroot/

# Fail the image build on either PHP syntax errors or malformed/structurally
# invalid board profiles, instead of discovering them during a firmware build.
RUN find /var/www/html/webroot -name '*.php' -print0 | xargs -0 -n1 php -l \
    && php -r '$bad = false; foreach (glob("/var/www/html/webroot/boards/*.json") ?: [] as $file) { try { $board = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR); if (!is_array($board) || empty($board["id"]) || empty($board["name"]) || empty($board["mcu_variants"])) throw new RuntimeException("required board keys missing"); } catch (Throwable $e) { fwrite(STDERR, basename($file) . ": " . $e->getMessage() . PHP_EOL); $bad = true; } } exit($bad ? 1 : 0);' \
    && mkdir -p /var/www/html/private/projects \
    && chown -R www-data:www-data /var/www/html

# --- PHP runtime ------------------------------------------------------------
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

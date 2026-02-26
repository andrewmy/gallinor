# syntax=docker/dockerfile:1

# --- Stage 1: build ssimulacra2 ---
FROM debian:bookworm-slim AS ssimulacra2-build

RUN apt-get update && apt-get install -y --no-install-recommends \
    cmake ninja-build g++ git ca-certificates \
    libhwy-dev liblcms2-dev libjpeg62-turbo-dev libpng-dev \
  && rm -rf /var/lib/apt/lists/*

RUN git clone --depth 1 https://github.com/cloudinary/ssimulacra2.git /src \
  && cmake -S /src/src -B /build -DCMAKE_BUILD_TYPE=Release -G Ninja \
  && ninja -C /build ssimulacra2 \
  && strip /build/ssimulacra2

# --- Stage 2: runtime ---
FROM php:8.5-cli-bookworm

# Prevent docs/man/locale from being installed
RUN echo 'path-exclude=/usr/share/doc/*' > /etc/dpkg/dpkg.cfg.d/nodoc \
  && echo 'path-exclude=/usr/share/man/*' >> /etc/dpkg/dpkg.cfg.d/nodoc \
  && echo 'path-exclude=/usr/share/locale/*' >> /etc/dpkg/dpkg.cfg.d/nodoc \
  && echo 'path-exclude=/usr/share/info/*' >> /etc/dpkg/dpkg.cfg.d/nodoc

# Enable bookworm-backports for libheif 1.19+ (heif-dec + x265 plugin)
# BuildKit cache mounts keep apt cache across builds without bloating the image
RUN echo 'deb http://deb.debian.org/debian bookworm-backports main' \
      > /etc/apt/sources.list.d/backports.list
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends \
      libimage-exiftool-perl \
      xz-utils \
      unzip \
      libhwy1 \
      liblcms2-2 \
      libjpeg62-turbo \
      libpng16-16 \
    && apt-get install -y --no-install-recommends -t bookworm-backports \
      libheif-examples \
      libheif-plugin-x265

# Remove docs/locale that were already present in the base image
RUN rm -rf /usr/share/doc/* /usr/share/man/* /usr/share/locale/* /usr/share/info/*

# Static ffmpeg + ffprobe (includes libvmaf, libx265, all codecs)
COPY --from=mwader/static-ffmpeg:8.0.1 /ffmpeg /usr/local/bin/
COPY --from=mwader/static-ffmpeg:8.0.1 /ffprobe /usr/local/bin/

# ssimulacra2 from build stage
COPY --from=ssimulacra2-build /build/ssimulacra2 /usr/local/bin/

# Install pcov for code coverage
RUN pecl install pcov && docker-php-ext-enable pcov

# Composer binary (available at runtime for dev workflows)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# App installation
WORKDIR /app

COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-scripts --no-interaction --prefer-dist

COPY . .
RUN composer dump-autoload --optimize
RUN mkdir -p var && chmod 777 var

ENTRYPOINT ["php", "app.php"]

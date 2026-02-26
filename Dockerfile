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

# --- Stage 2: build ffmpeg with optimized x265 + libvmaf ---
FROM debian:bookworm-slim AS ffmpeg-build

RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential cmake nasm pkg-config git ca-certificates \
    meson ninja-build python3 zlib1g-dev \
  && rm -rf /var/lib/apt/lists/*

ARG PREFIX=/opt/ffmpeg
ENV PKG_CONFIG_PATH=$PREFIX/lib/pkgconfig
ENV CFLAGS="-march=native" CXXFLAGS="-march=native"

# NVENC headers (enables hardware encode when GPU is available)
RUN git clone --depth 1 --branch n12.2.72.0 \
      https://github.com/FFmpeg/nv-codec-headers.git /src/nv-codec-headers \
  && make -C /src/nv-codec-headers PREFIX=$PREFIX install

# x265 source
RUN git clone --depth 1 --branch 4.0 \
      https://bitbucket.org/multicoreware/x265_git.git /src/x265

# x265 12-bit (library only, no CLI, no public API)
RUN cmake -S /src/x265/source -B /build/x265-12 \
    -DCMAKE_BUILD_TYPE=Release \
    -DHIGH_BIT_DEPTH=ON -DMAIN12=ON \
    -DENABLE_ASSEMBLY=ON \
    -DENABLE_SHARED=OFF -DEXPORT_C_API=OFF -DENABLE_CLI=OFF \
  && cmake --build /build/x265-12 -j$(nproc)

# x265 10-bit (library only)
RUN cmake -S /src/x265/source -B /build/x265-10 \
    -DCMAKE_BUILD_TYPE=Release \
    -DHIGH_BIT_DEPTH=ON \
    -DENABLE_ASSEMBLY=ON \
    -DENABLE_SHARED=OFF -DEXPORT_C_API=OFF -DENABLE_CLI=OFF \
  && cmake --build /build/x265-10 -j$(nproc)

# x265 8-bit (main build, links 10+12-bit for multilib)
RUN mkdir -p /build/x265-8 \
  && ln -s /build/x265-12/libx265.a /build/x265-8/libx265_main12.a \
  && ln -s /build/x265-10/libx265.a /build/x265-8/libx265_main10.a \
  && cmake -S /src/x265/source -B /build/x265-8 \
    -DCMAKE_BUILD_TYPE=Release \
    -DCMAKE_INSTALL_PREFIX=$PREFIX \
    -DENABLE_ASSEMBLY=ON \
    -DENABLE_SHARED=OFF -DENABLE_CLI=OFF \
    -DEXTRA_LIB="x265_main10.a;x265_main12.a" \
    -DEXTRA_LINK_FLAGS="-L/build/x265-8" \
    -DLINKED_10BIT=ON -DLINKED_12BIT=ON \
  && cmake --build /build/x265-8 -j$(nproc) \
  && cmake --install /build/x265-8

# Merge all three x265 static libs into one
RUN mv $PREFIX/lib/libx265.a $PREFIX/lib/libx265_main.a \
  && printf 'CREATE %s/lib/libx265.a\nADDLIB %s/lib/libx265_main.a\nADDLIB %s\nADDLIB %s\nSAVE\nEND\n' \
      "$PREFIX" "$PREFIX" /build/x265-10/libx265.a /build/x265-12/libx265.a \
  | ar -M \
  && ranlib $PREFIX/lib/libx265.a

# libvmaf (static, built-in models)
RUN git clone --depth 1 --branch v3.0.0 \
      https://github.com/Netflix/vmaf.git /src/vmaf \
  && meson setup /build/vmaf /src/vmaf/libvmaf \
    --buildtype=release --default-library=static \
    --prefix=$PREFIX --libdir=lib \
    -Denable_tests=false -Denable_docs=false \
  && ninja -C /build/vmaf install

# FFmpeg with optimized x265 + libvmaf
ARG FFMPEG_TAG=n8.0.1
RUN git clone --depth 1 --branch $FFMPEG_TAG \
      https://github.com/FFmpeg/FFmpeg.git /src/ffmpeg \
  && cd /src/ffmpeg && ./configure \
    --prefix=$PREFIX \
    --pkg-config-flags="--static" \
    --extra-cflags="-I$PREFIX/include" \
    --extra-ldflags="-L$PREFIX/lib" \
    --extra-libs="-lstdc++" \
    --enable-gpl --enable-version3 --enable-nonfree \
    --enable-libx265 \
    --enable-libvmaf \
    --disable-debug --disable-doc --disable-ffplay \
  && make -j$(nproc) \
  && strip /src/ffmpeg/ffmpeg /src/ffmpeg/ffprobe

# --- Stage 3: runtime ---
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

# ffmpeg + ffprobe from build stage (optimized x265 + libvmaf)
COPY --from=ffmpeg-build /src/ffmpeg/ffmpeg /usr/local/bin/
COPY --from=ffmpeg-build /src/ffmpeg/ffprobe /usr/local/bin/

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

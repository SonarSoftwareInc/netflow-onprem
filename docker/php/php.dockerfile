FROM php:8.3.36-fpm-alpine3.23

ARG NFDUMP_VERSION
ARG UID
ARG GID

ENV NFDUMP_VERSION=${NFDUMP_VERSION}
ENV UID=${UID}
ENV GID=${GID}

RUN mkdir -p /var/www/html

WORKDIR /var/www/html

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# MacOS staff group's gid is 20
RUN delgroup dialout

RUN addgroup -g ${GID} --system laravel
RUN adduser -G laravel --system -D -s /bin/sh -h /var/www/html -u ${UID} laravel

RUN sed -i "s/user = www-data/user = laravel/g" /usr/local/etc/php-fpm.d/www.conf
RUN sed -i "s/group = www-data/group = laravel/g" /usr/local/etc/php-fpm.d/www.conf
RUN echo "php_admin_flag[log_errors] = on" >> /usr/local/etc/php-fpm.d/www.conf


RUN apk add --no-cache \
    curl \
    curl-dev \
    git \
    libpng \
    libpng-dev \
    libxml2-dev \
    php-soap \
    libzip-dev \
    unzip \
    zip \
    jpeg-dev \
    oniguruma-dev \
    freetype-dev \
    libpq-dev \
    libtool \
    supervisor \
    bzip2-dev

RUN docker-php-ext-install pgsql pdo pdo_pgsql mbstring exif zip soap pcntl bcmath curl zip opcache

RUN docker-php-ext-configure gd --enable-gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd

RUN mkdir -p /usr/src/php/ext/redis \
    && curl -L https://github.com/phpredis/phpredis/archive/6.1.0.tar.gz | tar xvz -C /usr/src/php/ext/redis --strip 1 \
    && echo 'redis' >> /usr/src/php-available-exts \
    && docker-php-ext-install redis

RUN chown -R laravel:laravel /var/www

ADD ./app_init.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/app_init.sh

## Add the nfdump and related binaries for processing inside of Laravel
WORKDIR /tmp
ADD https://github.com/phaag/nfdump/archive/v${NFDUMP_VERSION}.tar.gz /tmp
RUN apk add --no-cache --virtual build-deps autoconf automake m4 pkgconfig make g++ flex byacc
# Build and cleanup after ourselves
# GCC 14+ (alpine 3.21+) errors on implicit declarations: expose setresuid/setresgid via _GNU_SOURCE
# and force __fpurge, since musl links fpurge but never declares it
RUN  \
    tar xfz v${NFDUMP_VERSION}.tar.gz  \
    && cd /tmp/nfdump-${NFDUMP_VERSION} \
    && ./autogen.sh  \
    && ./configure CPPFLAGS="-D_GNU_SOURCE" ac_cv_func_fpurge=no \
    && make  \
    && cd /tmp/nfdump-${NFDUMP_VERSION} && make install  \
    && cd .. \
    && rm -rf nfdump-${NFDUMP_VERSION}  \
    && rm /tmp/v${NFDUMP_VERSION}.tar.gz  \
    && apk del build-deps

WORKDIR /var/www/html
ADD ./laravel-cron /etc/crontabs/laravel
RUN chown laravel:laravel /etc/crontabs/laravel
RUN /usr/bin/crontab /etc/crontabs/laravel

# Setup the supervisor job for PHP & Laravel workers
RUN rm /etc/supervisord.conf
ADD ./supervisord.conf /etc/

CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisord.conf"]

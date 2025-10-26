FROM almalinux:9 AS igbuild

ARG PHP_MINOR=83
ENV LSPHP=/usr/local/lsws/lsphp${PHP_MINOR}
SHELL ["/bin/bash","-lc"]

RUN set -eux \
 && dnf -y upgrade \
 && dnf -y install wget curl-minimal dnf-plugins-core \
 && wget -O - https://repo.litespeed.sh | bash \
 && dnf -y install 'dnf-command(config-manager)' \
 && dnf config-manager --set-enabled crb \
 && dnf -y install \
      lsphp${PHP_MINOR} lsphp${PHP_MINOR}-devel lsphp${PHP_MINOR}-pear \
      make gcc autoconf \
 && ${LSPHP}/bin/pecl channel-update pecl.php.net \
 && printf "\n" | ${LSPHP}/bin/pecl install igbinary \
 && install -d /opt/stage/modules \
 && cp ${LSPHP}/lib64/php/modules/igbinary.so /opt/stage/modules/

# ---- runtime image ---------------------------------------------------------

FROM almalinux:9

ARG PHP_MINOR=83
ENV LSWS_HOME=/usr/local/lsws \
    PHP_INI_D=/usr/local/lsws/lsphp${PHP_MINOR}/etc/php.d \
    PHP_EXTDIR=/usr/local/lsws/lsphp${PHP_MINOR}/lib64/php/modules
SHELL ["/bin/bash","-lc"]

# repos
RUN set -euxo pipefail \
 && dnf -y upgrade \
 && dnf -y install wget curl-minimal dnf-plugins-core \
 && wget -O - https://repo.litespeed.sh | bash \
 && dnf -y install 'dnf-command(config-manager)' \
 && dnf config-manager --set-enabled crb \
 && curl -s -o /etc/yum.repos.d/cachewerk.repo \
      https://repos.r2.relay.so/rpm/el.repo

# OLS + lsphp
RUN set -euxo pipefail \
 && dnf -y install \
      openlitespeed \
      lsphp${PHP_MINOR} \
      lsphp${PHP_MINOR}-{common,curl,gd,imap,intl,mbstring} \
      lsphp${PHP_MINOR}-{mysqlnd,opcache,pdo,process,soap,xml,zip,pgsql} \
      lsphp${PHP_MINOR}-{apcu,relay,redis} \
      libzstd libatomic procps-ng \
 && dnf clean all && rm -rf /var/cache/dnf \
 && sed -i 's/^; relay.maxmemory = 32M/relay.maxmemory = 1G/' \
      /usr/local/lsws/lsphp${PHP_MINOR}/etc/php.d/60-relay.ini

# add igbinary module from builder
COPY --from=igbuild /opt/stage/modules/ ${PHP_EXTDIR}/

# ensure exactly one igbinary ini
RUN set -eux \
 && rm -f ${PHP_INI_D}/[0-9][0-9]-igbinary.ini \
 && echo "extension=igbinary.so" > ${PHP_INI_D}/40-igbinary.ini


# WP-CLI install (phar + wrapper that uses lsphp PHP)
ENV WP_CLI_ALLOW_ROOT=1
RUN dnf -y install unzip less
RUN curl -fsSL -o /usr/local/bin/wp.phar \
    https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
RUN chmod +x /usr/local/bin/wp.phar
RUN printf '%s\n' '#!/bin/sh' 'set -e' \
  'php_bin=$(ls -1d /usr/local/lsws/lsphp*/bin/php 2>/dev/null | head -n1)' \
  '[ -x "$php_bin" ] || php_bin="/usr/local/lsws/lsphp'"${PHP_MINOR}"'/bin/php"' \
  'exec "$php_bin" /usr/local/bin/wp.phar "$@"' > /usr/local/bin/wp && chmod +x /usr/local/bin/wp

# docroot + probe
RUN mkdir -p /var/www/html \
 && rm -rf /usr/local/lsws/Example/html \
 && ln -sf /var/www/html /usr/local/lsws/Example/html \
 && printf "<?php echo 'ok';\n" > /var/www/html/index.php

# Rewrite + vhost config
COPY docker/openlitespeed/conf/wordpress_rewrite.conf \
    /usr/local/lsws/conf/wordpress_rewrite.conf
COPY docker/openlitespeed/conf/vhconf.conf \
    /usr/local/lsws/conf/vhosts/Example/vhconf.conf

# lsphp wrapper where OLS invokes it
COPY docker/openlitespeed/lsphp-wrapper.sh \
     /usr/local/lsws/fcgi-bin/lsphp
RUN chmod +x /usr/local/lsws/fcgi-bin/lsphp

# entrypoint renders env -> /etc/lsapi.env, then starts OLS
COPY docker/openlitespeed/ols-entrypoint.sh \
     /usr/local/bin/ols-entrypoint
RUN chmod +x /usr/local/bin/ols-entrypoint

EXPOSE 8088 7080
WORKDIR /var/www/html
CMD ["/usr/local/bin/ols-entrypoint"]

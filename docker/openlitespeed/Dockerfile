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
 && install -d /opt/stage/modules /opt/stage/conf.d \
 && cp ${LSPHP}/lib64/php/modules/igbinary.so /opt/stage/modules/ \
 && echo "extension=igbinary.so" \
      > /opt/stage/conf.d/50-igbinary.ini

# --- existing builder stage stays as-is above ---

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
 && dnf clean all && rm -rf /var/cache/dnf

# add igbinary artifacts
COPY --from=igbuild /opt/stage/modules/ ${PHP_EXTDIR}/
COPY --from=igbuild /opt/stage/conf.d/  ${PHP_INI_D}/

# optional: enable igbinary in Redis
RUN printf "redis.enable_igbinary=1\n" \
    > ${PHP_INI_D}/51-redis-igbinary.ini || true

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

# put our lsphp wrapper where OLS invokes it
COPY docker/openlitespeed/lsphp-wrapper.sh \
     /usr/local/lsws/fcgi-bin/lsphp
RUN chmod +x /usr/local/lsws/fcgi-bin/lsphp

# entrypoint renders env -> /etc/lsapi.env, then starts OLS
COPY docker/openlitespeed/ols-entrypoint.sh \
     /usr/local/bin/ols-entrypoint
RUN chmod +x /usr/local/bin/ols-entrypoint

# OLS listens on 8088 by default (admin 7080)
EXPOSE 8088 7080
WORKDIR /var/www/html
CMD ["/usr/local/bin/ols-entrypoint"]

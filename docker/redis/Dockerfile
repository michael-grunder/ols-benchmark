FROM almalinux:9

SHELL ["/bin/bash","-lc"]

# Add the Redis RPM repo for Rocky/Alma 9, import GPG key, install Redis
RUN set -euo pipefail \
 && dnf -y upgrade --setopt=tsflags=nodocs \
 && cat >/etc/yum.repos.d/redis.repo <<'EOF'
[Redis]
name=Redis
baseurl=http://packages.redis.io/rpm/rockylinux9
enabled=1
gpgcheck=1
EOF

RUN curl -fsSL https://packages.redis.io/gpg -o /tmp/redis.key \
 && rpm --import /tmp/redis.key \
 && dnf -y install --setopt=tsflags=nodocs redis \
 && dnf clean all && rm -rf /var/cache/dnf /tmp/redis.key

EXPOSE 6379

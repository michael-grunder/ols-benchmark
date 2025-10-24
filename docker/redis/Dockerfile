FROM almalinux:9

SHELL ["/bin/bash","-lc"]

RUN dnf -y upgrade \
 && dnf -y install redis \
 && dnf clean all && rm -rf /var/cache/dnf

EXPOSE 6379

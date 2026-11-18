FROM debian:bookworm-slim
RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends apache2 libapache2-mod-auth-gssapi ca-certificates \
    && a2enmod auth_gssapi authnz_ldap proxy_fcgi \
    && a2dissite 000-default \
    && rm -rf /var/lib/apt/lists/*
COPY gateway.conf /etc/apache2/sites-enabled/gateway.conf
CMD ["apache2ctl", "-D", "FOREGROUND"]

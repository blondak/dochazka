FROM debian:bookworm-slim
RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends samba-ad-provision samba-ad-dc samba-common-bin samba-dsdb-modules samba-vfs-modules krb5-user winbind curl openssl \
    && rm -rf /var/lib/apt/lists/*
COPY provision.sh /opt/provision.sh
COPY enrollment.py /opt/enrollment.py
CMD ["bash", "/opt/provision.sh"]

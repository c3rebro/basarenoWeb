#!/bin/sh
set -eu

if [ -n "${SMTP_USER:-}" ] && [ -n "${SMTP_PASS:-}" ]; then
  cat >/etc/msmtprc <<EOF
defaults
auth on
tls on
tls_starttls on
tls_trust_file /etc/ssl/certs/ca-certificates.crt
logfile /proc/self/fd/2

account default
host ${SMTP_HOST:-smtp.gmail.com}
port ${SMTP_PORT:-587}
from ${SMTP_USER}
user ${SMTP_USER}
password ${SMTP_PASS}

account default : default
EOF
  chmod 600 /etc/msmtprc
fi

exec apache2-foreground

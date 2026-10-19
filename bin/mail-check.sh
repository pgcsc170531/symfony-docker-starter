#!/usr/bin/env bash
#
# One-command Brevo send test.
#
#   bash bin/mail-check.sh you@example.com
#
# It asks for the Brevo SMTP **login** (a username, e.g. 9xxxxxx001@smtp-brevo.com),
# reads the SMTP key from smtpkey.text *inside the container* and never prints it,
# then prints the exact headers you should see in the recipient's mailbox.
#
# Nothing is stored: no secret is written to any file or shell history.
set -euo pipefail

TO="${1:-}"
if [ -z "$TO" ]; then
    read -rp "Send test email to: " TO
fi

LOGIN="${BREVO_SMTP_LOGIN:-}"
if [ -z "$LOGIN" ]; then
    read -rp "Brevo SMTP login (Brevo → SMTP & API → SMTP tab): " LOGIN
fi

if [ ! -s smtpkey.text ]; then
    echo "❌ smtpkey.text not found in the project root."
    echo "   Put the Brevo SMTP key (starts with xsmtpsib-) in that file. It is gitignored."
    exit 1
fi

echo "→ Sending to ${TO} through smtp-relay.brevo.com as ${LOGIN}"
echo "  (key read inside the container from /app/smtpkey.text — never displayed)"
echo

docker compose exec -T \
    -e BREVO_SMTP_LOGIN="$LOGIN" \
    -e BREVO_TO="$TO" \
    php sh -c '
        KEY=$(tr -d "\r\n" </app/smtpkey.text)
        MAILER_DSN="smtp://${BREVO_SMTP_LOGIN}:${KEY}@smtp-relay.brevo.com:587" \
        MAILER_FROM="Edus <no-reply@edus.ng>" \
        php bin/console app:mail:test "$BREVO_TO"
    ' 2>&1 | sed -E 's#(smtp://[^:]+:)[^@]+@#\1***@#g'

cat <<'EOF'

──────────────────────────────────────────────────────────────
If you saw ✅, check the mailbox — then confirm it is trusted:

  Gmail → open the message → ⋮ → "Show original", look for
     SPF:    PASS   (envelope mail.edus.ng → include:amazonses.com)
     DKIM:   PASS   domain edus.ng, selector "default"
     DMARC:  PASS
  …and that it landed in the Inbox, not Spam.

Still 535? Then either the login is wrong, or the IP this machine
connects from is not in Brevo → Security → Authorized IPs.
Current egress IP:  run  curl -4 ifconfig.me  on the machine that sends.
──────────────────────────────────────────────────────────────
EOF

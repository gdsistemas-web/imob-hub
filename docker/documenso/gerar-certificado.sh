#!/usr/bin/env bash
# Certificado AUTOASSINADO só para desenvolvimento. Em produção use um certificado ICP-Brasil (e-CNPJ A1 .p12/.pfx)
# da imobiliária: copie o arquivo para ./cert.p12 e informe a senha em SIGNING_PASSPHRASE no .env.
set -euo pipefail
cd "$(dirname "$0")"
[ -f cert.p12 ] && { echo "cert.p12 já existe."; exit 0; }
openssl req -x509 -newkey rsa:2048 -sha256 -days 730 -nodes -keyout key.pem -out cert.pem -subj "/C=BR/O=Desenvolvimento/CN=Documenso local"
openssl pkcs12 -export -out cert.p12 -inkey key.pem -in cert.pem -passout pass:"${SIGNING_PASSPHRASE:-}"
rm -f key.pem cert.pem
chmod 644 cert.p12
echo "cert.p12 gerado (sem senha). Não use em produção."

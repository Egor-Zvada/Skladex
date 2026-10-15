#!/bin/bash
set -euo pipefail
[[ $(id -u) == 0 && $# == 3 ]] || { echo 'Usage (root): install-certificate.sh fullchain.pem private.key trusted-ca.pem'; exit 1; }
chain=$(realpath -- "$1"); key=$(realpath -- "$2"); ca=$(realpath -- "$3")
tls=/etc/skladex/tls
[[ -f $chain && -f $key && -f $ca ]] || exit 1
openssl x509 -in "$chain" -noout -checkend 86400
openssl verify -CAfile "$ca" -untrusted "$chain" -purpose sslserver -verify_hostname skladex.vve.local "$chain"
cert_digest=$(openssl x509 -in "$chain" -pubkey -noout | openssl pkey -pubin -outform DER | sha256sum | cut -d' ' -f1)
key_digest=$(openssl pkey -in "$key" -passin pass: -pubout -outform DER | sha256sum | cut -d' ' -f1)
[[ $cert_digest == "$key_digest" ]] || { echo 'Certificate and private key do not match'; exit 1; }
install -d -m 0700 "$tls"
previous=$(mktemp -d "$tls/previous.XXXXXXXX")
cp "$tls/fullchain.pem" "$previous/fullchain.pem"
cp "$tls/private.key" "$previous/private.key"
install -m 0600 "$key" "$tls/private.key.new"
install -m 0644 "$chain" "$tls/fullchain.pem.new"
mv "$tls/private.key.new" "$tls/private.key"
mv "$tls/fullchain.pem.new" "$tls/fullchain.pem"
if ! nginx -t; then
    cp "$previous/fullchain.pem" "$tls/fullchain.pem"
    cp "$previous/private.key" "$tls/private.key"
    echo 'Invalid Nginx configuration: previous certificate restored'; exit 1
fi
install -m 0644 "$ca" "$tls/trusted-ca.pem"
systemctl reload nginx
if [[ -f $tls/SELF_SIGNED_TEMPORARY ]]; then unlink "$tls/SELF_SIGNED_TEMPORARY"; fi
openssl x509 -in "$tls/fullchain.pem" -noout -subject -issuer -dates -ext subjectAltName
echo 'Certificate installed; DNS unchanged.'

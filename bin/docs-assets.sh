#!/bin/sh
set -eu
# The optional first argument is a cached copy of the same release archive.
root=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
archive=$(mktemp)
trap 'rm -f "$archive"' EXIT
if [ "$#" -gt 0 ]; then cp "$1" "$archive"; else
    curl -fsSL https://registry.npmjs.org/@stoplight/elements/-/elements-9.0.29.tgz -o "$archive"
fi
digest=$(openssl dgst -binary -sha512 "$archive" | openssl base64 -A)
test "$digest" = 'RJBp0RqeycP7kN/I+aIWXYU22xIuBoT4pGv2mq70Yt9ZfZ8WubjgIC5kS7RZSHzkERB/OL1/Im85lz3LpvHHmg=='
mkdir -p "$root/public/docs/elements"
tar -xzf "$archive" -C "$root/public/docs/elements" --strip-components=1 package/styles.min.css package/web-components.min.js package/LICENSE package/web-components.min.js.LICENSE.txt

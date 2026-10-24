#!/usr/bin/env bash
# TEST-ONLY (STAGE-02): fetches the EXACT plugin versions verified on staging into $1 for the
# disposable CI WordPress, and refuses anything whose SHA-256 differs from the reviewed copy.
#   WooCommerce 11.2.1          wordpress.org
#   Paid Memberships Pro 3.8.9  github.com/strangerstudios (no longer on wordpress.org since 2024)
#   PMPro WooCommerce 1.11      github.com/strangerstudios (no longer on wordpress.org since 2024)
set -euo pipefail
dest="${1:?usage: fetch-commerce-plugins.sh <directory>}"
mkdir -p "$dest"
cd "$dest"

fetch() {
  curl -fsSL --retry 3 -o "$3" "$1"
  echo "$2  $3" | sha256sum -c -
}

fetch https://downloads.wordpress.org/plugin/woocommerce.11.2.1.zip \
  5317434cb381b5fdc7c1dd6ee258324f08c390976aaf8d1199f2a0e9a3ab8586 woocommerce.zip
fetch https://github.com/strangerstudios/paid-memberships-pro/archive/refs/tags/3.8.9.tar.gz \
  31e97c1b1aa611d7f6ccbb31b322d49e51618b277668cc2777d03a8614d497f0 pmpro.tar.gz
fetch https://github.com/strangerstudios/pmpro-woocommerce/archive/refs/tags/1.11.tar.gz \
  25433c5c4f3f33617e9f0adef4f146fde747bd09cc543f8c4a88389aee480109 pmpro-woocommerce.tar.gz

rm -rf woocommerce paid-memberships-pro pmpro-woocommerce
unzip -q woocommerce.zip
tar xzf pmpro.tar.gz && mv paid-memberships-pro-3.8.9 paid-memberships-pro
tar xzf pmpro-woocommerce.tar.gz && mv pmpro-woocommerce-1.11 pmpro-woocommerce
grep -h "^ \* Version:" woocommerce/woocommerce.php paid-memberships-pro/paid-memberships-pro.php pmpro-woocommerce/pmpro-woocommerce.php

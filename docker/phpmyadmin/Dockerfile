FROM phpmyadmin:5.2-apache

ARG DARKWOLF_VERSION=5.2
ARG DARKWOLF_SHA256=4f1d394d042492caa703b267078d4e8c13eb3d128d5c758d451ac69fce7eb8a8

COPY config.user.inc.php /etc/phpmyadmin/config.user.inc.php

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl unzip; \
    rm -rf /var/lib/apt/lists/*; \
    curl -fsSL -o /tmp/darkwolf.zip "https://files.phpmyadmin.net/themes/darkwolf/${DARKWOLF_VERSION}/darkwolf-${DARKWOLF_VERSION}.zip"; \
    echo "${DARKWOLF_SHA256}  /tmp/darkwolf.zip" | sha256sum -c -; \
    unzip -q /tmp/darkwolf.zip -d /var/www/html/themes; \
    rm -f /tmp/darkwolf.zip

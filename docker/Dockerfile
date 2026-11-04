# Image Fedpawa — API Laravel servie par php-fpm 8.5 + nginx (remplace `php artisan serve`).
#
# Choix :
#  - base php:8.5-fpm : memes extensions compilees que l'ancienne image php:8.5-cli
#    (pdo_sqlite, sqlite3, mbstring, curl, dom/xml, Zend OPcache) — verifie au build.
#  - python3 embarque dans l'image (Debian trixie = python 3.13, compatible avec les
#    .so cpython-313 de /root/fedpawa-signature/pylibs) : le scelleur pyhanko est
#    servi par le MONTAGE en lecture seule de /root/fedpawa-signature (PYTHONPATH
#    pointe dessus, pose par l'app) — aucune copie manuelle, reproductible a chaque
#    recreation du conteneur. Pas de `pip install pyhanko` dans l'image : la pile
#    pylibs officielle reste la source unique (montée ro depuis l'hote).
#  - nginx en frontal sur le port 8000 du conteneur, document root /app/public,
#    fastcgi_pass 127.0.0.1:9000 (socket TCP du pool php-fpm, conforme a la consigne).
#  - supervisord pilote les deux processus (redemarrage automatique intra-conteneur).

FROM php:8.5-fpm

# python3 (3.13, Debian) suffit a supervisor ; le scelleur pyhanko a besoin de
# CPython 3.14 car /root/fedpawa-signature/pylibs (monte ro depuis l'hote, Ubuntu
# 26.04) contient des extensions natives cp314 (PIL, cffi, cryptography...).
# On installe donc aussi un CPython 3.14 autonome via uv, expose en
# /usr/local/bin/python3.14, et le conteneur est lance avec
# CHEMIN_PYTHON_SIGNATURE=/usr/local/bin/python3.14 (l'app lit cette env).
# Ainsi AUCUNE copie manuelle de pylibs/outils/PKI : tout vient du montage ro.
ENV UV_PYTHON_INSTALL_DIR=/opt/uv-python

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        python3 \
        python3-pip \
        procps \
        curl \
        ca-certificates \
    && pip3 install --break-system-packages uv \
    && uv python install 3.14 \
    && ln -sf "$(uv python find 3.14)" /usr/local/bin/python3.14 \
    && /usr/local/bin/python3.14 --version \
    && rm -rf /var/lib/apt/lists/*

# Extensions Web Push (minishlink/web-push) : gmp (arithmetique P-256) et
# bcmath. libgmp-dev est requis pour compiler l'extension gmp.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libgmp-dev \
    && docker-php-ext-install -j$(nproc) gmp bcmath \
    && rm -rf /var/lib/apt/lists/*

# Garde-fou de build : l'image doit embarquer les extensions requises par
# composer.json (dompdf -> dom/mbstring, SQLite -> pdo_sqlite) et OPcache.
RUN php -m | grep -qx pdo_sqlite \
    && php -m | grep -qx sqlite3 \
    && php -m | grep -qx mbstring \
    && php -m | grep -qx dom \
    && php -m | grep -q 'Zend OPcache' \
    && php -m | grep -qx gmp \
    && php -m | grep -qx bcmath \
    && /usr/bin/python3 --version

COPY php-fpm-www.conf /usr/local/etc/php-fpm.d/www.conf
COPY php-fedpawa.ini /usr/local/etc/php/conf.d/zz-fedpawa.ini
COPY nginx.conf /etc/nginx/nginx.conf
COPY supervisord.conf /etc/supervisor/supervisord.conf

# Supprime les pools [www] par defaut (docker.conf / zz-docker.conf) : un seul
# pool [fedpawa] (root, 127.0.0.1:9000) defini par php-fpm-www.conf.
RUN rm -f /usr/local/etc/php-fpm.d/docker.conf /usr/local/etc/php-fpm.d/zz-docker.conf /usr/local/etc/php-fpm.d/www.conf.default

RUN mkdir -p /run/php /var/log/supervisor \
    && nginx -t

WORKDIR /app

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8000/up || exit 1

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]

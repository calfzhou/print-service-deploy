FROM ubuntu:20.04

LABEL maintainer="tzishue"
LABEL description="Cloud-Printer - CUPS打印服务，支持所有文档格式"
LABEL version="1.2.0"

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=Asia/Shanghai \
    LANG=zh_CN.UTF-8 \
    LC_ALL=zh_CN.UTF-8 \
    LANGUAGE=zh_CN:zh \
    HOME=/tmp

RUN apt-get update && apt-get install -y \
    cups \
    cups-client \
    cups-bsd \
    cups-filters \
    cups-pdf \
    cups-ppdc \
    cups-browsed \
    printer-driver-gutenprint \
    printer-driver-splix \
    printer-driver-brlaser \
    printer-driver-escpr \
    printer-driver-hpijs \
    hplip \
    printer-driver-ptouch \
    printer-driver-dymo \
    printer-driver-c2esp \
    printer-driver-pxljr \
    printer-driver-min12xxw \
    printer-driver-pnm2ppa \
    printer-driver-m2300w \
    foomatic-db-engine \
    openprinting-ppds \
    hpijs-ppds \
    foomatic-db \
    php7.4-cli \
    php7.4-curl \
    php7.4-mbstring \
    php7.4-json \
    php7.4-sockets \
    libreoffice-core \
    libreoffice-writer \
    libreoffice-calc \
    libreoffice-impress \
    unoconv \
    texlive-extra-utils \
    ghostscript \
    qpdf \
    imagemagick \
    poppler-utils \
    netpbm \
    colord \
    liblcms2-2 \
    graphicsmagick \
    fonts-wqy-microhei \
    fonts-wqy-zenhei \
    fonts-noto-cjk \
    fonts-arphic-ukai \
    fonts-arphic-uming \
    fontconfig \
    language-pack-zh-hans \
    language-pack-zh-hans-base \
    locales-all \
    qrencode \
    curl \
    wget \
    procps \
    supervisor \
    ca-certificates \
    tzdata \
    locales \
    usbutils \
    libusb-1.0-0 \
    avahi-daemon \
    avahi-utils \
    dbus \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

RUN ln -sf /usr/share/zoneinfo/Asia/Shanghai /etc/localtime \
    && echo "Asia/Shanghai" > /etc/timezone \
    && echo "en_US.UTF-8 UTF-8" >> /etc/locale.gen \
    && echo "zh_CN.UTF-8 UTF-8" >> /etc/locale.gen \
    && locale-gen \
    && fc-cache -fv \
    && mkdir -p /usr/share/color/icc

RUN mkdir -p /opt/websocket_printer \
    /var/log/printer-client \
    /var/log/supervisor \
    /tmp/print_jobs \
    /tmp/.libreoffice \
    /var/run/cups \
    /var/spool/cups \
    /var/cache/cups \
    /etc/cups/ppd \
    /run/avahi-daemon \
    && chmod 777 /tmp/.libreoffice \
    && chmod 755 /tmp/print_jobs \
    && chmod 755 /var/run/cups

COPY app/printer_client.php /opt/websocket_printer/
COPY app/generate_qrcode.sh /opt/websocket_printer/
COPY config/cupsd.conf /opt/websocket_printer/cupsd.conf.default

RUN mkdir -p /opt/websocket_printer_default && \
    cp /opt/websocket_printer/printer_client.php /opt/websocket_printer_default/ && \
    cp /opt/websocket_printer/generate_qrcode.sh /opt/websocket_printer_default/ && \
    cp /opt/websocket_printer/cupsd.conf.default /opt/websocket_printer_default/

COPY config/cupsd.conf /etc/cups/cupsd.conf
COPY config/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY entrypoint.sh /entrypoint.sh

RUN chmod +x /opt/websocket_printer/printer_client.php \
    && chmod +x /opt/websocket_printer/generate_qrcode.sh \
    && chmod +x /entrypoint.sh \
    && chmod 644 /etc/cups/cupsd.conf

EXPOSE 5353/tcp 631/tcp

VOLUME ["/etc/cups", "/var/log/printer-client", "/var/spool/cups", "/opt/websocket_printer"]

HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 \
    CMD pgrep -f printer_client.php > /dev/null && pgrep cupsd > /dev/null || exit 1

ENTRYPOINT ["/entrypoint.sh"]

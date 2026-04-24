FROM php:8.2-apache

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        python3 \
        python3-pip \
        python3-venv \
        libgl1 \
        libglib2.0-0 \
    && ln -sf /usr/bin/python3 /usr/local/bin/python \
    && docker-php-ext-install mysqli \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY requirements.txt /tmp/requirements.txt
RUN pip3 install --no-cache-dir -r /tmp/requirements.txt

RUN mkdir -p /var/www/html/uploads/debug /var/www/html/uploads/history \
    && chmod -R 0777 /var/www/html/uploads

EXPOSE 80

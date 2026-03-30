FROM php:8.1-apache

RUN apt-get update \
  && apt-get install -y --no-install-recommends openssl ca-certificates msmtp msmtp-mta \
  && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install mysqli pdo pdo_mysql

RUN echo 'sendmail_path=/usr/bin/msmtp -t -i' > /usr/local/etc/php/conf.d/mail.ini

RUN a2enmod ssl rewrite headers

COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/apache/default-ssl.conf /etc/apache2/sites-available/default-ssl.conf
COPY docker/apache/start-apache-with-msmtp.sh /usr/local/bin/start-apache-with-msmtp.sh

RUN chmod +x /usr/local/bin/start-apache-with-msmtp.sh

RUN mkdir -p /etc/apache2/ssl \
  && openssl req -x509 -nodes -days 3650 \
  -subj "/C=DE/ST=Local/L=Local/O=BasarenoWeb/OU=Dev/CN=localhost" \
  -newkey rsa:2048 \
  -keyout /etc/apache2/ssl/localhost.key \
  -out /etc/apache2/ssl/localhost.crt \
  && a2ensite default-ssl

CMD ["/usr/local/bin/start-apache-with-msmtp.sh"]

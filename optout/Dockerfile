FROM php:7.2-fpm

# Install LDAP extension and dependencies
RUN apt-get update && apt-get install -y \
    libldap2-dev \
    && docker-php-ext-configure ldap --with-libdir=lib/x86_64-linux-gnu \
    && docker-php-ext-install ldap

RUN docker-php-ext-install mysqli pdo pdo_mysql
RUN docker-php-ext-enable mysqli

FROM local-php-base:latest
COPY docker/nginx.conf /etc/nginx/sites-enabled/default
RUN printf "upload_max_filesize = 50M\npost_max_size = 55M\n" > /usr/local/etc/php/conf.d/uploads.ini
WORKDIR /app
EXPOSE 8080
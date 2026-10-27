FROM php:8.3-cli
RUN apt-get update && apt-get install -y --no-install-recommends libbz2-dev \
	&& docker-php-ext-install bz2 \
	&& rm -rf /var/lib/apt/lists/*

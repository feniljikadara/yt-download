FROM php:8.2-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    ffmpeg \
    python3 \
    python3-pip \
    && rm -rf /var/lib/apt/lists/*

# Install yt-dlp
RUN pip3 install --break-system-packages yt-dlp

# Set working directory
WORKDIR /app

# Copy application files
COPY yt-downloader.php .

# Create required directories
RUN mkdir -p youtube_downloads temp_yt_downloads && \
    chmod 777 youtube_downloads temp_yt_downloads

# Update paths in the PHP file for Docker environment
RUN sed -i "s|'/usr/local/bin/yt-dlp'|'/usr/local/bin/yt-dlp'|g" yt-downloader.php && \
    sed -i "s|'/bin/ffmpeg'|'/usr/bin/ffmpeg'|g" yt-downloader.php

# Expose port
EXPOSE 10000

# Start PHP built-in server
CMD ["php", "-S", "0.0.0.0:10000", "yt-downloader.php"]

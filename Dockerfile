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
COPY setup_cookies.sh .

# Make setup script executable
RUN chmod +x setup_cookies.sh

# Create required directories
RUN mkdir -p youtube_downloads temp_yt_downloads && \
    chmod 777 youtube_downloads temp_yt_downloads

# Expose port
EXPOSE 10000

# Run setup script and start PHP server
CMD ./setup_cookies.sh && php -S 0.0.0.0:10000 yt-downloader.php

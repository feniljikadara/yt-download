#!/bin/bash
set -e
# Install required dependencies
apt-get update && apt-get install -y \
    ffmpeg \
    python3-pip \
    && rm -rf /var/lib/apt/lists/*
pip3 install yt-dlp
mkdir -p youtube_downloads temp_yt_downloads
chmod 777 youtube_downloads temp_yt_downloads

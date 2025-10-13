#!/bin/bash
# This script creates the cookies file from environment variable
# Run this in your Render build command

if [ ! -z "$YOUTUBE_COOKIES" ]; then
    echo "Setting up YouTube cookies..."
    echo "$YOUTUBE_COOKIES" > /app/youtube_cookies.txt
    chmod 600 /app/youtube_cookies.txt
    echo "Cookies file created successfully"
else
    echo "Warning: YOUTUBE_COOKIES environment variable not set"
fi

#!/bin/bash
# This script creates the cookies file from environment variable

if [ ! -z "$YOUTUBE_COOKIES" ]; then
    echo "Setting up YouTube cookies..."
    
    # Write cookies to file, interpreting escape sequences
    echo -e "$YOUTUBE_COOKIES" > /app/youtube_cookies.txt
    
    chmod 600 /app/youtube_cookies.txt
    
    echo "Cookies file created at /app/youtube_cookies.txt"
    echo "File size: $(wc -c < /app/youtube_cookies.txt) bytes"
    echo "Line count: $(wc -l < /app/youtube_cookies.txt) lines"
    echo "First line: $(head -n 1 /app/youtube_cookies.txt)"
    
    # Verify it's a valid Netscape format
    if head -n 1 /app/youtube_cookies.txt | grep -q "Netscape HTTP Cookie File"; then
        echo "✓ Valid Netscape cookie format detected"
    else
        echo "⚠ Warning: Cookie file may not be in correct Netscape format"
    fi
else
    echo "⚠ Warning: YOUTUBE_COOKIES environment variable not set"
    echo "yt-dlp may encounter bot detection without cookies"
fi

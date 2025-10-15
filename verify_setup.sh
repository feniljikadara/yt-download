#!/bin/bash
# Verify all dependencies are working before starting the server

echo "========================================="
echo "Verifying Deployment Setup"
echo "========================================="

# Check yt-dlp
echo -n "Checking yt-dlp... "
if command -v yt-dlp &> /dev/null; then
    YT_DLP_VERSION=$(yt-dlp --version 2>&1)
    echo "✓ Found: $YT_DLP_VERSION"
    echo "  Path: $(which yt-dlp)"
else
    echo "✗ NOT FOUND"
    exit 1
fi

# Check ffmpeg
echo -n "Checking ffmpeg... "
if command -v ffmpeg &> /dev/null; then
    FFMPEG_VERSION=$(ffmpeg -version 2>&1 | head -n 1)
    echo "✓ Found: $FFMPEG_VERSION"
    echo "  Path: $(which ffmpeg)"
else
    echo "✗ NOT FOUND"
    exit 1
fi

# Check PHP
echo -n "Checking PHP... "
if command -v php &> /dev/null; then
    PHP_VERSION=$(php -v 2>&1 | head -n 1)
    echo "✓ Found: $PHP_VERSION"
else
    echo "✗ NOT FOUND"
    exit 1
fi

# Check Python3
echo -n "Checking Python3... "
if command -v python3 &> /dev/null; then
    PYTHON_VERSION=$(python3 --version 2>&1)
    echo "✓ Found: $PYTHON_VERSION"
else
    echo "✗ NOT FOUND"
    exit 1
fi

# Test yt-dlp with a simple command
echo -n "Testing yt-dlp functionality... "
if yt-dlp --help &> /dev/null; then
    echo "✓ Working"
else
    echo "✗ FAILED"
    echo "Error output:"
    yt-dlp --help 2>&1
    exit 1
fi

echo "========================================="
echo "All checks passed! Starting application..."
echo "========================================="

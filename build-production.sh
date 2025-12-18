#!/bin/bash

# LinkAce Production Build Script
# This script prepares LinkAce for production deployment

echo "🚀 Starting LinkAce Production Build..."

# Set production environment
echo "📝 Setting production environment..."
sed -i 's/APP_ENV=.*/APP_ENV=production/' .env
sed -i 's/APP_DEBUG=.*/APP_DEBUG=false/' .env

# Install/update dependencies
echo "📦 Installing dependencies..."
composer install --no-dev --optimize-autoloader

# Build assets
echo "🔨 Building assets..."
npm run production

# Generate app key if not set
if ! grep -q "APP_KEY=base64:" .env; then
    echo "🔑 Generating app key..."
    php artisan key:generate
fi

# Cache for production
echo "⚡ Caching for performance..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Set permissions
echo "🔒 Setting permissions..."
chmod -R 755 storage bootstrap/cache

# Create production zip
echo "📦 Creating production zip..."
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
ZIP_NAME="linkace-production-${TIMESTAMP}.zip"

# Clean zip (git archive approach)
git archive --format=zip --output="../${ZIP_NAME}" HEAD

echo "✅ Production build complete!"
echo "📁 Zip created: ../${ZIP_NAME}"
echo "📏 Size: $(ls -lh ../${ZIP_NAME} | awk '{print $5}')"
echo ""
echo "📋 Next steps:"
echo "1. Upload the zip to your hosting server"
echo "2. Extract to web root (public/ as document root)"
echo "3. Configure .env and run setup commands"
echo "4. See INSTALLATION_GUIDE.md for details"

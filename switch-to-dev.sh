#!/bin/bash

# LinkAce Development Mode Script
# This script switches LinkAce back to development mode

echo "🔄 Switching LinkAce to Development Mode..."

# Set development environment
echo "📝 Setting development environment..."
sed -i 's/APP_ENV=.*/APP_ENV=local/' .env
sed -i 's/APP_DEBUG=.*/APP_DEBUG=true/' .env

# Clear caches
echo "🧹 Clearing caches..."
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

# Install development dependencies
echo "📦 Installing development dependencies..."
composer install

# Build development assets
echo "🔨 Building development assets..."
npm run dev

# Set permissions for development
echo "🔒 Setting development permissions..."
chmod -R 755 storage bootstrap/cache

# Restart valet if available
if command -v valet &> /dev/null; then
    echo "🔄 Restarting Valet..."
    valet restart
fi

echo "✅ Switched to development mode!"
echo ""
echo "🌐 Your app should be available at: http://linkace.test"
echo "📝 Debug mode: ON"
echo "⚡ Caches: CLEARED"
echo "🔨 Assets: Development build"

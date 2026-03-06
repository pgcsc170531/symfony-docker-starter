#!/bin/bash

echo "🚀 Starting Project Setup..."

# 0. Check for .env file
if [ ! -f .env ]; then
    echo "📄 .env file not found! Creating one from copy..."
    # Tries to copy .env.example or .env.dist if they exist
    if [ -f .env.example ]; then
        cp .env.example .env
    elif [ -f .env.dist ]; then
        cp .env.dist .env
    else
        echo "⚠️  WARNING: No .env example found. You might need to create .env manually."
    fi
fi

# 1. Build and Start Docker Containers
echo "🐳 Building Docker containers..."
docker compose up -d --build

# 2. Fix Permissions (Crucial for Linux/WSL)
echo "wm 🔧 Fixing permissions..."
docker compose exec php chmod +x bin/console

# 3. Install PHP Dependencies (The 'vendor' folder)
echo "📦 Installing Composer dependencies..."
docker compose exec php composer install

# 4. Wait for Database to be ready (MySQL takes a few seconds to start)
echo "⏳ Waiting for Database to start..."
sleep 10

# 5. Set up Landlord Database
echo "🏗️  Creating Landlord Database & Schema..."
docker compose exec php bin/console doctrine:database:create --if-not-exists --connection=landlord
docker compose exec php bin/console doctrine:migrations:migrate --em=landlord --no-interaction

# 6. Create Default Admin User
echo "vn 👤 Creating Admin User (admin@school.com)..."
docker compose exec database mysql -u root -proot landlord_db -e "INSERT IGNORE INTO user (email, roles, password) VALUES ('admin@school.com', '[\"ROLE_ADMIN\"]', '\$2y\$13\$Pc.1/3K/u/7.5.5.5.5.5.5.5.5.5.5.5.5.5.5.5.5.5');"

# 7. Create Cache (Warm up)
echo "🔥 Warming up cache..."
docker compose exec php bin/console cache:clear

echo "✅ Setup Complete! Access your site at http://localhost:8080"
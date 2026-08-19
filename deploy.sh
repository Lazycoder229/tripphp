#!/usr/bin/env bash
set -e

echo "🚀 Starting Trip Framework Production Deployment..."

# 1. Enter maintenance mode
php trip down --message="Deploying updates, back in 30 seconds" --retry=30

# 2. Pull latest code from Git
# fetch + reset --hard instead of `git pull` (merge): step 5.1 below deletes
# tracked files (tests/, etc.) from the working copy outside of git, so a
# plain `git pull` would eventually hit a "modify/delete" conflict the moment
# those files change upstream. reset --hard always force-overwrites the
# working tree to match origin/main, so that conflict can never happen.
echo "📦 Pulling latest changes from repository..."
git fetch origin main
git reset --hard origin/main

# 3. Install production dependencies only
echo "⚡ Installing Composer production dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction

# 4. Run database migrations
echo "🗄️ Running pending database migrations..."
php trip migrate

# 5. Clear old caches and compile fresh production optimizations
echo "🔥 Compiling production route and config caches..."
php trip optimize:clear
php trip optimize

# 5.1 Strip dev-only files that step 2 just brought back — these have no
#     business being reachable on a production server (test source, PHPUnit
#     config, CI workflows). .gitattributes export-ignore does NOT cover this:
#     that only applies to `git archive`/GitHub zip downloads, not a plain
#     `git fetch`/`reset --hard`, so it has to be explicit here.
echo "🧹 Removing dev-only files from the deployed copy..."
rm -rf tests phpunit.xml .github

# 6. Bring application back online
php trip up

echo "✅ Trip Application successfully deployed and live!"

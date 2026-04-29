# AutoSync Product Manager

**Version:** 5.10  
**Author:** Syed  
**Requires:** WordPress 5.8+, WooCommerce, PHP 7.4+

A WordPress plugin for automated product synchronization from AutoSync Studio API to WooCommerce with brand management, change detection, and automated syncing capabilities.

---

## Table of Contents

1. [Overview](#overview)
2. [Features](#features)
3. [Installation & Setup](#installation--setup)
4. [Configuration](#configuration)
5. [Cron Setup](#cron-setup)
6. [Troubleshooting](#troubleshooting)
7. [Testing](#testing)
8. [Development Information](#development-information)
9. [Support](#support)

---

## Overview

AutoSync Product Manager synchronizes wheel and tire products from AutoSync Studio API to your WooCommerce store. It features:

- **Hash-based change detection** - Only syncs products that have changed
- **Brand management** - Control which brands to sync
- **Automated syncing** - Daily full sync and frequent price/stock updates
- **Optimized performance** - Handles 80,000+ products efficiently
- **Notifications** - Email and Slack integration

---

## Features

### Core Functionality

1. **API Configuration**
   - Configure API key and base URL for AutoSync Studio API
   - Secure credential storage

2. **Brand Management**
   - Fetch all brands from API (wheels and tires)
   - Activate/deactivate brands for syncing
   - View brand statistics and counts
   - Manage brands separately for wheels and tires

3. **Hash-based Change Detection**
   - SHA-256 hashing for product data
   - Hash table to track product changes
   - Skip unchanged products during sync
   - Rebuild/truncate hash table
   - Clear price/stock hash values
   - Duplicate SKU detection

4. **External Price and Stock Synchronization**
   - Price/stock sync runs only when your external cron job calls it
   - Updates only when price/stock hash changes
   - Batch processing for performance
   - Only updates existing products (requires full sync first)

5. **External Full Product Sync**
   - Syncs all active brands when your CLI or HTTP cron endpoint is called
   - No internal WordPress scheduling is registered
   - External cron support via CLI and HTTP endpoints
   - Sync status tracking and reporting

6. **Email Notifications**
   - WooCommerce email integration
   - Email notifications on sync completion
   - Configurable recipients
   - Sync statistics in emails

7. **Slack Notifications**
   - Webhook integration for Slack
   - Real-time sync completion notifications
   - Enable/disable toggle
   - Test notification functionality

8. **Product Deletion by Brand**
   - Delete products by brand (wheels or tires)
   - Single or bulk deletion
   - Batch deletion for performance
   - Safety warnings before deletion

9. **Dashboard and Statistics**
   - Overview of sync status
   - Brand activation statistics
   - Hash table statistics
   - Last sync timestamps
   - Product counts per brand

10. **Image Management**
    - Download product images from API
    - External URL support (faster option)
    - Local image storage option
    - Batch image processing

11. **Advanced Sync Settings**
    - Product import mode (Create, Update, or Both)
    - Image storage mode (External URLs or Download)
    - Manual sync triggers from Brand Management page

---

## Installation & Setup

### Prerequisites

- WordPress 5.8 or higher
- WooCommerce installed and activated
- Valid AutoSync Studio API key
- PHP 7.4 or higher

### Installation Steps

1. Upload the plugin to `/wp-content/plugins/autosync-product-manager/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to **AutoSync Manager** → **Settings**
4. Enter your API key and API URL
5. Save settings

### Initial Setup

1. **Configure API Settings**
   - Go to **AutoSync Manager** → **Settings** → **API Configuration**
   - Enter your AutoSync Studio API key
   - Verify API URL (default: `https://api.autosyncstudio.com`)
   - Save settings

2. **Fetch and Activate Brands**
   - Go to **AutoSync Manager** → **Brand Management**
   - Click **Fetch All Brands**
   - Activate brands you want to sync
   - Brands are checked (active) by default

3. **Set Up Cron Jobs** (See [Cron Setup](#cron-setup) section)

---

## Configuration

### API Settings

- **API Key**: Your AutoSync Studio API key
- **API URL**: Base URL for API (default: `https://api.autosyncstudio.com`)

### Sync Settings

- **Product Import Mode**: 
  - `both` - Create new and update existing (default)
  - `create` - Only create new products
  - `update` - Only update existing products

- **Image Storage Mode**:
  - `external` - Use external URLs (faster, default)
  - `download` - Download to WordPress media library

### Email Notifications

Configure via **WooCommerce** → **Settings** → **Emails** → **Product Sync Notification**

### Slack Notifications

1. Create a Slack webhook (see [Slack Integration](#slack-integration) section)
2. Go to **AutoSync Manager** → **Settings** → **Sync Settings**
3. Enter Slack webhook URL
4. Enable/disable notifications
5. Test notification

---

## Cron Setup

### Overview

The plugin supports two external sync jobs:

1. **Full Product Sync** - Runs when your full-sync cron command calls the plugin
2. **Price/Stock Sync** - Runs when your price/stock cron command calls the plugin

### Why External Cron?

- **No PHP execution time limits** - Can run for hours if needed
- **Better for large syncs** - Handles 80,000+ products without getting stuck
- **More reliable** - Not dependent on website traffic
- **Better error handling** - Proper logging and error recovery

### Full Product Sync Setup

#### Option 1: cPanel Cron Jobs (Recommended)

1. Log into cPanel
2. Go to **Cron Jobs**
3. Add new cron job:
   - **Minute:** `0`
   - **Hour:** `2`
   - **Day:** `*`
   - **Month:** `*`
   - **Weekday:** `*`
   - **Command (CLI Method - BEST):**
     ```bash
     php /home/username/staging2.sdtireandwheel.com/public_html/wp-content/plugins/autosync-product-manager/cron/cron-runner.php
     ```
   - **Command (HTTP Method - If CLI blocked):**
     ```bash
     curl -s "https://yoursite.com/?asm_cron_run=1&key=your-very-secure-secret-key-here" > /dev/null 2>&1
     ```

#### Option 2: SSH/crontab

```bash
# Edit crontab
crontab -e

# Add this line (CLI method):
0 2 * * * /usr/bin/php /home/username/path/to/wp-content/plugins/autosync-product-manager/cron/cron-runner.php >> /var/log/asm-cron.log 2>&1

# Or HTTP method:
0 2 * * * curl -s "https://yoursite.com/?asm_cron_run=1&key=your-secret-key" >> /var/log/asm-cron.log 2>&1
```

#### Option 3: External Cron Service

Use services like EasyCron, Cronitor, or UptimeRobot to call:
```
https://yoursite.com/?asm_cron_run=1&key=your-very-secure-secret-key-here
```

### Price/Stock Sync Setup

#### cPanel Cron Jobs

1. Log into cPanel
2. Go to **Cron Jobs**
3. Add new cron job:
   - **Minute:** `0` for hourly, or your preferred external interval
   - **Hour:** `*`
   - **Day:** `*`
   - **Month:** `*`
   - **Weekday:** `*`
   - **Command:**
     ```bash
     php /home/username/path/to/wp-content/plugins/autosync-product-manager/cron/price-stock-cron-runner.php
     ```

#### SSH/crontab

```bash
# Every hour
0 * * * * /usr/bin/php /path/to/cron/price-stock-cron-runner.php >> /var/log/asm-price-stock-cron.log 2>&1

# Or HTTP method:
0 * * * * curl -s "https://yoursite.com/?asm_price_stock_sync=1&key=your-secret-key" > /dev/null 2>&1
```

### Finding Your WordPress Path

```bash
# Via SSH:
find /home -name "wp-load.php" 2>/dev/null

# Or from plugin directory:
cd wp-content/plugins/autosync-product-manager
ls -la ../../../wp-load.php
```

### Security: Change Secret Key

⚠️ **IMPORTANT**: Change the secret key before using in production!

1. Open `autosync-product-manager.php`
2. Search for `handle_public_cron_endpoint` or `handle_price_stock_sync_endpoint`
3. Find: `$expected_key = 'your-very-secure-secret-key-here';`
4. Change to a strong, unique secret key
5. Use the same key in your cron job URL

### Testing Cron

```bash
# Test CLI method:
php /path/to/cron/cron-runner.php

# Test HTTP endpoint:
curl "https://yoursite.com/?asm_cron_run=1&key=your-secret-key"
```

---

## Troubleshooting

### 504 Gateway Timeout Error

**Problem**: Sync times out when processing large product catalogs (80,000+ products).

**Solution**: Use CLI method instead of HTTP method.

**CLI Method (Recommended):**
```bash
php /path/to/cron/cron-runner.php
```

**Why CLI is better:**
- ✅ No timeout limits
- ✅ Can run for hours
- ✅ Better error handling
- ✅ Proper logging

**HTTP Endpoint (Background Mode):**
The HTTP endpoint now returns immediately and runs sync in background:
```
https://yoursite.com/?asm_cron_run=1&key=your-secret-key
```

### Permission Denied Errors

**Problem**: "Permission denied" when running cron-runner.php

**Solutions:**

1. **Use PHP command:**
   ```bash
   php cron/cron-runner.php
   # Not: ./cron/cron-runner.php
   ```

2. **Check file permissions:**
   ```bash
   chmod 644 cron/cron-runner.php
   ```

3. **Use HTTP endpoint instead** (bypasses file permissions):
   ```
   https://yoursite.com/?asm_cron_run=1&key=your-secret-key
   ```

### WordPress Not Found

**Problem**: "WordPress not found" error

**Solution:**
```bash
# Find WordPress installation:
find /home -name "wp-load.php" 2>/dev/null

# Navigate to plugin directory:
cd /path/to/wp-content/plugins/autosync-product-manager
```

### Hash Entries Created on Activation

**Problem**: Hash entries are created automatically after plugin activation without API key.

**Solution**: This has been fixed. Price/stock sync now only runs when:
- Hash table has entries (full sync has run)
- API key is configured

### Cron Not Running

**Checklist:**
1. Verify cron is scheduled: `crontab -l`
2. Check cron logs: `tail -f /var/log/asm-cron.log`
3. Test manually: `php cron/cron-runner.php`
4. Verify API key is correct
5. Check WooCommerce logs: **WooCommerce** → **Status** → **Logs**

### Sync Taking Too Long

**For 80,000+ products:**
- Full sync can take 1-2 hours (normal)
- Price/stock sync takes 15-60 minutes (normal)
- Use CLI method to avoid timeouts
- Monitor via logs and sync status

### All Products Skipped

**This is normal if:**
- Products haven't changed (hash matches)
- Full sync hasn't run yet (for price/stock sync)
- Hash table is empty

**To test:**
- Run full product sync first
- Wait for API data to change
- Or temporarily modify hash in database

---

## Testing

### Quick Test Checklist

**Essential Tests (5 minutes):**
- [ ] Plugin activates
- [ ] API connection works
- [ ] Brands can be fetched and activated
- [ ] Sync status displays
- [ ] Hash table created

**Full Test Suite (30 minutes):**
- [ ] Single product import
- [ ] Bulk import with pagination
- [ ] Brand activation/deactivation
- [ ] Hash-based change detection
- [ ] Sync status tracking
- [ ] Email notifications
- [ ] Slack notifications
- [ ] Product deletion by brand

### Test Scenarios

1. **Hash Detection Test**
   - Import a product
   - Import same product again → Should skip (hash matches)
   - Modify hash in database → Import again → Should update

2. **Brand Management Test**
   - Fetch brands
   - Deactivate some brands
   - Run sync → Only active brands should sync

3. **Sync Status Test**
   - Run sync
   - Check sync status in Brand Management
   - Verify statistics are accurate

See `TESTING.md` for comprehensive test documentation.

---

## Development Information

### Project Structure

```
wp-content/plugins/autosync-product-manager/
├── autosync-product-manager.php        # Main plugin file
├── cron/
│   ├── cron-runner.php                 # External cron runner
│   └── price-stock-cron-runner.php     # Price/Stock sync cron runner
├── includes/
│   ├── class-api-client.php           # API wrapper
│   ├── class-product-importer.php     # Product creation
│   ├── class-logger.php               # Logging system
│   ├── class-hash-manager.php         # Hash storage & change detection
│   ├── class-hash-generator.php       # Hash generation
│   ├── class-brand-manager.php        # Brand management
│   ├── class-cron-sync.php            # External full product sync
│   ├── class-price-stock-sync.php    # Price/Stock sync
│   ├── class-sql-batch-inserter.php  # Optimized batch insertion
│   ├── class-sync-status-manager.php # Sync status tracking
│   ├── class-product-deleter.php    # Product deletion utility
│   ├── class-image-downloader.php   # Image management
│   ├── class-email-notifier.php      # Email notifications
│   └── class-slack-notifier.php      # Slack notifications
└── admin/
    ├── views/
    │   ├── settings.php                # Settings page UI
    │   ├── hash-management.php         # Hash management UI
    │   ├── brand-management.php       # Brand management UI
    │   └── dashboard.php              # Dashboard UI
    ├── css/
    │   └── admin.css                  # Admin styles
    └── js/
        └── admin.js                   # AJAX handlers
```

### Database Tables

**Custom Tables:**
- `wp_asm_product_hashes` - Stores product hashes for change detection
  - Columns: id, sku, hash, price_stock_hash, product_id, product_type, brand, last_updated, created_at

**WordPress Options:**
- `asm_settings` - API settings
- `asm_sync_settings` - Sync settings
- `asm_brands_data` - Brand data (tires and wheels)
- `asm_products_sync_status` - Last sync status and statistics
- `asm_price_stock_sync_status` - Price/stock sync status

### AJAX Endpoints

- `asm_bulk_import` - Bulk import wheels with pagination
- `asm_bulk_import_tires` - Bulk import tires with pagination
- `asm_batch_insert_optimized` - Optimized batch insert
- `asm_fetch_brands` - Fetch all brands from API
- `asm_update_brand_status` - Update single brand active status
- `asm_batch_update_brand_status` - Bulk update brand status
- `asm_get_activated_brands` - Get list of active brands
- `asm_save_sync_status` - Save sync statistics
- `asm_get_sync_status` - Get saved sync status
- `asm_rebuild_hash_table` - Rebuild hash table
- `asm_truncate_hash_table` - Clear hash table
- `asm_empty_price_stock_hash` - Clear price/stock hash values
- `asm_delete_products_by_brand` - Delete products by brand
- `asm_sync_inventory_quantity` - Manual price/stock sync trigger
- `asm_test_slack` - Test Slack notification

### Development Phases

**Completed (8/10 phases):**
- ✅ Phase 1: Foundation & API Connection
- ✅ Phase 2: Single Product Import
- ✅ Phase 3: Bulk Import with Pagination
- ✅ Phase 3.5: Tire Support
- ✅ Phase 4: Action Scheduler Automation
- ✅ Phase 5: Change Detection & Hash Management
- ✅ Phase 6: Brand Management System
- ✅ Phase 7: Optimized SQL Batch Insertion
- ✅ Phase 8: Sync Status & Statistics Tracking

**Pending:**
- ⏳ Phase 9: Image Downloads & Media Library
- ⏳ Phase 10: Advanced Features

See `DEVELOPMENT-PLAN.md` for detailed development history.

---

## Slack Integration

### Setup Instructions

1. **Create Slack Webhook:**
   - Go to https://api.slack.com/apps
   - Create new app → **Incoming Webhooks**
   - Activate incoming webhooks
   - Add webhook to workspace
   - Copy webhook URL

2. **Configure in WordPress:**
   - Go to **AutoSync Manager** → **Settings** → **Sync Settings**
   - Scroll to **Slack Notifications** section
   - Paste webhook URL
   - Enable notifications
   - Test notification

### Notification Format

Slack notifications include:
- ✅ Sync completion status
- 📊 Summary statistics (imported, updated, skipped)
- 🛞 Tires section with brand-wise stats
- 🚗 Wheels section with brand-wise stats
- ⏱️ Duration and performance metrics

### When Notifications Are Sent

- Cron sync completion
- Manual sync completion
- Public cron endpoint sync

See `SLACK-INTEGRATION.md` for detailed setup guide.

---

## Support

### Logs Location

- **WooCommerce Logs**: WooCommerce → Status → Logs
- **WordPress Debug Log**: `wp-content/debug.log`
- **Cron Logs**: `/var/log/asm-cron.log` (if configured)

### Getting Help

1. Check WooCommerce logs for errors
2. Review sync status in Brand Management
3. Verify API key is correct
4. Check cron job is scheduled correctly
5. Review troubleshooting section above

### Important Notes

- **Hash table is created on activation** - This is normal
- **Price/stock sync requires full sync first** - Hash table must have entries
- **Large syncs take time** - 80,000+ products can take 1-2 hours
- **Use CLI cron method** - Best for large product catalogs

---

## Version History

- **5.10** - Current version
- **5.2** - Brand management, sync status tracking, optimized SQL
- **5.0** - Initial release with core functionality

---

**Last Updated**: 2024  
**Plugin URI**: https://sdtireandwheel.com  
**Author**: Syed


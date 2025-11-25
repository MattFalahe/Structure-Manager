# Structure Manager for SeAT

[![Latest Version](https://img.shields.io/packagist/v/mattfalahe/structure-manager.svg?style=flat-square)](https://packagist.org/packages/mattfalahe/structure-manager)
[![License](https://img.shields.io/badge/license-GPL--2.0-blue.svg?style=flat-square)](LICENSE)
[![SeAT](https://img.shields.io/badge/SeAT-5.x-blue.svg?style=flat-square)](https://github.com/eveseat/seat)

A comprehensive fuel management system for EVE Online corporation structures in SeAT. Track fuel consumption, monitor reserves, receive notifications, and optimize logistics across all your Upwell structures and legacy Player Owned Starbases (POS towers).

📸 **[See screenshots in the Wiki →](https://github.com/MattFalahe/Structure-Manager/wiki)**

## Features

### 🔥 Real-Time Fuel Tracking
- **Upwell Structures**: Hourly automated tracking with consumption analysis every 30 minutes
- **POS Towers**: 10-minute tracking intervals for real-time monitoring
- **Dual tracking method**: Primary fuel bay monitoring with days-remaining fallback
- **Automatic refuel detection** when fuel is added to structures
- **Historical consumption analysis** with anomaly detection (6 months for Upwell, 90 days for POS)

### 📊 Advanced Analytics
- **Accurate consumption calculations** based on actual online services
- **Service-specific fuel rates** with proper structure bonuses
- **Consumption trend analysis** to detect service changes
- **Predictive fuel requirements** for logistics planning
- **Interactive consumption charts** on detail pages

### 🏭 Structure Support

#### Upwell Structures
- **Citadels** (Astrahus, Fortizar, Keepstar)
- **Engineering Complexes** (Raitaru, Azbel, Sotiyo)
- **Refineries** (Athanor, Tatara)
- **Metenox Moon Drill** dual-fuel support
- **Navigation Structures** (Ansiblex, Pharolux, Tenebrex)

#### Legacy Player Owned Starbases (POS)
- **Multi-resource tracking**: Fuel blocks, strontium clathrates, and starbase charters
- **Tower size support**: Small, Medium, and Large towers with accurate consumption rates
- **Smart security detection**: Automatic charter tracking in high-security space
- **Limiting factor detection**: Identifies which resource will run out first
- **Faction & Officer towers**: Full support for fuel efficiency bonuses

### 🌕 Metenox Moon Drill Support
- **Dual-fuel system tracking**: Monitors both fuel blocks (120/day) and magmatic gas (4,800/day)
- **Limiting factor detection**: Automatically identifies which resource depletes first
- **Purple visual indicators**: Distinct badges for gas-related data throughout interface
- **Separate projections**: Individual consumption analysis for each fuel type
- **Magmatic gas reserves**: Tracks staged gas in corporation hangars

### 📦 Reserve Management
- **CorpSAG hangar tracking** for staged fuel blocks and magmatic gas
- **Selective hangar tracking**: Exclude specific hangars from reserves (market trading, logistics, etc.)
- **Nested Office container support** for reserve detection
- **Refuel event logging** when fuel moves from reserves to bay
- **Custom division name display** for organized fuel storage
- **Works for both Upwell structures and POSes**

### 🔔 Discord & Slack Notifications (POS Only)
- **Webhook integration**: Rich embeds with color-coded alerts (Red: Critical, Yellow: Warning)
- **Smart alerting**: Status-based notifications prevent spam (Good → Warning → Critical)
- **Customizable intervals**: Set reminder frequencies during critical stage or disable completely
- **Final alerts**: Urgent notification 1 hour before POS goes offline
- **Discord role mentions**: Ping specific teams for critical alerts
- **Independent tracking**: Separate alerts for fuel blocks, strontium, and charters
- **Threshold customization**: Configure when alerts trigger for each resource

### 🚨 Critical Alerts
- **Priority-based warnings** (Critical < 7 days, Warning < 14 days)
- **Fuel bay status monitoring** separate from total reserves
- **System-grouped alerts** for efficient logistics
- **Real-time dashboard widget** for quick overview
- **Limiting factor badges** for Metenox and POS resources

### 🚚 Logistics Planning
- **System-based fuel requirements** with 30/60/90-day projections
- **Hauling volume calculations** with trip estimates
- **Reserve vs. bay fuel breakdown** for refuel planning
- **Dual-fuel support**: Separate calculations for Metenox structures
- **CSV export functionality** for external logistics tools

### ⚙️ Settings & Configuration
- **Centralized settings page** for all plugin configuration
- **Webhook management**: Configure Discord/Slack notifications with test functionality
- **Threshold customization**: Set critical/warning levels for all resource types
- **Hangar exclusion**: Choose which corporate hangars to track for reserves
- **Notification intervals**: Configure reminder frequencies for fuel and strontium

### 📚 Help & Documentation
- **Complete in-app help system** with 9 major sections
- **15 FAQ entries** covering common questions
- **6 troubleshooting guides** with step-by-step solutions
- **Searchable documentation** for quick answers
- **Artisan command reference** with examples

### 🎨 Enhanced User Interface
- **Dark theme optimized** with improved contrast and readability
- **Responsive design** for mobile and desktop
- **Interactive charts** for consumption visualization
- **Clean, modern layout** matching SeAT 5.x design language
- **Separate pages**: Upwell Structures, Control Towers (POS), Settings, Help & Documentation

## Installation

```bash
composer require mattfalahe/structure-manager
php artisan migrate
```

That's it! The plugin will automatically:
- Start tracking fuel levels on the next scheduled run
- Create default settings
- Register scheduled tasks
- Set up navigation menu items

## Usage

### Dashboard Overview
Access Structure Manager from the main SeAT sidebar. The main dashboard shows:
- All corporation Upwell structures with fuel status
- Color-coded alerts (Red: Critical, Yellow: Warning, Green: Normal, Blue: Excellent)
- Real-time consumption rates
- Days remaining until refuel needed
- Metenox dual-fuel status with limiting factor indicators

### Control Towers (POS)
Dedicated page for legacy Player Owned Starbases:
- View all POSes with multi-resource status (fuel, strontium, charters)
- 10-minute tracking intervals for real-time data
- Security space detection (charters tracked in high-sec only)
- Limiting factor badges show priority resources
- Individual POS detail pages with 90-day history

### Fuel Reserves
Track staged fuel blocks and magmatic gas across your structures:
- View reserves by system and structure
- See which CorpSAG divisions contain fuel and gas
- Monitor reserve movements and refuel events
- Exclude specific hangars from tracking (market, logistics, personal storage)
- Works for both Upwell structures and POSes

### Critical Alerts
Stay on top of urgent fuel needs:
- Structures and POSes with less than 14 days of fuel
- Sorted by urgency (most critical first)
- Quick-view fuel requirements for each resource type
- Limiting factor indicators for Metenox and POS
- One-click navigation to structure details

### Logistics Report
Plan your fuel hauling operations:
- Fuel requirements grouped by solar system
- 30/60/90-day projections for each structure
- Separate calculations for fuel blocks and magmatic gas
- Total volume and hauler trip calculations
- CSV export for external planning tools

### Settings
Configure the plugin to match your needs:
- **Notification Settings**: Webhook URLs, intervals, role mentions, thresholds
- **Reserves Tracking**: Select which hangars to include in reserves calculations
- **Test Webhook**: Verify Discord/Slack connectivity
- **Restore Defaults**: Reset all settings to default values

### Structure Detail Pages
Deep dive into individual structure or POS fuel data:
- Complete fuel history with consumption graphs
- Service module breakdown with consumption rates
- Fuel projections and refuel recommendations
- Historical analysis with refuel event timeline
- Reserve status and movement tracking
- For Metenox: Separate charts for fuel blocks and magmatic gas
- For POS: Separate displays for fuel, strontium, and charters

### Help & Documentation
Comprehensive in-app documentation:
- Complete feature explanations
- Getting started guide
- Fuel mechanics reference
- Troubleshooting guides
- FAQ section with 15 entries
- Artisan command reference

## Permissions

Structure Manager uses SeAT's permission system:

- `structure-manager.view`: View structure fuel data
- `structure-manager.admin`: Administrative functions
- `structure-manager.export`: Export logistics reports

Assign permissions via SeAT's Settings → Access Management.

## Automated Tasks

The plugin runs several scheduled jobs automatically:

### Upwell Structures
1. **Fuel Tracking** (`structure-manager:track-fuel`)
   - Runs hourly at :15 past the hour
   - Tracks fuel bay levels and consumption

2. **Consumption Analysis** (`structure-manager:analyze-consumption`)
   - Runs every 30 minutes
   - Analyzes consumption patterns and generates recommendations

### POS Towers
3. **POS Fuel Tracking** (`structure-manager:track-poses-fuel`)
   - Runs every 10 minutes
   - Real-time tracking of fuel, strontium, and charters

4. **POS Consumption Analysis** (`structure-manager:analyze-pos-consumption`)
   - Runs daily at 01:00 AM
   - Analyzes POS consumption patterns

5. **POS Notifications** (`structure-manager:notify-pos-fuel`)
   - Runs every 10 minutes
   - Sends webhook alerts for low fuel levels

### Maintenance
6. **History Cleanup** (`structure-manager:cleanup-history`)
   - Runs daily at 03:00 AM
   - Removes old history records (6 months for Upwell, 90 days for POS)
   - Keeps reserve data for 3 months

### Manual Commands

```bash
# Upwell structures
php artisan structure-manager:track-fuel
php artisan structure-manager:analyze-consumption

# POS towers
php artisan structure-manager:track-poses-fuel
php artisan structure-manager:analyze-pos-consumption
php artisan structure-manager:notify-pos-fuel

# Maintenance
php artisan structure-manager:cleanup-history
```

## Requirements

- SeAT 5.x
- PHP 8.1 or higher
- MySQL/MariaDB
- Corporation structures tracked by SeAT
- Corporation asset data (for fuel bay and reserve tracking)
- Valid ESI tokens with required scopes

## Troubleshooting

### No fuel data showing
1. Ensure your corporation's ESI tokens have the required scopes
2. Wait for SeAT to complete initial corporation asset sync
3. Check that structures have fuel_expires timestamps
4. Run `php artisan structure-manager:track-fuel` manually
5. For POS: Run `php artisan structure-manager:track-poses-fuel`

### Inaccurate consumption rates
1. Allow 24-48 hours for initial data collection
2. Ensure services are properly online in game
3. Check for recent service changes or structure states
4. Verify fuel blocks are tracked in corporation assets

### Reserves not detected
1. Fuel must be in structure's CorpSAG hangars (1-7)
2. Or in Office containers inside the structure
3. Must be fuel blocks (Type ID: 4312) or magmatic gas (Type ID: 58903)
4. Corporation asset data must be up to date
5. Check Settings → Reserves Tracking to verify hangars aren't excluded

### POS notifications not working
1. Go to Settings and verify webhook URL is configured
2. Click "Test Webhook" to verify connectivity
3. Ensure notifications are enabled
4. Check notification intervals aren't set to 0 if you want reminders
5. Verify POS has actually changed status (good→warning→critical)
6. Check Laravel logs: `storage/logs/laravel.log`

### Charts not loading
1. Ensure JavaScript is enabled in your browser
2. Check browser console (F12) for errors
3. Clear browser cache and reload
4. Verify sufficient historical data exists (at least a few hours)

For more troubleshooting help, visit the **Help & Documentation** page in Structure Manager or see the [GitHub Wiki](https://github.com/MattFalahe/Structure-Manager/wiki).

## Support & Contributing

- **Issues**: [GitHub Issues](https://github.com/MattFalahe/structure-manager/issues)
- **Wiki**: [Full documentation](https://github.com/MattFalahe/Structure-Manager/wiki)
- **Changelog**: [CHANGELOG.md](CHANGELOG.md)
- **Pull Requests**: Always welcome!

## License

This project is licensed under the GNU General Public License v2.0 - see the [LICENSE](LICENSE) file for details.

This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

## Credits

**Author**: Matt Falahe  
**SeAT Compatibility**: 5.x

Built for the EVE Online community. Special thanks to the SeAT development team and all contributors.

---

*EVE Online and the EVE logo are the registered trademarks of CCP hf. All rights are reserved worldwide. All other trademarks are the property of their respective owners. EVE Online, the EVE logo, EVE and all associated logos and designs are the intellectual property relating to these trademarks are likewise the intellectual property of CCP hf. All artwork, screenshots, characters, vehicles, storylines, world facts or other recognizable features of the intellectual property relating to these trademarks are likewise the intellectual property of CCP hf.*

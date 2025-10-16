# Structure Manager for SeAT

[![Latest Version](https://img.shields.io/packagist/v/mattfalahe/structure-manager.svg?style=flat-square)](https://packagist.org/packages/mattfalahe/structure-manager)
[![License](https://img.shields.io/badge/license-GPL--2.0-blue.svg?style=flat-square)](LICENSE)
[![SeAT](https://img.shields.io/badge/SeAT-5.x-blue.svg?style=flat-square)](https://github.com/eveseat/seat)

A comprehensive fuel management system for EVE Online corporation structures in SeAT. Track fuel consumption, monitor reserves, predict refuel schedules, and optimize logistics across all your Upwell structures.

📸 **[See screenshots in the Wiki →](https://github.com/MattFalahe/Structure-Manager/wiki)**

## Features

### 🔥 Real-Time Fuel Tracking
- **Hourly automated tracking** of fuel bay levels and consumption rates
- **Dual tracking method**: Primary fuel bay monitoring with days-remaining fallback
- **Automatic refuel detection** when fuel is added to structures
- **Historical consumption analysis** with anomaly detection

### 📊 Advanced Analytics
- **Accurate consumption calculations** based on actual online services
- **Service-specific fuel rates** with proper structure bonuses
- **Consumption trend analysis** to detect service changes
- **Predictive fuel requirements** for logistics planning

### 🏭 Multi-Structure Support
- **Citadels** (Astrahus, Fortizar, Keepstar)
- **Engineering Complexes** (Raitaru, Azbel, Sotiyo)
- **Refineries** (Athanor, Tatara)
- **Navigation Structures** (Ansiblex, Pharolux, Tenebrex)

### 📦 Reserve Management
- **CorpSAG hangar tracking** for staged fuel blocks
- **Nested Office container support** for reserve detection
- **Refuel event logging** when fuel moves from reserves to bay
- **Custom division name display** for organized fuel storage
- **Reserve recommendations** based on consumption patterns

### 🚨 Critical Alerts
- **Priority-based warnings** (Critical < 7 days, Warning < 14 days)
- **Fuel bay status monitoring** separate from total reserves
- **System-grouped alerts** for efficient logistics
- **Real-time dashboard widget** for quick overview

### 🚚 Logistics Planning
- **System-based fuel requirements** with 30/60/90-day projections
- **Hauling volume calculations** with trip estimates
- **Reserve vs. bay fuel breakdown** for refuel planning
- **Export functionality** for external logistics tools

### 🎨 Enhanced User Interface
- **Dark theme optimized** with improved contrast and readability
- **Responsive design** for mobile and desktop
- **Interactive charts** for consumption visualization
- **Clean, modern layout** matching SeAT 5.x design language

## Installation

```bash
composer require mattfalahe/structure-manager
```

After installation, run:

```bash
php artisan migrate
```
## What's New in v1.0.7  

## 🧩 Maintenance & Fixes — *Refinements and Stability Improvements*  

This update focuses on **cleaning up internal logic**, **removing redundant setup steps**, and **improving compatibility** with development environments.  
It doesn’t introduce new user-facing features, but it makes the plugin leaner, simpler, and easier to maintain.  

### 🔧 Key Improvements  
- **Simplified Schedule Seeder** — Now uses SeAT’s built-in service class instead of a custom implementation  
- **Removed Unnecessary Setup Command** — `structure-manager:setup` is no longer needed; registration is handled automatically  
- **Improved HTTPS Handling** — Fixed an issue where the plugin would force HTTPS and break local (HTTP) development environments  

---

## 🤝 Contribution  
Special thanks to **@recursivetree** for identifying issues, refining setup flow, and improving plugin compatibility.  

---

## Previous Updates  

---

## What's New in v1.0.6  

## 🌕 New Features — *Metenox Moon Drill Support*

This update introduces **full dual-fuel support** for the new **Metenox Moon Drills**, expanding the plugin’s capabilities to track both **Fuel Blocks** and **Magmatic Gas** simultaneously.

### 🔧 Key Additions
- **Dual Fuel System Tracking** — Complete support for Metenox Moon Drills requiring both *Fuel Blocks (120/day)* and *Magmatic Gas (4,800/day)*  
- **Limiting Factor Detection** — Automatically determines which resource will deplete first  
- **Magmatic Gas Reserve Tracking** — Tracks magmatic gas stored in corporation hangars across all structures  
- **Enhanced Critical Alerts** — New **purple "LIMITING" badges** highlight which resource needs immediate attention  
- **Dual Fuel Projections** — Separate projections for fuel blocks and magmatic gas on detail pages  
- **Improved Logistics Planning** — Gas requirements are now included in hauling plans and logistics reports  
- **Visual Indicators** — Purple badges and icons throughout the interface for gas-related information  

---

## 🧩 Enhancements
- Updated structure detail views with dual-fuel display and limiting factor visibility  
- Improved dark theme compatibility for new components  
- Expanded analytics to include magmatic gas consumption data  

---

📖 **[View full changelog on GitHub Wiki →](https://github.com/MattFalahe/Structure-Manager/wiki/Changelog)**

---

## Usage

### Dashboard Overview
Access Structure Manager from the main SeAT sidebar. The main dashboard shows:
- All corporation structures with fuel status
- Color-coded alerts (Red: Critical, Yellow: Warning, Green: Normal)
- Real-time consumption rates
- Days remaining until refuel needed

### Fuel Status View
Monitor all your structures in one place:
- Filter by corporation and fuel status
- See active services and their fuel consumption
- View estimated blocks remaining
- Track hourly/daily/weekly consumption rates

### Fuel Reserves Management
Track staged fuel blocks across your structures:
- View reserves by system and structure
- See which CorpSAG divisions contain fuel
- Monitor reserve movements and refuel events
- Get recommendations for moving reserves to fuel bay

### Critical Alerts
Stay on top of urgent fuel needs:
- Structures with less than 14 days of fuel
- Sorted by urgency (most critical first)
- Quick-view fuel requirements
- One-click navigation to structure details

### Logistics Report
Plan your fuel hauling operations:
- Fuel requirements grouped by solar system
- 30/60/90-day projections for each structure
- Total volume and hauler trip calculations
- Export data for external planning tools

### Structure Details
Deep dive into individual structure fuel data:
- Complete fuel history with consumption graphs
- Service module breakdown with consumption rates
- Fuel projections and refuel recommendations
- Historical analysis with refuel event timeline
- Reserve status and movement tracking

## EVE Online Fuel Mechanics

### Service Module Consumption Rates

#### Citadel Services
- **Clone Bay**: 10 blocks/hour base, 7.5/hour on Citadels (-25%)
- **Market Hub**: 40 blocks/hour base, 30/hour on Citadels (-25%)

#### Engineering Complex Services
- **Manufacturing/Research/Invention**: 12 blocks/hour base, 9/hour on Engineering (-25%)
- **Capital Shipyard**: 24 blocks/hour base, 18/hour on Engineering (-25%)
- **Supercapital Shipyard**: 36 blocks/hour base, 27/hour on Engineering (-25%)

#### Refinery Services
- **Reprocessing**: 10 blocks/hour base, 8/hour on Athanor (-20%), 7.5/hour on Tatara (-25%)
- **Moon Drill**: 5 blocks/hour (120/day) - **NO BONUSES** on any structure
- **Reactions (All Types)**: 15 blocks/hour base, 12/hour on Athanor (-20%), 11.25/hour on Tatara (-25%)

#### Navigation Structures
- **Ansiblex Jump Gate**: 30 blocks/hour
- **Pharolux Cyno Beacon**: 15 blocks/hour
- **Tenebrex Cyno Jammer**: 40 blocks/hour

### Important Notes
- Upwell structures themselves consume **ZERO fuel**
- Only **online service modules** consume fuel blocks
- **One module = one fuel cost** (even if a module provides multiple services)
- Fuel reduction bonuses apply **ONLY** to Reprocessing and Reaction services
- Moon Drills **ALWAYS** use 120 blocks/day regardless of structure type

## Permissions

Structure Manager uses SeAT's permission system:

- `structure-manager.view`: View structure fuel data
- `structure-manager.admin`: Administrative functions
- `structure-manager.export`: Export logistics reports

Assign permissions via SeAT's Settings → Access Management.

## Automated Tasks

The plugin runs three scheduled jobs:

1. **Fuel Tracking** (`structure-manager:track-fuel`)
   - Runs hourly at :15 past the hour
   - Tracks fuel bay levels and consumption
   - Detects refuel events

2. **Consumption Analysis** (`structure-manager:analyze-consumption`)
   - Runs hourly at :30 past the hour
   - Analyzes consumption patterns
   - Generates recommendations

3. **History Cleanup** (`structure-manager:cleanup-history`)
   - Runs daily at 3:00 AM
   - Removes old history records (6+ months)
   - Keeps reserve data for 3 months

### Manual Commands

```bash
# Track fuel for all structures
php artisan structure-manager:track-fuel

# Analyze consumption for specific structure
php artisan structure-manager:analyze-consumption --structure=STRUCTURE_ID

# Analyze by corporation
php artisan structure-manager:analyze-consumption --corporation=CORP_ID

# Clean up old history
php artisan structure-manager:cleanup-history --days=180
```

## Requirements

- SeAT 5.x
- PHP 8.1 or higher
- MySQL/MariaDB
- Corporation structures tracked by SeAT
- Corporation asset data (for fuel bay and reserve tracking)

## Troubleshooting

### No fuel data showing
1. Ensure your corporation's ESI tokens have the required scopes
2. Wait for SeAT to complete initial corporation asset sync
3. Check that structures have fuel_expires timestamps
4. Run `php artisan structure-manager:track-fuel` manually

### Inaccurate consumption rates
1. Allow 24-48 hours for initial data collection
2. Ensure services are properly online in game
3. Check for recent service changes or structure states
4. Verify fuel blocks are tracked in corporation assets

### Reserves not detected
1. Fuel must be in structure's CorpSAG hangars (1-7)
2. Or in Office containers inside the structure
3. Must be one of the four fuel block types (4051, 4246, 4247, 4312)
4. Corporation asset data must be up to date

## Support & Contributing

- **Issues**: [GitHub Issues](https://github.com/MattFalahe/structure-manager/issues)
- **Discussions**: [GitHub Discussions](https://github.com/MattFalahe/structure-manager/discussions)
- **Wiki**: [Full documentation and changelog](https://github.com/MattFalahe/structure-manager/wiki)
- **Pull Requests**: Always welcome!

## License

This project is licensed under the GNU General Public License v2.0 - see the [LICENSE](LICENSE) file for details.

This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

## Credits

**Author**: Matt Falahe  
**Version**: 1.0.6  
**SeAT Compatibility**: 5.x

Built for the EVE Online community. Special thanks to the SeAT development team and all contributors.

---

*EVE Online and the EVE logo are the registered trademarks of CCP hf. All rights are reserved worldwide. All other trademarks are the property of their respective owners. EVE Online, the EVE logo, EVE and all associated logos and designs are the intellectual property relating to these trademarks are likewise the intellectual property of CCP hf. All artwork, screenshots, characters, vehicles, storylines, world facts or other recognizable features of the intellectual property relating to these trademarks are likewise the intellectual property of CCP hf.*

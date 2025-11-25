# Changelog

Below is a summary of all major updates, improvements, and fixes made to **Structure Manager**.
Each version entry lists key changes for easier reference and upgrade planning.

---

## 🆕 Version 1.0.10 — *November 2025*

### Added
- Multiple webhook support with per-webhook configuration (corporation filters, location filters, role mentions)
- Zero strontium behavior handling for POSes (proper critical alerts and status detection)
- Status-based POS notifications (good → warning → critical transitions with optional reminders)
- Final 1-hour alert for POSes before going offline
- `simulate-consumption` command for rapid fuel consumption testing
- Webhook test button in settings for connectivity verification
- Enhanced reserve tracking with nested Office container support

### Changed
- Renamed "Recent Refuel Events" section to "Fuel Withdrawals" for clarity
- Fuel Withdrawals now only shows Upwell structures (excludes POS and Metenox)
- Enhanced refuel event detection logic
- Updated help documentation with corrected command schedules
- `track-fuel` command now documented as handling both fuel bays and reserves
- `analyze-consumption` command now includes `--structure` and `--corporation` options

### Fixed
- POS and Metenox entries incorrectly appearing in Fuel Withdrawals section
- Incorrect command schedules in help documentation (analyze-consumption)
- Duplicate "Track Fuel Reserves" section in help documentation
- Zero strontium alert triggers not working correctly

### Documented
- Added missing `cleanup-history` command documentation
- Added missing `simulate-consumption` command documentation
- Removed confusing duplicate command sections
- Added command options and usage examples throughout
- Corrected all command schedules (hourly at :15/:30, every 10 minutes, etc.)

---

## Version 1.0.9 — *November 2025*

### 🐛 Bug Fixes

**Fixed POS State Recognition and Critical Alerts Filtering**

**Issue:** POSes were not appearing correctly in critical alerts and notifications regardless of their state.

**What's Fixed:**

- **Fixed POS state recognition** - Plugin now correctly identifies POS states (unanchored, offline, onlining, reinforced, online, unanchoring)

- **Critical alerts page filtering** - Critical alerts page will now only show POSes that are **online** or **reinforced** and actively consuming fuel. POSes in other states (offline, unanchored, etc.) are excluded from alerts as they do not consume fuel.

- **Notification filtering** - Discord/Slack notifications will now only be sent for POSes in **online** or **reinforced** states. Any other state will be ignored.

- **Added POS state badge** - Control tower list now displays a badge showing the current state of each POS for better visibility.

- **Added state validation checks** - Enhanced validation to ensure state recognition is accurate throughout the plugin.

**State Behavior:**
- **Online (state 4)** - POS is operational and consuming fuel → Alerts enabled
- **Reinforced (state 3)** - POS is in reinforced mode and consuming fuel → Alerts enabled
- **Offline (state 1)** - POS is anchored but offline → Alerts disabled (no fuel consumption)
- **Unanchored (state 0)** - POS is not deployed → Alerts disabled
- **Onlining (state 2)** - POS is coming online → Alerts disabled
- **Unanchoring (state 5)** - POS is being unanchored → Alerts disabled

---

## Version 1.0.8 — *November 2025*

### 🎉 Major Features

#### Legacy Player Owned Starbases (POS) Support
Complete fuel management system for legacy Player Owned Starbases (Control Towers), bringing the same comprehensive tracking capabilities to POS towers that Upwell structures already enjoy.

**Multi-Resource Tracking**
- **Fuel Blocks**: Full consumption tracking based on tower size (Small: 10/hr, Medium: 20/hr, Large: 40/hr)
- **Strontium Clathrates**: Defensive resource monitoring with reinforcement timer calculations
- **Starbase Charters**: Automatic detection and tracking for high-security space (1/hour consumption)
- **Faction & Officer Towers**: Full support for fuel efficiency bonuses

**Smart Security Space Detection**
- Automatically detects system security level (High-Sec, Low-Sec, Null-Sec, Wormhole)
- Enables charter tracking only in high-security space
- Handles both sovereign and NPC null-sec correctly
- No manual configuration required

**Limiting Factor Detection**
- Identifies which resource (fuel, strontium, or charters) will run out first
- Visual `[LIMITING FACTOR]` badges highlight priority resources
- Helps optimize hauling operations and prioritize refueling efforts
- Separate status tracking for each resource type

**Real-Time Monitoring**
- POS fuel tracking every 10 minutes (vs 1 hour for Upwell structures)
- Daily consumption analysis at 01:00 AM
- 90-day historical data retention
- Automatic refuel event detection

**Comprehensive Dashboard**
- Dedicated "Control Towers" page with filterable table
- Color-coded status indicators (Critical/Warning/Normal/Good)
- Multiple resource display (fuel, strontium, charters) in single view
- Days/hours remaining calculations for each resource
- Corporation filtering for multi-corp management
- System security status display

**Detailed POS Pages**
- Individual detail pages for each POS with full analytics
- Separate resource cards for fuel blocks, strontium, and charters
- Historical consumption graphs (90 days)
- Refuel event timeline
- Reserve status from corporation hangars
- Fuel projections (7, 14, 30, 60, 90 days)
- Critical alerts with actionable information

#### Discord & Slack Webhook Notifications
Advanced notification system for POS fuel alerts with intelligent status-based alerting and customizable intervals.

**Rich Webhook Integration**
- Discord and Slack webhook support
- Color-coded embeds (Red: Critical, Yellow: Warning)
- Detailed resource information in structured format
- System location, security status, and corporation details
- Limiting factor emphasis in notifications
- Customizable webhook avatar (uses your webhook configuration)

**Smart Notification System**
- **Status-Based Alerts**: Notifications sent on status transitions (Good → Warning → Critical)
- **Critical Stage Reminders**: Optional interval reminders during critical stage
  - Fuel/Charters: Default 6 hours (configurable 1-24 hours, or disable with 0)
  - Strontium: Default 2 hours (configurable 1-12 hours, or disable with 0)
- **Final Alert**: Urgent notification sent exactly 1 hour before POS goes offline
- **Independent Tracking**: Fuel and strontium alerts tracked separately per POS
- **Spam Prevention**: Status flags reset automatically when POS returns to good status

**Configurable Thresholds**
- **Fuel Blocks**: Critical < 7 days, Warning < 14 days (customizable)
- **Strontium Clathrates**: Critical < 6 hours, Warning < 12 hours, Good < 24 hours (customizable)
- **Starbase Charters**: Critical < 7 days, Warning < 14 days (customizable)
- Validation ensures critical thresholds are always less than warning thresholds

**Discord Role Mentions**
- Optional role mentions for critical alerts (@role support)
- Easy role ID configuration with helper instructions
- Developer mode integration guide in documentation

**Notification Testing**
- Built-in webhook test button in Settings
- Immediate feedback on webhook connectivity
- Test message with sample embed format

#### Settings Page
Brand new Settings page providing centralized configuration for all plugin features.

**Notification Settings Tab**
- Enable/disable POS webhook notifications
- Webhook URL configuration (Discord/Slack)
- Discord role mention field
- Critical stage reminder intervals (fuel and strontium)
- Test webhook button for connectivity verification

**Threshold Settings Tab**
- Customizable alert thresholds for all resource types
- Fuel blocks: Critical and Warning day thresholds
- Strontium: Critical, Warning, and Good hour thresholds
- Starbase charters: Critical day threshold
- Real-time validation of threshold relationships

**Reserves Tracking Settings Tab**
- Corporate hangar exclusion selector (Hangars 1-7)
- Visual checkbox interface (Checked = Tracked, Unchecked = Excluded)
- Applies to both Upwell structures AND POSes
- Useful for excluding hangars used for:
  - Market trading operations
  - Logistics staging areas
  - Personal storage
  - Contract fulfillment

**Settings Management**
- Default values restoration button
- Form validation with helpful error messages
- Cache clearing on settings updates
- Persistent configuration across sessions

#### Help & Documentation Page
Comprehensive, searchable documentation system replacing the old About page.

**Complete Coverage**
- 9 major documentation sections with navigation sidebar
- Plugin overview and key features
- Getting started guide with installation steps
- Detailed feature explanations:
  - Fuel mechanics for Upwell structures
  - Metenox Moon Drill dual-fuel system
  - POS management and resource types
  - Notification system setup
  - Settings configuration
  - Pages guide (all plugin pages explained)
- Artisan commands reference with examples
- 15 FAQ entries covering common questions
- 6 troubleshooting issues with solutions

**Interactive Features**
- Live search functionality across all documentation
- Smooth navigation with URL hash support
- Collapsible FAQ accordion
- Direct links to plugin pages
- GitHub repository integration
- Plugin information sidebar with version, license, changelog links

**User-Friendly Design**
- Clean, modern interface with icons
- Color-coded sections for easy scanning
- Code examples with syntax highlighting
- Step-by-step instructions
- Visual indicators and badges explained
- Pro tips and best practices

**Comprehensive Troubleshooting**
- Issue 1: Structures Not Appearing
- Issue 2: Incorrect Fuel Consumption Numbers
- Issue 3: Missing Reserve Data
- Issue 4: Metenox Dual-Fuel Not Showing
- Issue 5: Charts Not Loading on Detail Page
- Issue 6: POS Notifications Not Working (NEW)

### ✨ Improvements

#### Navigation & Organization
- **Renamed Page**: "Fuel Status" → "Upwell Structures" for clarity
- **New Navigation Tab**: "Control Towers" for POS management
- **New Navigation Tab**: "Settings" for centralized configuration
- **Improved Menu Structure**: Logical grouping of structure types
- **Page Descriptions**: Clear labels distinguish Upwell vs POS structures

#### Reserve Tracking Enhancements
- **Hangar Exclusion**: Selective tracking of corporation hangars
- **Flexible Configuration**: Include/exclude hangars per operational needs
- **Cross-Platform**: Same exclusion rules apply to Upwell and POS reserves
- **User Control**: Easy checkbox interface in Settings
- **Documentation**: Clear explanation of exclusion use cases

#### User Experience
- **Consistent Interface**: POS pages match Upwell structure design language
- **Better Filtering**: Enhanced corporation and status filters
- **Improved Tables**: Better column organization and responsive design
- **Status Indicators**: Unified color scheme across all pages
- **Help Integration**: Context-sensitive help links throughout interface

### 🔧 Technical Changes

#### Database Schema
- **New Tables**:
  - `starbase_fuel_history`: POS fuel tracking (90-day retention)
  - `starbase_fuel_consumption`: POS consumption analytics
  - `starbase_fuel_reserves`: POS hangar reserve tracking
  - `structure_manager_settings`: Plugin configuration storage
- **New Columns**:
  - Added POS notification tracking fields to `corporation_starbases`
  - Added reserve tracking settings fields
  - Added notification status timestamps

#### New Artisan Commands
- `structure-manager:track-poses-fuel`: POS fuel tracking (runs every 10 minutes)
- `structure-manager:analyze-pos-consumption`: POS consumption analysis (runs daily)
- `structure-manager:notify-pos-fuel`: POS notification dispatcher (runs every 10 minutes)

#### Job Scheduling
- POS tracking: Every 10 minutes for real-time data
- POS analysis: Daily at 01:00 AM
- POS notifications: Every 10 minutes with intelligent cooldowns
- Upwell structures: Maintained at 1 hour tracking, 30-minute analysis

#### New Controllers
- `PosManagerController`: Handles POS dashboard and detail pages
- `SettingsController`: Manages plugin configuration with validation
- `FuelAlertController`: Enhanced for POS notification support

#### New Models
- `StarbaseFuelHistory`: POS fuel history tracking
- `StarbaseFuelConsumption`: POS consumption analytics
- `StarbaseFuelReserves`: POS hangar reserves
- `StructureManagerSettings`: Configuration management with caching

#### Helpers & Services
- `PosFuelCalculator`: Tower size, security space, and consumption calculations
- Enhanced `FuelConsumptionTracker`: Support for POS resource tracking
- Settings validation and sanitization service

### 📊 Performance & Data

#### Tracking Intervals
- **POS Tracking**: 10-minute intervals (6x per hour)
- **POS Analysis**: Daily comprehensive analysis
- **POS Notifications**: 10-minute check intervals
- **Upwell Tracking**: Maintained at 1-hour intervals
- **Upwell Analysis**: Maintained at 30-minute intervals
- **Reserve Tracking**: Hourly for all structure types

#### Data Retention
- **POS History**: 90 days (updated more frequently than Upwell)
- **Upwell History**: 6 months (unchanged)
- **Reserve History**: 3 months for all types (unchanged)
- **Consumption Data**: Matches respective history retention

#### Cache Management
- Settings cached for performance
- Automatic cache invalidation on updates
- Reserve exclusion query optimization

### 🐛 Bug Fixes

#### Notification System
- Fixed POS notification interval calculations
- Resolved status tracking field preservation in TrackPosesFuel job
- Corrected notification cooldown logic for status-based alerts
- Fixed critical stage reminder interval application
- Resolved final alert (1-hour) timing issues

#### UI/UX
- Fixed CSS compatibility issues across SeAT themes (default/jet)
- Resolved blade template structural issues causing help page errors
- Fixed responsive design issues on mobile devices
- Corrected color scheme inconsistencies

### 📝 Documentation Updates

#### New Documentation
- Complete POS management guide (installation, configuration, usage)
- Discord/Slack webhook setup instructions
- Settings page comprehensive documentation
- Notification system detailed explanation
- Hangar exclusion feature documentation
- POS troubleshooting guide (Issue #6)

#### Enhanced Documentation
- Updated FAQ with 5 new POS-related questions (Q12-Q15)
- Expanded command reference with POS commands
- Added notification system best practices
- Enhanced troubleshooting section
- Updated screenshots for new interface

### 🔄 Migration Notes

#### Automatic Migrations
All database changes are handled automatically via migrations. No manual intervention required.

#### Settings Migration
- Default settings are created automatically on first access
- Existing installations: Settings page will use defaults until customized
- No breaking changes to existing Upwell structure tracking

#### Data Compatibility
- Existing Upwell structure data remains unchanged
- POS data is tracked independently
- Reserve tracking respects new exclusion settings
- No data loss or corruption

### ⚠️ Breaking Changes

**None.** This release is fully backward compatible with previous versions.

### 🔮 Known Issues & Future Improvements

#### Known Limitations
- Webhook notifications currently support POSes only
- Upwell structure webhook notifications planned for v1.1.0+
- Hangar exclusion applies globally (cannot exclude per-structure)

---

# **Previous Updates / Related Enhancements**

## Version 1.0.7 — *October 2025*

**🧩 Maintenance & Fixes — *Refinements and Stability Improvements*** 

This update focuses on **cleaning up internal logic**, **removing redundant setup steps**, and **improving compatibility** with development environments.  
It doesn’t introduce new user-facing features, but it makes the plugin leaner, simpler, and easier to maintain.  

**🔧 Key Improvements**  
- **Simplified Schedule Seeder** — Now uses SeAT’s built-in service class instead of a custom implementation  
- **Removed Unnecessary Setup Command** — `structure-manager:setup` is no longer needed; registration is handled automatically  
- **Improved HTTPS Handling** — Fixed an issue where the plugin would force HTTPS and break local (HTTP) development environments  

**🤝 Contribution**

Special thanks to **@recursivetree** for identifying issues, refining setup flow, and improving plugin compatibility.  

---

## Version 1.0.6 — *October 2025*

**🌕 New Features — Metenox Moon Drill Support**

This update introduces full dual-fuel support for the new Metenox Moon Drills, expanding the plugin’s capabilities to track both Fuel Blocks and Magmatic Gas simultaneously.

**🔧 Key Additions**

* Dual Fuel System Tracking — Complete support for Metenox Moon Drills requiring both Fuel Blocks (120/day) and Magmatic Gas (4,800/day)  
* Limiting Factor Detection — Automatically determines which resource will deplete first  
* Magmatic Gas Reserve Tracking — Tracks magmatic gas stored in corporation hangars across all structures  
* Enhanced Critical Alerts — New purple "LIMITING" badges highlight which resource needs immediate attention  
* Dual Fuel Projections — Separate projections for fuel blocks and magmatic gas on detail pages  
* Improved Logistics Planning — Gas requirements are now included in hauling plans and logistics reports  
* Visual Indicators — Purple badges and icons throughout the interface for gas-related information

**🧩 Enhancements**

* Updated structure detail views with dual-fuel display and limiting factor visibility  
* Improved dark theme compatibility for new components  
* Expanded analytics to include magmatic gas consumption data

---

## Version 1.0.5 — *October 2025*

**Enhanced Structure Detail Page / Comprehensive Fuel Dashboard**

* Detailed consumption breakdown cards showing hourly, daily, weekly, monthly, and quarterly rates  
* Improved fuel projections with estimated blocks remaining, volume in m³, and precise time calculations  
* Historical analysis integration displaying fuel bay snapshot data and refuel event detection  
* Consumption anomaly alerts to identify potential service changes

**Visual Improvements**

* Enhanced dark theme support with better contrast and improved color palette  
* Service status indicators with clear online/offline visual display  
* Modern chart visualization with dark-themed gradients and improved tooltips  
* Better event tracking in fuel history table with change indicators and event badges

**User Experience**

* Clearer explanation of service-based calculations  
* Real-time consumption updates when services change  
* Better mobile responsiveness  
* Improved data presentation and readability

---

## Version 1.0.4 — *October 2025*

**Critical Bug Fix**

* **Fixed fuel consumption calculation for multi-service modules**
  * Previously calculated fuel consumption per service instead of per module
  * Structures with modules providing multiple services showed incorrect consumption rates

**Example Issue (Now Fixed)**

* **Standup Research Lab I** provides 3 services:
  * Blueprint Copying
  * Material Efficiency Research
  * Time Efficiency Research
* **OLD BUG**: Calculated as 3 separate modules = 27 blocks/hour ❌
* **NOW FIXED**: Correctly calculated as 1 module = 9 blocks/hour ✅

**Impact Examples**

* **Raitaru** with Research Lab + Invention Lab:
  * Before: ~36 blocks/hour (incorrect) ❌
  * After: **18 blocks/hour** (correct) ✅
* **Azbel** with Manufacturing Plant + Capital Shipyard:
  * Before: ~18 blocks/hour (incorrect) ❌
  * After: **27 blocks/hour** (correct) ✅

**Technical Changes**

* Added `SERVICE_TO_MODULE_MAP` constant mapping services to their source modules  
* Implemented proper module grouping to count each unique module only once  
* Updated `calculateFromActiveServices()` to group services by module before calculating fuel  
* Service names now use exact case-sensitive matching from EVE API

This fix ensures **accurate fuel consumption calculations** for all structures, especially those using Research Labs.

---

## Version 1.0.3 — *October 2025*

**Fixes**

* Fixed migration issue.

---

## Version 1.0.2 — *October 2025*

**Highlights**

* Added **Reserve Tracking** for corporation hangars and containers  
* Implemented **Automated Consumption Analysis** with hourly updates  
* Improved dashboard visuals and responsiveness  
* Added permissions: `structure-manager.view`, `structure-manager.admin`, `structure-manager.export`  
* Refined structure grouping and improved query efficiency  
* Minor bug fixes and ESI error handling improvements

---

## Version 1.0.1 — *October 2025*

**New Features**

* Introduced **Fuel Usage Analytics** with time-remaining prediction  
* Added manual Artisan commands for testing and diagnostics  
* Included full SeAT permission integration for granular role control  
* Added **automatic scheduling** of hourly and daily jobs (no cron setup needed)

**Fixes**

* Fixed inaccurate `fuel_expires` timestamps for newly anchored structures  
* Resolved caching issue causing delayed dashboard updates

---

## 🚀 Version 1.0.0 — *October 2025*

**Initial Release**

* Core structure tracking with automatic hourly fuel updates  
* Integration with SeAT’s job system and corporation asset sync  
* Dynamic dashboard showing fuel levels, time to depletion, and last refuel events  
* Configurable through SeAT’s permission system  
* Full support for multi-corporation SeAT installations

---

## 🔧 Development Notes

* Back-end processes are optimized for minimal performance impact  
* ESI polling is staggered to avoid hitting rate limits  
* History cleanup runs daily to maintain performance and data freshness

---

## 📦 Upcoming Features (Planned)

* Low-fuel alerts via Discord or SeAT notifications  
* Low stock reserves notifications  
* Alliance-level structure overview  
* Configurable data retention period via UI settings  
* POS support

---

For the latest updates, issues, or feature requests:  
👉 [github.com/MattFalahe/structure-manager](https://github.com/MattFalahe/structure-manager)

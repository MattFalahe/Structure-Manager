# Structure Manager Plugin for SeAT

A comprehensive fuel management plugin for EVE Online corporation structures in SeAT.

## Features

- **Real-time Fuel Monitoring**: Track fuel levels across all corporation structures
- **Consumption Analytics**: Calculate daily, weekly, monthly, and quarterly fuel consumption
- **Visual Indicators**: Color-coded fuel status (Critical, Warning, Normal, Good)
- **Service Tracking**: Monitor online services and their fuel impact
- **Historical Data**: Track fuel consumption patterns over time
- **Fuel Projections**: Estimate when structures will run out of fuel
- **Multi-Corporation Support**: Filter and view structures by corporation
- **Detailed Reports**: Export fuel consumption data for logistics planning

## Installation

1. Navigate to your SeAT installation directory
2. Create the plugin directory:
   ```bash
   mkdir -p seat-plugins/structure-manager
   ```
3. Copy the plugin files to the directory
4. Run migrations:
   ```bash
   php artisan migrate
   ```
5. Clear cache:
   ```bash
   php artisan cache:clear
   ```
6. Restart your queue workers

## Configuration

The plugin configuration file will be published to `config/structure-manager.config.php` where you can adjust:
- Fuel warning thresholds
- Consumption calculation methods
- Display preferences

## Permissions

The plugin adds two permissions:
- `structure-manager.view`: View structure fuel information
- `structure-manager.admin`: Administer structure manager settings

## Usage

1. Navigate to the Structure Manager from the main menu
2. Use filters to view specific corporations or fuel statuses
3. Click on a structure name for detailed information
4. View consumption charts and projections
5. Export data for logistics planning

## Fuel Consumption Tracking

The plugin tracks fuel consumption by:
1. Recording snapshots of fuel_expires timestamps
2. Calculating consumption based on timestamp changes
3. Providing estimates based on structure type and services

## Support

For issues or feature requests, please open an issue on the GitHub repository.

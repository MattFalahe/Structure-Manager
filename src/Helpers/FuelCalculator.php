<?php

namespace StructureManager\Helpers;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FuelCalculator
{
    /**
     * Structure type fuel consumption rates (blocks per hour)
     * Based on actual EVE Online consumption rates
     */
    const FUEL_RATES = [
        // Engineering Complexes
        35825 => 4,  // Raitaru - 96 blocks/day
        35826 => 12, // Azbel - 288 blocks/day  
        35827 => 24, // Sotiyo - 576 blocks/day
        
        // Citadels
        35832 => 4,  // Astrahus - 96 blocks/day
        35833 => 12, // Fortizar - 288 blocks/day
        35834 => 24, // Keepstar - 576 blocks/day
        
        // Refineries
        35835 => 5,  // Athanor - 120 blocks/day
        35836 => 20, // Tatara - 480 blocks/day
    ];
    
    /**
     * Fuel block types from EVE
     */
    const FUEL_BLOCKS = [
        4051 => 'Nitrogen Fuel Block',
        4246 => 'Hydrogen Fuel Block',
        4247 => 'Helium Fuel Block',
        4312 => 'Oxygen Fuel Block',
    ];
    
    /**
     * Calculate estimated fuel blocks remaining
     */
    public static function calculateBlocksRemaining($structureTypeId, $daysRemaining)
    {
        $hourlyRate = self::FUEL_RATES[$structureTypeId] ?? 10; // Default 10 blocks/hour
        return $daysRemaining * 24 * $hourlyRate;
    }
    
    /**
     * Get service fuel modifiers
     */
    public static function getServiceModifier($services)
    {
        $modifier = 1.0;
        
        foreach ($services as $service) {
            if (strpos($service->name, 'Manufacturing') !== false) {
                $modifier += 0.1;
            } elseif (strpos($service->name, 'Research') !== false) {
                $modifier += 0.05;
            } elseif (strpos($service->name, 'Market') !== false) {
                $modifier += 0.05;
            } elseif (strpos($service->name, 'Cloning') !== false) {
                $modifier += 0.15;
            }
        }
        
        return $modifier;
    }
    
    /**
     * Parse fuel notification YAML text
     */
    public static function parseFuelNotification($yamlText)
    {
        // Parse YAML-like structure from notification
        $lines = explode("\n", $yamlText);
        $data = [];
        
        foreach ($lines as $line) {
            if (preg_match('/^(\w+):\s*(.*)$/', $line, $matches)) {
                $data[$matches[1]] = $matches[2];
            } elseif (preg_match('/^- - (\d+)/', $line, $matches)) {
                // Fuel quantity
                $data['fuel_quantity'] = (int)$matches[1];
            } elseif (preg_match('/^  - (\d+)/', $line, $matches)) {
                // Fuel type ID
                $data['fuel_type_id'] = (int)$matches[1];
            }
        }
        
        return $data;
    }
    
    public static function getFuelRequirement($structureTypeId, $period = 'daily')
    {
        $hourlyRate = self::FUEL_RATES[$structureTypeId] ?? 10;
        
        switch ($period) {
            case 'hourly':
                return $hourlyRate;
            case 'daily':
                return $hourlyRate * 24;
            case 'weekly':
                return $hourlyRate * 24 * 7;
            case 'monthly':
                return $hourlyRate * 24 * 30;
            case 'quarterly':
                return $hourlyRate * 24 * 90;
            default:
                return $hourlyRate * 24;
        }
    }
}

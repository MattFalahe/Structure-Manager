<?php

namespace StructureManager\Helpers;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FuelCalculator
{
    /**
     * Structure type fuel consumption rates (blocks per hour)
     */
    const FUEL_RATES = [
        // Citadels
        35825 => 4,  // Raitaru
        35826 => 12, // Azbel
        35827 => 24, // Sotiyo
        
        // Engineering Complexes
        35832 => 4,  // Astrahus
        35833 => 12, // Fortizar
        35834 => 24, // Keepstar
        
        // Refineries
        35835 => 5,  // Athanor
        35836 => 20, // Tatara
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
}

<?php

namespace StructureManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Model for Structure Manager settings
 * 
 * Provides easy access to configuration values with caching
 */
class StructureManagerSettings extends Model
{
    protected $table = 'structure_manager_settings';
    
    protected $fillable = [
        'key',
        'value',
        'type',
        'category',
        'description',
    ];
    
    protected $casts = [
        'value' => 'string',
    ];
    
    /**
     * Get a setting value by key
     * 
     * @param string $key Setting key
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public static function get($key, $default = null)
    {
        $cacheKey = "structure_manager_setting_{$key}";
        
        return Cache::remember($cacheKey, 3600, function() use ($key, $default) {
            $setting = self::where('key', $key)->first();
            
            if (!$setting) {
                return $default;
            }
            
            // Cast to appropriate type
            return self::castValue($setting->value, $setting->type);
        });
    }
    
    /**
     * Set a setting value
     * 
     * @param string $key Setting key
     * @param mixed $value Setting value
     * @param string $type Value type (auto-detected if null)
     * @param string $category Category (default: general)
     * @return bool
     */
    public static function set($key, $value, $type = null, $category = 'general')
    {
        $setting = self::where('key', $key)->first();
        
        // Auto-create setting if it doesn't exist
        if (!$setting) {
            // Auto-detect type if not provided
            if ($type === null) {
                $type = self::detectType($value);
            }
            
            $setting = self::create([
                'key' => $key,
                'value' => self::valueToString($value, $type),
                'type' => $type,
                'category' => $category,
            ]);
            
            // Clear cache
            Cache::forget("structure_manager_setting_{$key}");
            
            return true;
        }
        
        // Convert value to string for storage
        $stringValue = self::valueToString($value, $setting->type);
        
        $setting->value = $stringValue;
        $result = $setting->save();
        
        // Clear cache
        Cache::forget("structure_manager_setting_{$key}");
        
        return $result;
    }
    
    /**
     * Auto-detect value type
     * 
     * @param mixed $value
     * @return string
     */
    private static function detectType($value)
    {
        if (is_bool($value)) {
            return 'boolean';
        }
        if (is_int($value)) {
            return 'integer';
        }
        if (is_float($value)) {
            return 'float';
        }
        if (is_array($value)) {
            return 'json';
        }
        return 'string';
    }
    
    /**
     * Get all settings by category
     * 
     * @param string $category
     * @return \Illuminate\Support\Collection
     */
    public static function getByCategory($category)
    {
        return self::where('category', $category)->get()->map(function($setting) {
            $setting->value = self::castValue($setting->value, $setting->type);
            return $setting;
        });
    }
    
    /**
     * Cast value to appropriate type
     * 
     * @param string $value
     * @param string $type
     * @return mixed
     */
    private static function castValue($value, $type)
    {
        if ($value === null) {
            return null;
        }
        
        switch ($type) {
            case 'integer':
                return (int) $value;
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'json':
                return json_decode($value, true);
            case 'float':
                return (float) $value;
            default:
                return $value;
        }
    }
    
    /**
     * Convert value to string for storage
     * 
     * @param mixed $value
     * @param string $type
     * @return string
     */
    private static function valueToString($value, $type)
    {
        if ($value === null) {
            return null;
        }
        
        switch ($type) {
            case 'boolean':
                return $value ? '1' : '0';
            case 'json':
                return json_encode($value);
            default:
                return (string) $value;
        }
    }
    
    /**
     * Clear all settings cache
     */
    public static function clearCache()
    {
        $settings = self::all();
        foreach ($settings as $setting) {
            Cache::forget("structure_manager_setting_{$setting->key}");
        }
    }
}

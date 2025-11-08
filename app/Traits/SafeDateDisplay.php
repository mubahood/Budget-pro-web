<?php

namespace App\Traits;

use Carbon\Carbon;

/**
 * SafeDateDisplay Trait
 * 
 * Provides safe date handling methods for Encore Admin controllers
 * to prevent "Call to member function on string" errors when
 * dates are not properly cast to Carbon instances.
 */
trait SafeDateDisplay
{
    /**
     * Safely convert a date value to Carbon instance
     * 
     * @param mixed $date The date value (string, Carbon, or null)
     * @return Carbon|null
     */
    protected function toCarbon($date)
    {
        if (!$date) {
            return null;
        }
        
        if ($date instanceof Carbon) {
            return $date;
        }
        
        try {
            return Carbon::parse($date);
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Format date for display with human-readable difference
     * 
     * @param mixed $date The date value
     * @param string $format The date format (default: 'd M Y, h:i A')
     * @param string $nullText Text to display if date is null (default: 'N/A')
     * @return string
     */
    protected function formatDateWithHuman($date, $format = 'd M Y, h:i A', $nullText = 'N/A')
    {
        $carbon = $this->toCarbon($date);
        
        if (!$carbon) {
            return $nullText;
        }
        
        return $carbon->format($format) . ' (' . $carbon->diffForHumans() . ')';
    }
    
    /**
     * Format date for display
     * 
     * @param mixed $date The date value
     * @param string $format The date format (default: 'd M Y, h:i A')
     * @param string $nullText Text to display if date is null (default: 'N/A')
     * @return string
     */
    protected function formatDate($date, $format = 'd M Y, h:i A', $nullText = 'N/A')
    {
        $carbon = $this->toCarbon($date);
        
        if (!$carbon) {
            return $nullText;
        }
        
        return $carbon->format($format);
    }
    
    /**
     * Get human-readable time difference
     * 
     * @param mixed $date The date value
     * @param string $nullText Text to display if date is null (default: 'N/A')
     * @return string
     */
    protected function humanDate($date, $nullText = 'N/A')
    {
        $carbon = $this->toCarbon($date);
        
        if (!$carbon) {
            return $nullText;
        }
        
        return $carbon->diffForHumans();
    }
}

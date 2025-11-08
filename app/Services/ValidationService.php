<?php

namespace App\Services;

use Illuminate\Support\Str;

class ValidationService
{
    /**
     * Sanitize input to prevent SQL injection and XSS attacks
     *
     * @param mixed $input
     * @return mixed
     */
    public static function sanitize($input)
    {
        if (is_array($input)) {
            return array_map([self::class, 'sanitize'], $input);
        }

        if (!is_string($input)) {
            return $input;
        }

        // Remove SQL injection patterns
        $input = self::removeSqlInjectionPatterns($input);

        // Remove XSS attacks
        $input = self::removeXss($input);

        return $input;
    }

    /**
     * Remove SQL injection patterns
     *
     * @param string $input
     * @return string
     */
    protected static function removeSqlInjectionPatterns($input)
    {
        // Patterns that indicate SQL injection attempts
        $patterns = [
            '/(\bUNION\b.*\bSELECT\b)/i',
            '/(\bSELECT\b.*\bFROM\b.*\bWHERE\b)/i',
            '/(\bINSERT\b.*\bINTO\b.*\bVALUES\b)/i',
            '/(\bUPDATE\b.*\bSET\b)/i',
            '/(\bDELETE\b.*\bFROM\b)/i',
            '/(\bDROP\b.*\bTABLE\b)/i',
            '/(\bCREATE\b.*\bTABLE\b)/i',
            '/(\bALTER\b.*\bTABLE\b)/i',
            '/(\bEXEC\b|\bEXECUTE\b)/i',
            '/(--|\#|\/\*|\*\/)/i', // SQL comments
            '/(\bOR\b.*=.*)/i', // OR 1=1 attacks
            '/(\bAND\b.*=.*)/i', // AND 1=1 attacks
        ];

        foreach ($patterns as $pattern) {
            $input = preg_replace($pattern, '', $input);
        }

        return $input;
    }

    /**
     * Remove XSS attack patterns
     *
     * @param string $input
     * @return string
     */
    protected static function removeXss($input)
    {
        // Remove script tags and their content
        $input = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $input);

        // Remove javascript: protocol
        $input = preg_replace('/javascript:/i', '', $input);

        // Remove on* event handlers
        $input = preg_replace('/\bon\w+\s*=\s*["\']?[^"\']*["\']?/i', '', $input);

        // Convert special characters to HTML entities
        $input = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $input;
    }

    /**
     * Validate email address
     *
     * @param string $email
     * @return bool
     */
    public static function isValidEmail($email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate phone number (basic validation)
     *
     * @param string $phone
     * @return bool
     */
    public static function isValidPhone($phone)
    {
        // Remove common phone number characters
        $phone = preg_replace('/[\s\-\(\)\+]/', '', $phone);
        
        // Check if it's a valid phone number (7-15 digits)
        return preg_match('/^[0-9]{7,15}$/', $phone);
    }

    /**
     * Validate URL
     *
     * @param string $url
     * @return bool
     */
    public static function isValidUrl($url)
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Validate integer
     *
     * @param mixed $value
     * @return bool
     */
    public static function isValidInteger($value)
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    /**
     * Validate float/decimal
     *
     * @param mixed $value
     * @return bool
     */
    public static function isValidFloat($value)
    {
        return filter_var($value, FILTER_VALIDATE_FLOAT) !== false;
    }

    /**
     * Validate date format
     *
     * @param string $date
     * @param string $format
     * @return bool
     */
    public static function isValidDate($date, $format = 'Y-m-d')
    {
        $d = \DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }

    /**
     * Validate required fields
     *
     * @param array $data
     * @param array $required
     * @return array Array of missing fields
     */
    public static function validateRequired($data, $required)
    {
        $missing = [];
        
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $missing[] = $field;
            }
        }
        
        return $missing;
    }

    /**
     * Validate string length
     *
     * @param string $value
     * @param int $min
     * @param int $max
     * @return bool
     */
    public static function validateLength($value, $min = 0, $max = null)
    {
        $length = strlen($value);
        
        if ($length < $min) {
            return false;
        }
        
        if ($max !== null && $length > $max) {
            return false;
        }
        
        return true;
    }

    /**
     * Validate numeric range
     *
     * @param mixed $value
     * @param float $min
     * @param float $max
     * @return bool
     */
    public static function validateRange($value, $min = null, $max = null)
    {
        if (!is_numeric($value)) {
            return false;
        }
        
        if ($min !== null && $value < $min) {
            return false;
        }
        
        if ($max !== null && $value > $max) {
            return false;
        }
        
        return true;
    }

    /**
     * Sanitize filename to prevent directory traversal
     *
     * @param string $filename
     * @return string
     */
    public static function sanitizeFilename($filename)
    {
        // Remove path separators
        $filename = str_replace(['../', '..\\', '/', '\\'], '', $filename);
        
        // Remove special characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
        
        return $filename;
    }

    /**
     * Validate file upload
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @param array $allowedExtensions
     * @param int $maxSize in bytes
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public static function validateFileUpload($file, $allowedExtensions = [], $maxSize = 5242880)
    {
        if (!$file->isValid()) {
            return ['valid' => false, 'error' => 'Invalid file upload'];
        }

        // Check file size
        if ($file->getSize() > $maxSize) {
            return ['valid' => false, 'error' => 'File size exceeds maximum allowed'];
        }

        // Check file extension
        if (!empty($allowedExtensions)) {
            $extension = strtolower($file->getClientOriginalExtension());
            if (!in_array($extension, $allowedExtensions)) {
                return ['valid' => false, 'error' => 'File type not allowed'];
            }
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Validate password strength
     *
     * @param string $password
     * @param int $minLength
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validatePasswordStrength($password, $minLength = 8)
    {
        $errors = [];

        if (strlen($password) < $minLength) {
            $errors[] = "Password must be at least {$minLength} characters long";
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = "Password must contain at least one uppercase letter";
        }

        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = "Password must contain at least one lowercase letter";
        }

        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = "Password must contain at least one number";
        }

        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = "Password must contain at least one special character";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Clean string for database storage
     *
     * @param string $value
     * @return string
     */
    public static function cleanForDatabase($value)
    {
        // Trim whitespace
        $value = trim($value);
        
        // Remove null bytes
        $value = str_replace("\0", '', $value);
        
        // Normalize line endings
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        
        return $value;
    }

    /**
     * Validate money amount
     *
     * @param mixed $amount
     * @return bool
     */
    public static function isValidAmount($amount)
    {
        // Check if it's a valid number
        if (!is_numeric($amount)) {
            return false;
        }

        // Check if it's not negative
        if ($amount < 0) {
            return false;
        }

        // Check decimal places (max 2)
        if (strpos($amount, '.') !== false) {
            $parts = explode('.', $amount);
            if (strlen($parts[1]) > 2) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate SKU format
     *
     * @param string $sku
     * @return bool
     */
    public static function isValidSKU($sku)
    {
        // SKU should be alphanumeric with hyphens/underscores, 3-50 characters
        return preg_match('/^[A-Za-z0-9_-]{3,50}$/', $sku);
    }

    /**
     * Validate barcode format
     *
     * @param string $barcode
     * @return bool
     */
    public static function isValidBarcode($barcode)
    {
        // Barcode should be numeric, 8-13 characters (EAN-8, EAN-13, UPC-A)
        return preg_match('/^[0-9]{8,13}$/', $barcode);
    }
}

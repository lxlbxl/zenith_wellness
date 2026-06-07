<?php

class Validator
{
    public static function sanitize($input)
    {
        if (is_array($input)) {
            foreach ($input as $key => $value) {
                $input[$key] = self::sanitize($value);
            }
            return $input;
        }
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }

    public static function validateEmail($email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    public static function validateRequired($data, $fields)
    {
        $missing = [];
        foreach ($fields as $field) {
            if (!isset($data[$field]) || empty(trim($data[$field]))) {
                $missing[] = $field;
            }
        }
        return $missing;
    }
}

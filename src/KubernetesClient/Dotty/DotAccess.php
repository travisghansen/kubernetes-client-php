<?php

namespace KubernetesClient\Dotty;

class DotAccess {
    private const DELIMITERS = ['.'];
    protected static function keyToPathArray($path): array
    {
        if (is_array($path)) {
            return $path;
        }

        if (\strlen($path) === 0) {
            throw new \Exception('Path cannot be an empty string');
        }

        $path = \str_replace(self::DELIMITERS, '.', $path);
        $path = preg_replace('/\[([a-zA-Z0-9_\-]*)\]/', '.${1}', $path);

        return \explode('.', $path);
    }

    public static function propExists(&$data, $key) {
        if (is_object($data) && property_exists($data, $key)) {
            return true;
        }
        if (is_array($data) && array_key_exists($key, $data)) {
            return true;
        }

        return false;
    }
    public static function &propGet(&$data, $key) {
        if (!self::propExists($data, $key)) {
            throw new \Exception('property not present');
        }
        if (is_object($data)) {
            return $data->{$key};
        }
        if (is_array($data)) {
            return $data[$key];
        }
    }

    public static function propSet(&$data, $key, $value) {
        if (is_object($data)) {
            $data->{$key} = $value;
        }
        if (is_array($data)) {
            $data[$key] = $value;
        }
    }

    public static function isStructuredData(&$data) {
        if (is_object($data) || is_array($data)) {
            return true;
        }
        return false;
    }

    public static function &get(&$currentValue, $key, $default = null) {
        $hasDefault = \func_num_args() > 2;

        if (is_string($key)) {
            $keyPath = self::keyToPathArray($key);
        } else {
            $keyPath = $key;
        }

        foreach ($keyPath as $currentKey) {
            if (!self::isStructuredData($currentValue) || !self::propExists($currentValue, $currentKey)) {
                if ($hasDefault) {
                    return $default;
                }

                throw new \Exception('path not present');
            }

            $currentValue = &self::propGet($currentValue, $currentKey);
        }

        return $currentValue === null ? $default : $currentValue;
    }

    public static function exists(&$currentValue, $key) {
        if (is_string($key)) {
            $keyPath = self::keyToPathArray($key);
        } else {
            $keyPath = $key;
        }

        foreach ($keyPath as $currentKey) {
            if (!self::isStructuredData($currentValue) || !self::propExists($currentValue, $currentKey)) {
                return false;
            }

            $currentValue = &self::propGet($currentValue, $currentKey);
        }

        return true;
    }

    public static function set(&$data, $key, $value, $options = []) {
        if (is_string($key)) {
            $keyPath = self::keyToPathArray($key);
        } else {
            $keyPath = $key;
        }

        $currentValue = &$data;

        $keySize = sizeof($keyPath);
        for ($i = 0; $i < $keySize; $i++) {
            $currentKey = $keyPath[$i];

            if ($i == ($keySize - 1)) {
                self::propSet($currentValue, $currentKey, $value);
                return;
            }

            if (!self::isStructuredData($currentValue) && self::propExists($currentValue, $currentKey)) {
                throw new \Exception("key {$currentKey} already exists but it unstructured content");
            }

            if (!self::propExists($currentValue, $currentKey)) {
                // if option to create is enabled create
                if (self::get($options, 'create_structure', true)) {
                    $type = self::get($options, 'create_structure_type', 'obj');
                    if ($type == 'array') {
                        self::propSet($currentValue, $currentKey, []);
                    }

                    if ($type == 'obj') {
                        self::propSet($currentValue, $currentKey, new \stdClass());
                    }
                } else {
                    throw new \Exception('necessary parents do not exist');
                }
            }

            $currentValue = &self::propGet($currentValue, $currentKey);
        }
    }
}

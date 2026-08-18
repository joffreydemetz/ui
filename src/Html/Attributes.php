<?php

declare(strict_types=1);

namespace JDZ\Ui\Html;

/**
 * Parse / merge HTML tag attributes.
 *
 * @author Joffrey Demetz <joffrey.demetz@gmail.com>
 */
class Attributes
{
    /**
     * Parse an attribute string into key/value pairs.
     */
    public static function parse(string $string): array
    {
        $attr = [];
        $list = [];

        preg_match_all('/([\w:-]+)[\s]?=[\s]?"([^"]*)"/i', $string, $attr);

        if (is_array($attr)) {
            $numPairs = count($attr[1]);
            for ($i = 0; $i < $numPairs; $i++) {
                $list[$attr[1][$i]] = $attr[2][$i];
            }
        }

        return $list;
    }

    /**
     * Merge key/value pairs back into an attribute string. Returns the string
     * with a leading space (or '' when empty), ready to drop after a tag name.
     */
    public static function merge(array $attrs = []): string
    {
        $attributes = [];
        foreach ($attrs as $key => $value) {
            if ($key === 'class' && is_array($value)) {
                $value = array_unique($value);
                $value = implode(' ', $value);
                if (empty($value)) {
                    continue;
                }
            }

            if (true === $value) {
                $value = 'true';
            } elseif (false === $value) {
                $value = 'false';
            } elseif ($value) {
                $value = trim($value);
                $value = str_replace('"', '\"', $value);
            } else {
                $value = '';
            }

            $attributes[] = $key . '="' . $value . '"';
        }

        $attrs = implode(' ', $attributes);
        if ($attrs !== '') {
            return ' ' . $attrs;
        }

        return '';
    }
}

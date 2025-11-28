<?php

class XmlXsdValidator
{
    public static function validate(string $xml, string $xsdPath): bool
    {
        libxml_use_internal_errors(true);

        $doc = new \DOMDocument();
        $doc->loadXML($xml);

        if (!$doc->schemaValidate($xsdPath)) {
            throw new \Exception(self::formatErrors());
        }

        return true;
    }

    private static function formatErrors()
    {
        $errors = libxml_get_errors();
        $msg = '';

        foreach ($errors as $error) {
            $msg .= trim($error->message) . "\n";
        }

        libxml_clear_errors();
        return $msg;
    }
}


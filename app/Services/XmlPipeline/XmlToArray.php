<?php

class XmlToArray
{
    public static function convert(string $xml): array
    {
        $simple = simplexml_load_string($xml, null, LIBXML_NOCDATA);

        return json_decode(json_encode($simple), true);
    }
}


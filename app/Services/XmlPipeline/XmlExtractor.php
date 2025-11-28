<?php

class XmlExtractor
{
    public static function extract(string $xml): string
    {
        // CDATA
        if (preg_match('/<!\[CDATA\[(.*?)\]\]>/s', $xml, $m)) {
            return trim($m[1]);
        }

        // SOAP
        if (str_contains($xml, '<soap:Envelope')) {
            return self::extractFromSoap($xml);
        }

        return $xml;
    }

    private static function extractFromSoap(string $xml): string
    {
        $simple = simplexml_load_string($xml);
        $ns = $simple->getNamespaces(true);

        if (!isset($ns['soap'])) {
            return $xml;
        }

        return (string) $simple->children($ns['soap'])->Body->asXML();
    }
}

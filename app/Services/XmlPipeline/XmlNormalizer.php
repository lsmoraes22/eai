class XmlNormalizer
{
    public static function normalize(string $xml): string
    {
        $xml = trim($xml);
        $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml); // remove BOM
        return $xml;
    }
}

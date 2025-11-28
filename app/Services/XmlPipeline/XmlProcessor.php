<?php

class XmlProcessor
{
    public static function process(string $rawXml, ?string $xsdFile = null, ?array $rules = null): array
    {
        // 1. normalizar
        $xml = XmlNormalizer::normalize($rawXml);

        // 2. extrair SOAP / CDATA
        $xml = XmlExtractor::extract($xml);

        // 3. validar por XSD (se existir)
        if ($xsdFile && file_exists($xsdFile)) {
            XmlXsdValidator::validate($xml, $xsdFile);
        }

        // 4. converter para array
        $array = XmlToArray::convert($xml);

        // 5. validar por rules.json (se existir)
        if ($rules) {
            $errors = XmlRulesValidator::validate($array, $rules);

            if (!empty($errors)) {
                return [
                    'status' => 'error',
                    'errors' => $errors,
                ];
            }
        }

        // 6. retorno final
        return [
            'status' => 'ok',
            'xml' => $xml,
            'array' => $array
        ];
    }
}


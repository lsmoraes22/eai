<?php

class XmlRulesValidator
{
    public static function validate(array $xmlArray, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $required) {
            if ($required === true && (!isset($xmlArray[$field]) || empty($xmlArray[$field]))) {
                $errors[] = "Campo obrigatório ausente: $field";
            }
        }

        return $errors;
    }
}

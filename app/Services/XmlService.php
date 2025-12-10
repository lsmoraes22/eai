<?php

namespace App\Services;

class XmlService
{
    public $message = null;

    /**
     * Extrai o XML real de dentro de um SOAP Envelope (Body).
     */
    public function extractXmlFromSoap(string $content, bool $removeNamespaces = false): ?string
    {
        // 1) Remove CDATA
	$start = strpos($content, "<![CDATA[");
	$end   = strripos($content, "]]>") + strlen("]]>");

	if ($start !== false && $end !== false) {
	    $content = substr($content, $start, $end - $start);
	}
	$content = preg_replace('/<!\[CDATA\[(.*?)\]\]>/s', '$1', $content);

        // 2) Remove namespaces (simplifica parsing)
        if ($removeNamespaces) {
    	    $content = preg_replace('/(<\/?)(\w+):([^>]*>)/', '$1$3', $content);
    	}

        // 3) Extrair conteúdo do Body
        if (preg_match('/<Body>(.*)<\/Body>/is', $content, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/<Body[^>]*>(.*)<\/Body>/is', $content, $matches)) {
            return trim($matches[1]);
        }

        return $content;
    }

    /**
     * Sanitiza XML removendo caracteres inválidos e corrigindo entidades.
     * Também registra uma mensagem se algo foi alterado.
     */
    public function sanitize(string $xml): string
    {
        $original = $xml;

        // 1) Corrige & não escapados
        $xml = preg_replace('/&(?![a-zA-Z0-9#]+;)/', '&amp;', $xml, -1, $countAmp);

        // 2) Remove caracteres de controle ilegais
        $xml = preg_replace('/[^\P{C}\t\n\r]/u', '', $xml, -1, $countInvalidChars);

        // Se algo foi removido/adaptado, registrar mensagem
        if (($countAmp ?? 0) > 0 || ($countInvalidChars ?? 0) > 0) {

            $msgs = [];

            if (!empty($countAmp)) {
                $msgs[] = "Corrigidos {$countAmp} caracteres '&' não escapados.";
            }

            if (!empty($countInvalidChars)) {
                $msgs[] = "Removidos {$countInvalidChars} caracteres ilegais de controle.";
            }

            $this->message = implode(' ', $msgs);
        }

        return trim($xml);
    }
}

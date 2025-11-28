<?php

namespace App\Services;

class XmlService
{
    public ?string $message = null;

    /**
     * Sanitize string to make it XML-loadable:
     * - Escapa & que não fazem parte de entidades (ex: "& something" -> "&amp; something")
     * - Remove caracteres de controle ilegais.
     */
    public function sanitizeForXml(string $xml): string
    {
        // Corrige ampersands soltos (não parte de entidades): &texto ---> &amp;texto
        $xml = preg_replace('/&(?!([a-zA-Z]+|#\d+);)/', '&amp;', $xml);

        // Remove caracteres de controle inválidos em XML (exceto tab/newline/carriage)
        $xml = preg_replace('/[^\x09\x0A\x0D\x20-\x{10FFFF}]/u', '', $xml);

        return $xml;
    }

    /**
     * Extrai o payload real de dentro do SOAP Body.
     * Retorna string XML do primeiro filho element do Body (ou conteúdo do CDATA), ou null se não encontrou.
     */
    public function extractXmlFromSoap(string $content): ?string
    {
        $this->message = null;

        // 1) quick check: se o conteúdo já parece ser só XML útil (sem Envelope), devolve ele
        $trim = trim($content);
        if (str_starts_with($trim, '<') && stripos($trim, '<Envelope') === false && stripos($trim, '<soap:') === false) {
            // pode ainda ter CDATA
            if (preg_match('/<!\[CDATA\[(.*?)\]\]>/s', $trim, $m)) {
                $this->message = 'CDATA extraído (conteúdo direto).';
                return $m[1];
            }
            return $trim;
        }

        // 2) sanitize para evitar erros óbvios (ampersands, chars ilegais)
        $sanitized = $this->sanitizeForXml($content);
        if ($sanitized !== $content) {
            $this->message = ($this->message ? $this->message . ' ' : '') . 'Conteúdo sanitizado (ampersands/caracteres ilegais corrigidos).';
        }

        // 3) Tentar carregar com DOMDocument e XPath (robusto)
        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();

        // tenta carregar. Se falhar, tentaremos fallback regex.
        if (@$doc->loadXML($sanitized) === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();

            // Fallback: extrair CDATA por regex (se houver)
            if (preg_match('/<!\[CDATA\[(.*?)\]\]>/s', $content, $m)) {
                $this->message = ($this->message ? $this->message . ' ' : '') . 'Usado fallback regex para extrair CDATA.';
                return trim($m[1]);
            }

            // Se não encontrou CDATA, tentar pegar o conteúdo entre <Body> com regex
            if (preg_match('/<Body[^>]*>(.*)<\/Body>/is', $sanitized, $m2)) {
                $this->message = ($this->message ? $this->message . ' ' : '') . 'Body extraído por regex (fallback).';
                return trim($m2[1]);
            }

            // tudo falhou
            $this->message = ($this->message ? $this->message . ' ' : '') . 'Erro ao carregar XML com DOM (fallbacks tentados).';
            return null;
        }

        $xpath = new \DOMXPath($doc);

        // Registrar namespaces conhecidos para facilitar query (várias variações)
        // Apenas registra os namespaces existentes no documento
        if ($doc->documentElement && $doc->documentElement->hasAttributes()) {
            foreach ($doc->documentElement->attributes as $attr) {
                if (strpos($attr->nodeName, 'xmlns') === 0) {
                    $parts = explode(':', $attr->nodeName, 2);
                    $prefix = $parts[1] ?? '';
                    $ns = $attr->nodeValue;
                    if ($prefix) {
                        $xpath->registerNamespace($prefix, $ns);
                    } else {
                        // namespace default
                        $xpath->registerNamespace('def', $ns);
                    }
                }
            }
        }

        // 4) Buscar Body de forma robusta (suporta prefixos distintos)
        $bodyNodeList = $xpath->query('//*[local-name()="Body"]');
        if ($bodyNodeList->length === 0) {
            // sem Body: talvez o payload já esteja "desembrulhado"
            $this->message = ($this->message ? $this->message . ' ' : '') . 'Nenhum Body encontrado via XPath.';
            // tentar pegar primeiro elemento que não seja Envelope
            $rootChildren = [];
            foreach ($doc->documentElement->childNodes as $c) {
                if ($c->nodeType === XML_ELEMENT_NODE) {
                    $rootChildren[] = $c;
                }
            }
            if (count($rootChildren) > 0) {
                $first = $rootChildren[0];
                return $doc->saveXML($first);
            }
            return null;
        }

        $body = $bodyNodeList->item(0);

        // 5) Procurar primeiro nó element dentro do Body (pode haver whitespace/text)
        foreach ($body->childNodes as $child) {
            if ($child->nodeType === XML_CDATA_SECTION_NODE) {
                // Se for CDATA, retorna o conteúdo bruto
                $this->message = ($this->message ? $this->message . ' ' : '') . 'CDATA extraído do Body.';
                return trim($child->data);
            }
            if ($child->nodeType === XML_ELEMENT_NODE) {
                // Retorna o XML do nó (incluindo sua tag)
                return $doc->saveXML($child);
            }
            if ($child->nodeType === XML_TEXT_NODE && trim($child->textContent) !== '') {
                // Texto direto dentro do Body (raro)
                return trim($child->textContent);
            }
        }

        // Se chegou aqui, Body existe mas não tem conteúdo útil
        $this->message = ($this->message ? $this->message . ' ' : '') . 'Body vazio ou sem elementos/processáveis.';
        return null;
    }
}

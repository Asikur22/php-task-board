<?php
/**
 * Input hardening: upload URL allowlist + rich-text HTML sanitizer.
 */

declare(strict_types=1);

/** Allowed image extensions for paths under uploads/. */
const UPLOAD_URL_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

/**
 * Accept only app-local upload paths: uploads/<hex>.(jpg|png|gif|webp)
 * Returns the normalized relative URL, or null if invalid / empty.
 */
function safe_upload_url(?string $url): ?string
{
    if ($url === null) {
        return null;
    }
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    // Reject absolute / protocol-relative / data / javascript URLs.
    if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $url)) {
        return null;
    }
    $url = str_replace('\\', '/', $url);
    if (str_contains($url, '..')) {
        return null;
    }
    if (!preg_match('#^uploads/([a-f0-9]{16})\.(jpg|jpeg|png|gif|webp)$#i', $url, $m)) {
        return null;
    }
    $ext = strtolower($m[2]);
    if ($ext === 'jpeg') {
        $ext = 'jpg';
    }
    if (!in_array($ext, UPLOAD_URL_EXTS, true)) {
        return null;
    }
    return 'uploads/' . strtolower($m[1]) . '.' . $ext;
}

/**
 * Sanitize user HTML to a strict allowlist (matches the client-side rules).
 * Plain text (no tags) is escaped and newlines become <br>.
 */
function sanitize_rich_html(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    if (!preg_match('/<[a-z][\s\S]*>/i', $raw)) {
        $escaped = htmlspecialchars($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return str_replace(["\r\n", "\r", "\n"], '<br>', $escaped);
    }

    if (!class_exists('DOMDocument')) {
        return htmlspecialchars(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    $allowed = [
        'p' => [], 'br' => [], 'div' => [], 'span' => [],
        'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 's' => [],
        'ul' => [], 'ol' => [], 'li' => [],
        'h1' => [], 'h2' => [], 'h3' => [],
        'blockquote' => [], 'code' => [],
        'a' => ['href'],
    ];

    $doc = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $wrapped = '<!DOCTYPE html><html><body><div id="tb-root">' . $raw . '</div></body></html>';
    $doc->loadHTML('<?xml encoding="UTF-8">' . $wrapped);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $root = $doc->getElementById('tb-root');
    if (!$root) {
        return '';
    }

    $out = sanitize_rich_children($root, $allowed);
    // Collapse empty result
    $plain = trim(html_entity_decode(strip_tags($out), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return $plain === '' ? '' : $out;
}

/** @param array<string, list<string>> $allowed */
function sanitize_rich_children(DOMNode $parent, array $allowed): string
{
    $html = '';
    foreach (iterator_to_array($parent->childNodes) as $child) {
        if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
            $html .= htmlspecialchars((string) $child->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            continue;
        }
        if ($child->nodeType !== XML_ELEMENT_NODE || !($child instanceof DOMElement)) {
            continue;
        }
        $tag = strtolower($child->tagName);
        if ($tag === 'tb-root') {
            $html .= sanitize_rich_children($child, $allowed);
            continue;
        }
        if (!isset($allowed[$tag])) {
            $html .= sanitize_rich_children($child, $allowed);
            continue;
        }
        if ($tag === 'br') {
            $html .= '<br>';
            continue;
        }
        if ($tag === 'a') {
            $href = trim($child->getAttribute('href'));
            if (!preg_match('#^(https?:|mailto:)#i', $href)) {
                $html .= sanitize_rich_children($child, $allowed);
                continue;
            }
            $safeHref = htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $html .= '<a href="' . $safeHref . '" target="_blank" rel="noopener noreferrer">'
                . sanitize_rich_children($child, $allowed) . '</a>';
            continue;
        }
        $inner = sanitize_rich_children($child, $allowed);
        $html .= '<' . $tag . '>' . $inner . '</' . $tag . '>';
    }
    return $html;
}

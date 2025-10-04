<?php

// NOTE: Reintroduced after merge cleanup so the notes feature can be re-PR'd.
// Lightweight Parsedown-like renderer for Markdown content.
// Provides a subset of Parsedown features needed for notes.php while
// remaining dependency-free and PHP 7.4 compatible.

class Parsedown
{
    /** @var bool */
    protected $safeMode = true;

    public function setSafeMode($safeMode)
    {
        $this->safeMode = (bool) $safeMode;
        return $this;
    }

    public function getSafeMode()
    {
        return $this->safeMode;
    }

    public function text($text)
    {
        $lines = preg_split("/(\r\n|\r|\n)/", (string) $text);
        $html = [];
        $paragraph = [];
        $listItems = [];
        $listType = null;
        $inCodeBlock = false;
        $codeLang = '';
        $codeLines = [];

        $flushParagraph = function () use (&$paragraph, &$html) {
            if (!empty($paragraph)) {
                $content = $this->formatInline(implode(' ', $paragraph));
                $html[] = '<p>' . $content . '</p>';
                $paragraph = [];
            }
        };

        $flushList = function () use (&$listItems, &$listType, &$html) {
            if (!empty($listItems)) {
                $tag = $listType === 'ol' ? 'ol' : 'ul';
                $html[] = '<' . $tag . '>';
                foreach ($listItems as $item) {
                    $html[] = '<li>' . $this->formatInline($item) . '</li>';
                }
                $html[] = '</' . $tag . '>';
                $listItems = [];
                $listType = null;
            }
        };

        $flushCode = function () use (&$codeLines, &$codeLang, &$html, &$inCodeBlock) {
            if ($inCodeBlock) {
                $inCodeBlock = false;
                $escaped = htmlspecialchars(implode("\n", $codeLines), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $class = $codeLang !== '' ? ' class="language-' . htmlspecialchars($codeLang, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' : '';
                $html[] = '<pre><code' . $class . '>' . $escaped . '</code></pre>';
                $codeLines = [];
                $codeLang = '';
            }
        };

        foreach ($lines as $line) {
            $rawLine = $line;
            $line = rtrim($line);

            if ($inCodeBlock) {
                if (preg_match('/^```/', $line)) {
                    $flushCode();
                    continue;
                }
                $codeLines[] = $rawLine;
                continue;
            }

            if (preg_match('/^```\s*(\w+)?\s*$/', $line, $matches)) {
                $flushParagraph();
                $flushList();
                $inCodeBlock = true;
                $codeLang = isset($matches[1]) ? strtolower($matches[1]) : '';
                $codeLines = [];
                continue;
            }

            if (trim($line) === '') {
                $flushParagraph();
                $flushList();
                continue;
            }

            if (preg_match('/^(#{1,6})\s*(.+)$/', $line, $matches)) {
                $flushParagraph();
                $flushList();
                $level = strlen($matches[1]);
                $content = $this->formatInline($matches[2]);
                $html[] = '<h' . $level . '>' . $content . '</h' . $level . '>';
                continue;
            }

            if (preg_match('/^(?:-\s{3,}|\*\s{3,}|_\s{3,})$/', $line)) {
                $flushParagraph();
                $flushList();
                $html[] = '<hr>';
                continue;
            }

            if (preg_match('/^([*+-])\s+(.+)$/', $line, $matches)) {
                $flushParagraph();
                if ($listType !== 'ul') {
                    $flushList();
                    $listType = 'ul';
                }
                $listItems[] = $matches[2];
                continue;
            }

            if (preg_match('/^(\d+)\.\s+(.+)$/', $line, $matches)) {
                $flushParagraph();
                if ($listType !== 'ol') {
                    $flushList();
                    $listType = 'ol';
                }
                $listItems[] = $matches[2];
                continue;
            }

            $paragraph[] = $line;
        }

        if ($inCodeBlock) {
            $flushCode();
        }

        $flushParagraph();
        $flushList();

        return implode("\n", $html);
    }

    protected function formatInline($text)
    {
        $text = (string) $text;

        // Handle code spans first.
        $segments = preg_split('/(`[^`]*`)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $output = '';

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($segment[0] === '`' && substr($segment, -1) === '`') {
                $code = substr($segment, 1, -1);
                $output .= '<code>' . htmlspecialchars($code, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
                continue;
            }

            $escaped = htmlspecialchars($segment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $escaped = preg_replace_callback('/\*\*(.+?)\*\*/s', function ($m) {
                return '<strong>' . $m[1] . '</strong>';
            }, $escaped);

            $escaped = preg_replace_callback('/__(.+?)__/s', function ($m) {
                return '<strong>' . $m[1] . '</strong>';
            }, $escaped);

            $escaped = preg_replace_callback('/\*(.+?)\*/s', function ($m) {
                return '<em>' . $m[1] . '</em>';
            }, $escaped);

            $escaped = preg_replace_callback('/_(.+?)_/s', function ($m) {
                return '<em>' . $m[1] . '</em>';
            }, $escaped);

            $escaped = preg_replace_callback('/\[(.+?)\]\((https?:[^\s)]+)\)/', function ($m) {
                $text = $m[1];
                $url = $m[2];
                $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                return '<a href="' . $safeUrl . '" rel="noopener" target="_blank">' . $text . '</a>';
            }, $escaped);

            $escaped = preg_replace_callback('/~~(.+?)~~/s', function ($m) {
                return '<del>' . $m[1] . '</del>';
            }, $escaped);

            $output .= nl2br($escaped);
        }

        return $output;
    }
}

?>

<?php

declare(strict_types=1);

namespace App\Services;

final class ShareDocument
{
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function head(array $metadata): string
    {
        $escape = self::escape(...);
        $tags = '<title>' . $escape($metadata['title']) . '</title>' . "\n";
        $tags .=
            '<meta name="description" content="' .
            $escape($metadata['description']) .
            '" />' .
            "\n";
        $tags .=
            '<link rel="canonical" href="' . $escape($metadata['url']) . '" />' . "\n";
        foreach (
            [
                'og:type' => 'website',
                'og:locale' => 'id_ID',
                'og:site_name' => 'Ciptain',
                'og:title' => $metadata['title'],
                'og:description' => $metadata['description'],
                'og:url' => $metadata['url'],
                'og:image' => $metadata['image'],
                'og:image:alt' => $metadata['title'],
            ]
            as $property => $content
        ) {
            $tags .=
                '<meta property="' .
                $property .
                '" content="' .
                $escape($content) .
                '" />' .
                "\n";
        }
        if ($metadata['generatedImage']) {
            $tags .= '<meta property="og:image:type" content="image/jpeg" />' . "\n";
            $tags .= '<meta property="og:image:width" content="1200" />' . "\n";
            $tags .= '<meta property="og:image:height" content="630" />' . "\n";
        }
        return $tags;
    }

    public static function inject(string $html, string $head): string
    {
        $html = preg_replace('~<title\b[^>]*>.*?</title>~is', '', $html);
        $html = preg_replace(
            '~<meta\b(?=[^>]*\bname=["\']description["\'])[^>]*>~i',
            '',
            $html,
        );
        return preg_replace_callback(
            '~</head>~i',
            static fn() => $head . '</head>',
            $html,
            1,
        );
    }
}

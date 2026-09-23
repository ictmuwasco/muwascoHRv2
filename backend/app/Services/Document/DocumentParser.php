<?php

declare(strict_types=1);

namespace App\Services\Document;

use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\IOFactory as PhpWordIOFactory;

class DocumentParser
{
    public static function parse(string $filePath, string $extension): array
    {
        if (!is_file($filePath)) {
            throw new \InvalidArgumentException('Document file not found for parsing.');
        }

        $extension = strtolower($extension);

        // For .doc files, try to parse as .docx (PhpWord can sometimes handle them)
        if ($extension === 'doc') {
            $extension = 'docx';
        }

        return match ($extension) {
            'pdf' => self::parsePdf($filePath),
            'docx' => self::parseDocx($filePath),
            default => throw new \InvalidArgumentException("Unsupported document type: {$extension}"),
        };
    }

    private static function parsePdf(string $filePath): array
    {
        $parser = new PdfParser();
        $pdf = $parser->parseFile($filePath);
        $pages = $pdf->getPages();
        $sections = [];
        $currentSection = null;
        $sectionIndex = 0;

        foreach ($pages as $pageNum => $page) {
            $text = $page->getText();
            $pageNumber = $pageNum + 1;
            $lines = explode("\n", $text);

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                if (self::isHeading($line)) {
                    if ($currentSection !== null) {
                        $sections[] = $currentSection;
                    }
                    $sectionIndex++;
                    $currentSection = [
                        'title' => $line,
                        'content' => '',
                        'page_start' => $pageNumber,
                        'page_end' => $pageNumber,
                        'section_number' => (string) $sectionIndex,
                    ];
                } elseif ($currentSection !== null) {
                    $currentSection['content'] .= $line . "\n";
                    $currentSection['page_end'] = $pageNumber;
                } else {
                    $sectionIndex++;
                    $currentSection = [
                        'title' => 'Introduction',
                        'content' => $line . "\n",
                        'page_start' => $pageNumber,
                        'page_end' => $pageNumber,
                        'section_number' => (string) $sectionIndex,
                    ];
                }
            }
        }

        if ($currentSection !== null) {
            $sections[] = $currentSection;
        }

        foreach ($sections as &$section) {
            $section['content'] = trim($section['content']);
        }

        return $sections;
    }

    private static function parseDocx(string $filePath): array
    {
        $phpWord = PhpWordIOFactory::load($filePath);
        $sections = [];
        $currentSection = null;
        $sectionIndex = 0;

        foreach ($phpWord->getSections() as $docSection) {
            foreach ($docSection->getElements() as $element) {
                $elementName = basename(str_replace('\\', '/', get_class($element)));
                $text = '';

                // Extract text from element
                if (method_exists($element, 'getText')) {
                    $text = $element->getText();
                    if (is_array($text)) {
                        $text = implode(' ', $text);
                    }
                }

                $text = trim($text);
                if (empty($text)) continue;

                // Check if this is a heading (Heading 1, Heading 2, etc.)
                $isHeading = str_contains($elementName, 'Heading') || str_contains($elementName, 'Title');

                if ($isHeading) {
                    if ($currentSection !== null) {
                        $sections[] = $currentSection;
                    }
                    $sectionIndex++;
                    $currentSection = [
                        'title' => $text,
                        'content' => '',
                        'page_start' => 1,
                        'page_end' => 1,
                        'section_number' => (string) $sectionIndex,
                    ];
                } elseif ($currentSection !== null) {
                    $currentSection['content'] .= $text . "\n\n";
                } else {
                    $sectionIndex++;
                    $currentSection = [
                        'title' => 'Introduction',
                        'content' => $text . "\n\n",
                        'page_start' => 1,
                        'page_end' => 1,
                        'section_number' => (string) $sectionIndex,
                    ];
                }
            }
        }

        if ($currentSection !== null) {
            $sections[] = $currentSection;
        }

        foreach ($sections as &$section) {
            $section['content'] = trim($section['content']);
        }

        return $sections;
    }

    private static function isHeading(string $line): bool
    {
        $line = trim($line);
        if (empty($line) || strlen($line) > 100) return false;
        if (preg_match('/^(\d+\.?\s+|Chapter\s+\d+|Section\s+\d+)/i', $line)) return true;
        if (preg_match('/^[A-Z\s\d\-\.]+$/', $line) && strlen($line) > 3) return true;
        if (strlen($line) < 60 && str_ends_with($line, ':')) return true;
        return false;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Document;

use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\IOFactory as PhpWordIOFactory;
use PhpOffice\PhpWord\Element\TextRun;

/**
 * DocumentParser — extract structured content from uploaded policy documents.
 *
 * Supports:
 * - PDF files (using smalot/pdfparser)
 * - DOCX files (using phpoffice/phpword)
 *
 * The parser splits content into sections based on detected headings
 * or page boundaries, creating a navigable table of contents.
 */
class DocumentParser
{
    /**
     * Parse a document and extract sections.
     *
     * @param string $filePath Absolute path to the document file
     * @param string $extension File extension (pdf, docx)
     * @return array<int, array{title: string, content: string, page_start: int|null, page_end: int|null}>
     * @throws \InvalidArgumentException If the file cannot be parsed
     */
    public static function parse(string $filePath, string $extension): array
    {
        if (!is_file($filePath)) {
            throw new \InvalidArgumentException('Document file not found for parsing.');
        }

        return match (strtolower($extension)) {
            'pdf' => self::parsePdf($filePath),
            'docx' => self::parseDocx($filePath),
            default => throw new \InvalidArgumentException("Unsupported document type: {$extension}"),
        };
    }
    /**
     * Parse a PDF document and extract sections.
     */
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

            // Split text by lines to detect headings
            $lines = explode("\n", $text);

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                // Detect potential headings (short lines, all caps, or numbered)
                if (self::isHeading($line)) {
                    // Save previous section
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
                    // Append content to current section
                    $currentSection['content'] .= $line . "\n";
                    $currentSection['page_end'] = $pageNumber;
                } else {
                    // Content before first heading - create an intro section
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

        // Add the last section
        if ($currentSection !== null) {
            $sections[] = $currentSection;
        }

        // Clean up content
        foreach ($sections as &$section) {
            $section['content'] = trim($section['content']);
        }

        return $sections;
    }


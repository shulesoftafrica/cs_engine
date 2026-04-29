<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser;
use Throwable;

class KnowledgeBaseContentExtractor
{
    public function extract(?UploadedFile $file): string
    {
        if ($file === null) {
            throw ValidationException::withMessages([
                'content' => 'A content file is required.',
            ]);
        }

        $extension = strtolower($file->getClientOriginalExtension());

        $content = match ($extension) {
            'txt' => $this->extractTextFile($file),
            'pdf' => $this->extractPdfFile($file),
            'docx' => $this->extractWordFile($file, 'Word2007'),
            'doc' => $this->extractWordFile($file, 'MsDoc'),
            default => throw ValidationException::withMessages([
                'content' => 'Unsupported file type. Allowed types: txt, pdf, doc, docx.',
            ]),
        };

        $content = trim(preg_replace('/\s+/', ' ', $content) ?? '');

        if ($content === '') {
            throw ValidationException::withMessages([
                'content' => 'The uploaded file does not contain readable text.',
            ]);
        }

        return $content;
    }

    private function extractTextFile(UploadedFile $file): string
    {
        return (string) file_get_contents($file->getRealPath());
    }

    private function extractPdfFile(UploadedFile $file): string
    {
        try {
            return (new Parser)->parseFile($file->getRealPath())->getText();
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'content' => 'Unable to read text from the uploaded PDF file.',
            ]);
        }
    }

    private function extractWordFile(UploadedFile $file, string $reader): string
    {
        try {
            $document = IOFactory::load($file->getRealPath(), $reader);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'content' => 'Unable to read text from the uploaded Word document.',
            ]);
        }

        $fragments = [];

        foreach ($document->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $this->collectWordText($element, $fragments);
            }
        }

        return implode(' ', array_filter($fragments));
    }

    private function collectWordText(mixed $element, array &$fragments): void
    {
        if ($element instanceof Text) {
            $fragments[] = $element->getText();

            return;
        }

        if ($element instanceof TextBreak) {
            $fragments[] = ' ';

            return;
        }

        if ($element instanceof TextRun) {
            foreach ($element->getElements() as $child) {
                $this->collectWordText($child, $fragments);
            }

            return;
        }

        if (method_exists($element, 'getText')) {
            $text = $element->getText();

            if (is_string($text) && $text !== '') {
                $fragments[] = $text;
            }
        }

        if (method_exists($element, 'getElements')) {
            foreach ($element->getElements() as $child) {
                $this->collectWordText($child, $fragments);
            }
        }
    }
}
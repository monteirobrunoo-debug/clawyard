<?php

namespace App\Services;

use App\Models\Document;

class RagService
{
    /**
     * Build context from relevant documents for a given query.
     */
    public function getContext(string $query): string
    {
        $documents = Document::search($query, 3);

        if (empty($documents)) {
            return '';
        }

        $context = "## Relevant PartYard Knowledge Base:\n\n";

        foreach ($documents as $doc) {
            $context .= "### {$doc->title}\n";
            $context .= substr($doc->content, 0, 1500) . "\n\n";
        }

        return $context;
    }

    /**
     * Inject RAG context into a message.
     */
    public function augmentMessage(string $message): string
    {
        $context = $this->getContext($message);

        if (empty($context)) {
            return $message;
        }

        return $context . "\n---\n\nUser question: " . $message;
    }

    /**
     * Ingest a document into the knowledge base.
     */
    public function ingest(string $title, string $content, string $source = 'partyard'): Document
    {
        $chunks = $this->chunkText($content);

        return Document::create([
            'title'   => $title,
            'source'  => $source,
            'content' => $content,
            'chunks'  => $chunks,
            'summary' => substr($content, 0, 500),
        ]);
    }

    protected function chunkText(string $text, int $chunkSize = 1000): array
    {
        $words  = explode(' ', $text);
        $chunks = [];
        $chunk  = [];
        $count  = 0;

        foreach ($words as $word) {
            $chunk[] = $word;
            $count++;

            if ($count >= $chunkSize) {
                $chunks[] = implode(' ', $chunk);
                $chunk    = [];
                $count    = 0;
            }
        }

        if (!empty($chunk)) {
            $chunks[] = implode(' ', $chunk);
        }

        return $chunks;
    }
}

<?php

namespace App\Services\Catalog;

class CatalogItemNormalizer
{
    public function normalize(?string $partNumber): string
    {
        $normalized = strtoupper(trim((string) $partNumber));

        return preg_replace('/[\s._-]+/', '', $normalized) ?? $normalized;
    }

    public function isNormalizable(?string $partNumber): bool
    {
        return $this->normalize($partNumber) !== '';
    }
}

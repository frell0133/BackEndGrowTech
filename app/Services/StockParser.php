<?php

namespace App\Services;

class StockParser
{
  /**
   * 1 baris = 1 stock item.
   * Format bebas. Kita simpan utuh ke stock_data.
   * fingerprint = hash(normalized line) untuk deteksi duplikat.
   */
  public function parseLines(string $raw): array
  {
    $raw = trim($raw);
    if ($raw === '') return [];

    $lines = preg_split("/\r\n|\r|\n/", $raw);
    $out = [];

    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '') continue;

      $normalized = preg_replace('/\s+/', ' ', $line); // rapihin spasi
      $fingerprint = hash('sha256', mb_strtolower($normalized));

      $out[] = [
        'stock_data' => $line,
        'fingerprint' => $fingerprint,
      ];
    }

    return $out;
  }
}

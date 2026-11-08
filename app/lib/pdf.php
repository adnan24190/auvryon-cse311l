<?php
declare(strict_types=1);
/** Minimal dependency-free PDF writer (Helvetica, text + rules). ASCII output: non-Latin text is transliterated. */
final class MiniPdf {
  private array $pages = []; private string $cur = ''; private float $y; private int $n = 0;
  const W = 595, H = 842, M = 48;
  function __construct(private string $title = 'Auvryon') { $this->newPage(); }
  private function newPage(): void { if ($this->cur !== '') $this->pages[] = $this->cur; $this->cur = ''; $this->y = self::H - self::M; $this->n++; }
  private static function esc(string $s): string {
    $t = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) : false; if ($t === false || $t === '') $t = $s;
    $t = preg_replace('/[^\x20-\x7E]/', '?', $t);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $t);
  }
  function line(string $text, float $size = 10, bool $bold = false, float $x = self::M, float $gap = 4): void {
    if ($this->y < self::M + $size) $this->newPage();
    $this->y -= $size; $this->cur .= sprintf("BT /%s %.1f Tf %.1f %.1f Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $x, $this->y, self::esc($text)); $this->y -= $gap;
  }
  /** one table row: cells [text, x, align('l'|'r')] */
  function row(array $cells, float $size = 8.5, bool $bold = false): void {
    if ($this->y < self::M + $size) $this->newPage();
    $this->y -= $size;
    foreach ($cells as [$t, $x, $al]) { $t = self::esc((string)$t); $xx = $al === 'r' ? $x - strlen($t) * $size * 0.5 : $x; $this->cur .= sprintf("BT /%s %.1f Tf %.1f %.1f Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $xx, $this->y, $t); }
    $this->y -= 3.5;
  }
  function rule(): void { $this->y -= 2; $this->cur .= sprintf("0.8 0.78 0.72 RG %.1f %.1f m %.1f %.1f l S\n", self::M, $this->y, self::W - self::M, $this->y); $this->y -= 5; }
  function gap(float $h = 8): void { $this->y -= $h; }
  function output(): string {
    $this->pages[] = $this->cur; $objs = []; $kids = [];
    $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>'; $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>'; $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    $id = 5; $total = count($this->pages);
    foreach ($this->pages as $i => $c) {
      $c .= sprintf("BT /F1 8 Tf %.1f 28 Td (%s) Tj ET\n", self::M, self::esc("Auvryon - simulated demonstration statement - page " . ($i + 1) . " of $total"));
      $objs[$id] = '<< /Length ' . strlen($c) . " >>\nstream\n$c\nendstream"; $objs[$id + 1] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::W . ' ' . self::H . "] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents $id 0 R >>"; $kids[] = ($id + 1) . ' 0 R'; $id += 2;
    }
    $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    $info = $id; $objs[$info] = '<< /Title (' . self::esc($this->title) . ') /Producer (Auvryon MiniPdf) >>';
    ksort($objs); $out = "%PDF-1.4\n"; $off = [];
    foreach ($objs as $n => $o) { $off[$n] = strlen($out); $out .= "$n 0 obj\n$o\nendobj\n"; }
    $x = strlen($out); $max = max(array_keys($objs));
    $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= $max; $i++) $out .= sprintf("%010d 00000 n \n", $off[$i] ?? 0);
    return $out . "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info $info 0 R >>\nstartxref\n$x\n%%EOF";
  }
}

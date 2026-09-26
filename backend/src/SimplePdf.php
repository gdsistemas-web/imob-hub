<?php
declare(strict_types=1);

/**
 * Gerador de PDF mínimo para contratos (A4, Helvetica/Helvetica-Bold, WinAnsi).
 * Suporta títulos, subtítulos, parágrafos justificados, itens de lista, rodapé com paginação
 * e um bloco de assinaturas que devolve a posição de cada assinatura em % da página
 * (formato que o Documenso usa para posicionar campos).
 */
final class SimplePdf {
 public const W = 595.28, H = 841.89;
 private const ML = 62, MR = 62, MT = 64, MB = 70;
 private const REGULAR = [32=>278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
 private const BOLD = [32=>278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584];
 /** Larguras de caracteres WinAnsi fora do ASCII que aparecem em contratos. */
 private const EXTRA = [0x96=>556,0x97=>1000,0x93=>333,0x94=>333,0x91=>222,0x92=>222,0x95=>350,0xA7=>556,0xAA=>370,0xBA=>365,0xB0=>400,0xB2=>333,0xB3=>333,0x80=>556];

 private array $pages = [];
 private string $cur = '';
 private float $y = 0;
 private string $footer = '';

 public function __construct(private string $title = '') { $this->newPage(); }

 public function footer(string $text): void { $this->footer = $text; }

 public function heading(string $text, float $size = 13): void {
  $this->ensure($size * 2.2);
  foreach ($this->wrap($text, $size, true, self::W - self::ML - self::MR) as $line) {
   $w = $this->width($line, $size, true);
   $this->text((self::W - $w) / 2, $this->y, $line, $size, true);
   $this->y -= $size * 1.35;
  }
  $this->y -= $size * 0.6;
 }

 public function subheading(string $text, float $size = 10.5): void {
  $this->ensure($size * 4); // não deixa o título órfão no pé da página
  $this->y -= 4;
  foreach ($this->wrap($text, $size, true, self::W - self::ML - self::MR) as $line) { $this->text(self::ML, $this->y, $line, $size, true); $this->y -= $size * 1.4; }
  $this->y -= 1;
 }

 public function paragraph(string $text, float $size = 10, bool $bold = false, float $indent = 0, string $bullet = ''): void {
  $maxW = self::W - self::ML - self::MR - $indent;
  $lines = $this->wrap($text, $size, $bold, $maxW);
  $lh = $size * 1.45;
  foreach ($lines as $i => $line) {
   $this->ensure($lh);
   if ($bullet !== '' && $i === 0) $this->text(self::ML + $indent - 10, $this->y, $bullet, $size, $bold);
   $last = $i === count($lines) - 1;
   $spaces = substr_count($line, ' ');
   $tw = !$last && $spaces > 0 ? ($maxW - $this->width($line, $size, $bold)) / $spaces : 0;
   $this->text(self::ML + $indent, $this->y, $line, $size, $bold, $tw);
   $this->y -= $lh;
  }
  $this->y -= $size * 0.55;
 }

 public function space(float $pt): void { $this->y -= $pt; }

 /**
  * Bloco de assinaturas em duas colunas. Cada item: ['name'=>..,'role'=>..,'doc'=>..].
  * Retorna, por item, ['page'=>n, 'positionX'=>%, 'positionY'=>%, 'width'=>%, 'height'=>%].
  */
 public function signatures(array $people, string $placeDate): array {
  $this->ensure(60);
  $this->paragraph($placeDate);
  $this->y -= 8;
  $colW = (self::W - self::ML - self::MR - 30) / 2; $boxH = 44; $out = [];
  foreach (array_chunk($people, 2, true) as $row) {
   $this->ensure($boxH + 46);
   $top = $this->y;
   foreach ($row as $idx => $p) {
    $x = self::ML + (array_search($idx, array_keys($row), true) * ($colW + 30));
    $lineY = $top - $boxH;
    $this->cur .= sprintf("0.4 w %.2F %.2F m %.2F %.2F l S\n", $x, $lineY, $x + $colW, $lineY);
    $this->text($x, $lineY - 12, $this->fit($p['name'], 9, true, $colW), 9, true);
    $this->text($x, $lineY - 23, $this->fit(trim($p['role'].($p['doc'] ? ' · '.$p['doc'] : '')), 8), 8);
    $out[$idx] = ['page'=>count($this->pages) + 1, 'positionX'=>round($x / self::W * 100, 2), 'positionY'=>round((self::H - $top + 2) / self::H * 100, 2), 'width'=>round($colW / self::W * 100, 2), 'height'=>round(($boxH - 4) / self::H * 100, 2)];
   }
   $this->y = $top - $boxH - 44;
  }
  return $out;
 }

 public function output(): string {
  $this->pages[] = $this->cur; $this->cur = '';
  $n = count($this->pages);
  $objs = [];
  $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
  $kids = [];
  $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
  $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
  foreach ($this->pages as $i => $content) {
   if ($this->footer !== '' || $n > 1) {
    $label = trim($this->footer.'  ·  Página '.($i + 1).' de '.$n, ' ·');
    $content .= sprintf("0.6 g BT /F1 7.5 Tf %.2F %.2F Td (%s) Tj ET 0 g\n", self::ML, 36, $this->escape($this->enc($label)));
   }
   $stream = gzcompress($content, 6);
   $pageObj = 5 + $i * 2; $contentObj = $pageObj + 1;
   $objs[$pageObj] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::W, self::H, $contentObj);
   $objs[$contentObj] = "<< /Length ".strlen($stream)." /Filter /FlateDecode >>\nstream\n".$stream."\nendstream";
   $kids[] = "$pageObj 0 R";
  }
  $objs[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$n.' >>';
  $infoId = max(array_keys($objs)) + 1;
  $objs[$infoId] = '<< /Title ('.$this->escape($this->enc($this->title)).') /Producer (IMOB HUB) /CreationDate (D:'.date('YmdHis').') >>';
  ksort($objs);
  $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offsets = [];
  foreach ($objs as $id => $body) { $offsets[$id] = strlen($pdf); $pdf .= "$id 0 obj\n$body\nendobj\n"; }
  $xref = strlen($pdf); $size = $infoId + 1;
  $pdf .= "xref\n0 $size\n0000000000 65535 f \n";
  for ($i = 1; $i < $size; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
  return $pdf."trailer\n<< /Size $size /Root 1 0 R /Info $infoId 0 R >>\nstartxref\n$xref\n%%EOF\n";
 }

 private function newPage(): void {
  if ($this->cur !== '') $this->pages[] = $this->cur;
  $this->cur = ''; $this->y = self::H - self::MT;
 }
 private function ensure(float $h): void { if ($this->y - $h < self::MB) $this->newPage(); }

 private function text(float $x, float $y, string $utf8, float $size, bool $bold = false, float $tw = 0): void {
  $this->cur .= sprintf("BT /%s %.2F Tf %.3F Tw %.2F %.2F Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $tw, $x, $y, $this->escape($this->enc($utf8)));
 }

 private function enc(string $s): string {
  $s = strtr($s, ["\u{00A0}"=>' ', "\u{2009}"=>' ', "\u{2011}"=>'-']);
  return (string)iconv('UTF-8', 'CP1252//TRANSLIT', $s);
 }
 private function escape(string $s): string { return strtr($s, ['\\'=>'\\\\', '('=>'\\(', ')'=>'\\)', "\r"=>'', "\n"=>' ']); }

 private function width(string $utf8, float $size, bool $bold): float {
  $table = $bold ? self::BOLD : self::REGULAR; $w = 0;
  foreach (str_split($this->enc($utf8)) as $ch) {
   $c = ord($ch);
   if (isset($table[$c])) { $w += $table[$c]; continue; }
   if (isset(self::EXTRA[$c])) { $w += self::EXTRA[$c]; continue; }
   // letras acentuadas: largura da letra base
   $base = iconv('CP1252', 'ASCII//TRANSLIT', $ch);
   $w += ($base !== false && $base !== '' && isset($table[ord($base[0])])) ? $table[ord($base[0])] : 556;
  }
  return $w * $size / 1000;
 }

 private function wrap(string $text, float $size, bool $bold, float $maxW): array {
  $lines = []; $line = '';
  foreach (preg_split('/\s+/u', trim($text)) as $word) {
   $try = $line === '' ? $word : "$line $word";
   if ($this->width($try, $size, $bold) <= $maxW || $line === '') { $line = $try; continue; }
   $lines[] = $line; $line = $word;
  }
  if ($line !== '') $lines[] = $line;
  return $lines ?: [''];
 }

 private function fit(string $text, float $size, bool $bold = false, float $maxW = 220): string {
  while ($text !== '' && $this->width($text, $size, $bold) > $maxW) $text = mb_substr($text, 0, -2).'…';
  return $text;
 }
}

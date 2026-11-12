<?php
// Legacy Word (.doc, Word 97-2003) text extraction.
//
// 1. Pure-PHP reader: parses the OLE2 compound file, the FIB and the piece table (CLX)
//    to read the document text. Works on any host (no antiword / LibreOffice needed).
// 2. Fallback: antiword, catdoc or headless LibreOffice, accepted only on a zero exit
//    code and plausible output (their error messages are never treated as resume text).
//
// Returns ['text' => string, 'requires_ocr' => false, 'error' => ?string]. An empty
// text always comes with an error; content is never invented.

function docExtractText(string $file): array
{
    $fail = static fn(string $msg): array => ['text' => '', 'requires_ocr' => false, 'error' => $msg];
    if (!is_file($file) || !is_readable($file)) return $fail('The .DOC file could not be read.');

    $nativeError = null;
    try {
        $text = docReadWord97($file);
        if (docTextLooksReal($text)) return ['text' => $text, 'requires_ocr' => false, 'error' => null];
        $nativeError = 'No readable text found in the .DOC file.';
    } catch (Throwable $e) {
        $nativeError = $e->getMessage();
    }

    foreach (docExternalConverters($file) as $text) {
        if (docTextLooksReal($text)) return ['text' => $text, 'requires_ocr' => false, 'error' => null];
    }
    return $fail('Legacy .DOC could not be parsed (' . $nativeError . '). Please save it as .DOCX or .PDF.');
}

/** Reject empty output and converter error messages such as "I can't open ...". */
function docTextLooksReal(?string $text): bool
{
    $t = trim((string)$text);
    if (mb_strlen($t) < 20) return false;
    if (preg_match('/^(I can\'t open|Error|Cannot|Unable to|usage:)/i', $t)) return false;
    $letters = preg_match_all('/\p{L}/u', $t);
    return $letters >= 15 && $letters / max(1, mb_strlen($t)) > 0.3;
}

/** @return iterable<string> text from external tools that exited successfully */
function docExternalConverters(string $file): iterable
{
    if (!function_exists('exec')) return;
    $has = static function (string $cmd): bool {
        $out = [];
        $rc = 1;
        @exec((stripos(PHP_OS, 'WIN') === 0 ? 'where ' : 'command -v ') . escapeshellarg($cmd) . (stripos(PHP_OS, 'WIN') === 0 ? ' 2>NUL' : ' 2>/dev/null'), $out, $rc);
        return $rc === 0 && !empty($out);
    };
    $null = stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null';
    foreach (['antiword' => 'antiword -m UTF-8.txt', 'catdoc' => 'catdoc -d utf-8'] as $bin => $cmd) {
        if (!$has($bin)) continue;
        $out = [];
        $rc = 1;
        @exec($cmd . ' ' . escapeshellarg($file) . ' 2>' . $null, $out, $rc);
        if ($rc === 0) yield implode("\n", $out);
    }
    foreach (['libreoffice', 'soffice'] as $bin) {
        if (!$has($bin)) continue;
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'resume_doc_' . bin2hex(random_bytes(6));
        @mkdir($dir, 0700, true);
        $out = [];
        $rc = 1;
        @exec($bin . ' --headless --convert-to txt:Text --outdir ' . escapeshellarg($dir) . ' ' . escapeshellarg($file) . ' 2>' . $null, $out, $rc);
        $txt = $dir . DIRECTORY_SEPARATOR . pathinfo($file, PATHINFO_FILENAME) . '.txt';
        $text = ($rc === 0 && is_file($txt)) ? (string)file_get_contents($txt) : '';
        @unlink($txt);
        @rmdir($dir);
        if ($text !== '') yield $text;
        break;
    }
}

/** Read the main document text of a Word 97-2003 binary file. Throws on unsupported/corrupt files. */
function docReadWord97(string $file): string
{
    $data = (string)file_get_contents($file);
    if (strlen($data) < 1536 || substr($data, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") throw new RuntimeException('not a Word 97-2003 file');

    $u16 = static fn(string $s, int $o): int => unpack('v', substr($s, $o, 2))[1];
    $u32 = static fn(string $s, int $o): int => unpack('V', substr($s, $o, 4))[1];
    $sectorSize = 1 << $u16($data, 0x1E);
    $miniSize = 1 << $u16($data, 0x20);
    $fatCount = $u32($data, 0x2C);
    $dirStart = $u32($data, 0x30);
    $miniCutoff = $u32($data, 0x38);
    $miniFatStart = $u32($data, 0x3C);
    $difatStart = $u32($data, 0x44);
    $difatCount = $u32($data, 0x48);
    $sector = static fn(int $n): string => substr($data, ($n + 1) * $sectorSize, $sectorSize);

    // FAT sector list from the header DIFAT (109 entries) plus DIFAT sectors.
    $fatSectors = [];
    for ($i = 0; $i < 109 && count($fatSectors) < $fatCount; $i++) $fatSectors[] = $u32($data, 0x4C + $i * 4);
    for ($d = $difatStart, $guard = 0; $d < 0xFFFFFFFA && count($fatSectors) < $fatCount && $guard++ < 10000; ) {
        $s = $sector($d);
        for ($i = 0; $i < $sectorSize / 4 - 1 && count($fatSectors) < $fatCount; $i++) $fatSectors[] = $u32($s, $i * 4);
        $d = $u32($s, $sectorSize - 4);
    }
    $fat = [];
    foreach ($fatSectors as $fs) $fat = array_merge($fat, array_values(unpack('V*', $sector($fs))));

    $chain = static function (int $start, array $table) : array {
        $out = [];
        for ($s = $start, $guard = 0; $s < 0xFFFFFFFA && isset($table[$s]) && $guard++ < 1000000; $s = $table[$s]) $out[] = $s;
        return $out;
    };
    $readChain = static function (int $start) use ($chain, $fat, $sector): string {
        $buf = '';
        foreach ($chain($start, $fat) as $s) $buf .= $sector($s);
        return $buf;
    };

    // Directory entries.
    $dir = $readChain($dirStart);
    $entries = [];
    for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
        $nameLen = $u16($dir, $o + 0x40);
        if ($nameLen < 2) continue;
        $name = mb_convert_encoding(substr($dir, $o, $nameLen - 2), 'UTF-8', 'UTF-16LE');
        $entries[$name] = ['type' => ord($dir[$o + 0x42]), 'start' => $u32($dir, $o + 0x74), 'size' => $u32($dir, $o + 0x78)];
    }
    if (!isset($entries['WordDocument'])) throw new RuntimeException('WordDocument stream missing');

    // Mini stream support for small streams.
    $miniStream = isset($entries['Root Entry']) ? $readChain($entries['Root Entry']['start']) : '';
    $miniFat = $miniFatStart < 0xFFFFFFFA ? array_values(unpack('V*', $readChain($miniFatStart))) : [];
    $stream = static function (string $name) use ($entries, $readChain, $chain, $miniFat, $miniStream, $miniSize, $miniCutoff): string {
        if (!isset($entries[$name])) throw new RuntimeException($name . ' stream missing');
        $e = $entries[$name];
        if ($e['size'] < $miniCutoff) {
            $buf = '';
            foreach ($chain($e['start'], $miniFat) as $s) $buf .= substr($miniStream, $s * $miniSize, $miniSize);
            return substr($buf, 0, $e['size']);
        }
        return substr($readChain($e['start']), 0, $e['size']);
    };

    $word = $stream('WordDocument');
    if ($u16($word, 0) !== 0xA5EC) throw new RuntimeException('unsupported Word version');
    $flags = $u16($word, 0x0A);
    if ($flags & 0x0100) throw new RuntimeException('the document is password protected');
    $table = $stream(($flags & 0x0200) ? '1Table' : '0Table');
    $fcClx = $u32($word, 0x01A2);
    $lcbClx = $u32($word, 0x01A6);
    if ($lcbClx === 0 || $fcClx + $lcbClx > strlen($table)) throw new RuntimeException('piece table missing');
    $clx = substr($table, $fcClx, $lcbClx);

    // Skip Prc blocks (0x01) to the Pcdt (0x02).
    $pos = 0;
    while ($pos < strlen($clx) && ord($clx[$pos]) === 0x01) $pos += 3 + $u16($clx, $pos + 1);
    if ($pos >= strlen($clx) || ord($clx[$pos]) !== 0x02) throw new RuntimeException('piece table not found');
    $lcb = $u32($clx, $pos + 1);
    $plc = substr($clx, $pos + 5, $lcb);
    $n = intdiv($lcb - 4, 12);
    $ccpText = $u32($word, 0x004C); // main document length in characters

    $text = '';
    for ($i = 0; $i < $n && mb_strlen($text) < $ccpText + 1; $i++) {
        $cpStart = $u32($plc, $i * 4);
        $cpEnd = $u32($plc, ($i + 1) * 4);
        $fc = $u32($plc, ($n + 1) * 4 + $i * 8 + 2);
        $count = max(0, $cpEnd - $cpStart);
        if ($fc & 0x40000000) {
            $text .= mb_convert_encoding(substr($word, intdiv($fc & ~0x40000000, 2), $count), 'UTF-8', 'Windows-1252');
        } else {
            $text .= mb_convert_encoding(substr($word, $fc, $count * 2), 'UTF-8', 'UTF-16LE');
        }
    }
    $text = mb_substr($text, 0, $ccpText);

    // Field codes: keep the displayed result (between 0x14 and 0x15), drop the instructions.
    $text = preg_replace('/\x13[^\x13\x14\x15]*\x14([^\x13\x15]*)\x15/u', '$1', $text) ?? $text;
    $text = preg_replace('/\x13[^\x13\x15]*\x15/u', '', $text) ?? $text;
    $text = strtr($text, ["\r" => "\n", "\x07" => "\t", "\x0B" => "\n", "\x0C" => "\n", "\x1E" => '-', "\x1F" => '', "\xC2\xA0" => ' ']);
    $text = preg_replace('/[\x00-\x08\x0E-\x1D]/u', '', $text) ?? $text;
    return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
}

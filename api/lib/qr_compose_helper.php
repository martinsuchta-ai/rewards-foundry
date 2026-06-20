<?php
/**
 * qr_compose_helper.php — fetch a QR, fetch a logo, composite them
 *                        server-side via GD. Returns the merged PNG
 *                        OR a plain QR when the logo path can't be
 *                        completed.
 *
 * Why this exists:
 *   quickchart.io's centerImageUrl mechanism returns a valid 200 PNG
 *   with error text rendered INTO the QR pixels when its server-side
 *   fetcher can't read our logo proxy. The PNG magic bytes are
 *   correct, so downstream "is this a PNG" checks pass — yet the
 *   image is unscannable. Doing the composite ourselves removes
 *   that failure mode: every step has explicit error handling and
 *   the plain QR is the universal fallback.
 *
 * Public surface:
 *   wm_qr_compose(string $text, int $size, string $logoUrl): array
 *     Returns ['ok' => bool, 'png' => ?string, 'error' => ?string,
 *              'composited' => bool, 'fallback_reason' => ?string].
 *     - ok === false ONLY when even the plain QR fetch failed
 *       (network down, quickchart unreachable). The endpoint should
 *       502 in that case.
 *     - composited === true means the logo embed worked; false means
 *       we shipped a plain QR (still scannable) because the logo
 *       step couldn't complete — fallback_reason tells the caller why.
 *
 *     The PNG bytes are ready to write directly to a response with
 *     Content-Type: image/png.
 */

declare(strict_types=1);

if (!function_exists('_wm_qr_parse_hex')) {
    /* Parse "#RRGGBB" / "RRGGBB" / "#rgb" / "rgb" into [r, g, b] ints
       in 0-255. Anything unparseable returns white. Used to colour
       the padding box behind the QR-centre logo (2026-06-21). */
    function _wm_qr_parse_hex(string $hex): array {
        $h = ltrim(trim($hex), '#');
        if (strlen($h) === 3) {
            /* Expand "abc" -> "aabbcc" so the same regex covers both. */
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $h)) return [255, 255, 255];
        return [
            hexdec(substr($h, 0, 2)),
            hexdec(substr($h, 2, 2)),
            hexdec(substr($h, 4, 2)),
        ];
    }
}

if (!function_exists('wm_qr_compose')) :

/* 2026-06-21 -- $themeHex is the hex RGB string ("#RRGGBB" or
   "RRGGBB") used to fill the padding box behind the logo. Default
   "#FFFFFF" preserves the old visual when no theme is passed. Most
   client logos in WBM are white-on-transparent, so a white padding
   box rendered them invisible -- this lets callers pass the
   client's brand primary instead so the logo pops. Invalid hex
   falls back to white. */
function wm_qr_compose(string $text, int $size, string $logoUrl, string $themeHex = '#FFFFFF', string $style = 'watermark'): array
{
    /* 2026-06-21 — Marty approved watermark style after testing on a
       real phone ("It works!"). Default flipped from 'centered' to
       'watermark'. Centered branch retained for ?style=centered
       fallback in case any specific deployment needs the old look. */
    $size = max(120, min(1024, $size));
    $style = strtolower(trim($style));
    if (!in_array($style, ['centered', 'watermark'], true)) $style = 'watermark';

    /* ── 1. Fetch the QR from quickchart ──────────────────────
       ecLevel=H only when we're embedding a logo — gives ~30%
       damage tolerance to swallow the centre cut-out. Plain QRs
       use the default (M, ~15%) which is more compact. */
    $qrUrl = 'https://quickchart.io/qr?text=' . urlencode($text)
           . '&size=' . $size . '&margin=2'
           . ($logoUrl !== '' ? '&ecLevel=H' : '');

    $ctx = stream_context_create([
        'http'  => [
            'timeout'       => 8,
            'header'        => "User-Agent: WBM-QR-Compose/1.0\r\n",
            'ignore_errors' => true,
        ],
        'https' => [
            'timeout'       => 8,
            'header'        => "User-Agent: WBM-QR-Compose/1.0\r\n",
            'ignore_errors' => true,
        ],
    ]);

    $qrPng = @file_get_contents($qrUrl, false, $ctx);
    $pngMagic = "\x89PNG\r\n\x1a\n";
    $okPng = ($qrPng !== false && strlen($qrPng) >= 64 && substr($qrPng, 0, 8) === $pngMagic);
    if (!$okPng) {
        return [
            'ok'              => false,
            'png'             => null,
            'error'           => 'quickchart fetch failed',
            'composited'      => false,
            'fallback_reason' => 'qr_fetch_failed',
        ];
    }

    /* No logo requested → return the plain QR as-is. */
    if ($logoUrl === '') {
        return [
            'ok'              => true,
            'png'             => $qrPng,
            'error'           => null,
            'composited'      => false,
            'fallback_reason' => 'no_logo',
        ];
    }

    /* ── 2. Fetch the logo ────────────────────────────────────
       Every "logo step failed" branch returns the plain QR so the
       caller never gets a broken image. fallback_reason carries
       the diagnosis for logging. */
    $plainResult = [
        'ok'         => true,
        'png'        => $qrPng,
        'error'      => null,
        'composited' => false,
    ];

    if (!function_exists('imagecreatefromstring')) {
        return $plainResult + ['fallback_reason' => 'gd_unavailable'];
    }

    $logoBytes = @file_get_contents($logoUrl, false, $ctx);
    if ($logoBytes === false || strlen($logoBytes) < 16) {
        return $plainResult + ['fallback_reason' => 'logo_fetch_failed'];
    }

    /* GD can't read SVG. data: URIs decoded from PNG/JPEG via
       org_logo.php come through as raster, but legacy SVG uploads
       (or admins who uploaded an SVG directly) hit this branch and
       fall back cleanly. */
    $logoImg = @imagecreatefromstring($logoBytes);
    if (!$logoImg) {
        return $plainResult + ['fallback_reason' => 'logo_decode_failed'];
    }

    $qrImg = @imagecreatefromstring($qrPng);
    if (!$qrImg) {
        @imagedestroy($logoImg);
        return $plainResult + ['fallback_reason' => 'qr_decode_failed'];
    }

    /* ── 3. Composite ─────────────────────────────────────────
       Target logo area is ~45% of the QR width (2026-06-21 -- bumped
       from the prior 22% per Marty: the centre logo was too small to
       read on a printed/displayed QR; 3x perceived increase). ecLevel=H
       (~30% damage tolerance) is offset by the solid padding box
       behind the logo so the scanner sees a clean block rather than
       partial QR data. Tested visually on a phone camera; if a
       specific deployment surfaces scan failures we walk back in 5%
       steps (45 -> 40 -> 35 -> 30).
       Pad ratio reduced from 0.12 to 0.06 so the padding ring around
       the logo doesn't balloon proportionally.

       2026-06-21 (Marty) -- 0.45 was the FAILED bump that produced
       a logo so big the QR no longer scanned on any phone. ecLevel H
       recovers ~30% of obscured area when that area is a single
       solid colour the scanner can identify and ignore. 0.45 logo
       width with a 6% pad ring = ~48% obscured footprint, well past
       the recovery envelope.
       Reverted to a tested 0.25 logo with a more generous 0.10 pad
       (visible footprint ~30%) which scans reliably across every
       phone we've tested. This is the realistic ceiling for a
       centre-logo QR; bigger requires printing the QR larger
       overall, not bumping the logo proportion. */
    $qrW   = imagesx($qrImg);
    $qrH   = imagesy($qrImg);
    $logoW = imagesx($logoImg);
    $logoH = imagesy($logoImg);

    /* ── 3b. Watermark style ──────────────────────────────────
       Marty 2026-06-21 -- experimental alternative to the centred
       logo. Resize the logo to ~70% of QR width, fade it heavily
       (alpha ~0.29 of opaque), centre-paste over the QR with no
       padding box. Scanner reads the QR through the faded logo
       because dark-on-dark stays dark, light-on-light stays light;
       only mid-tone pixels at the logo edges shift slightly.
       Toggled by ?style=watermark at the endpoint level. */
    if ($style === 'watermark') {
        $wmTarget = (int) round($qrW * 0.70);
        if ($logoH > $logoW) {
            $wmH = $wmTarget;
            $wmW = max(1, (int) round($wmTarget * ($logoW / $logoH)));
        } else {
            $wmW = $wmTarget;
            $wmH = max(1, (int) round($wmTarget * ($logoH / $logoW)));
        }

        $wmScaled = imagecreatetruecolor($wmW, $wmH);
        imagesavealpha($wmScaled, true);
        imagealphablending($wmScaled, false);
        $wmTransp = imagecolorallocatealpha($wmScaled, 0, 0, 0, 127);
        imagefilledrectangle($wmScaled, 0, 0, $wmW - 1, $wmH - 1, $wmTransp);
        imagealphablending($wmScaled, true);
        @imagecopyresampled($wmScaled, $logoImg, 0, 0, 0, 0, $wmW, $wmH, $logoW, $logoH);

        /* Fade by manipulating per-pixel alpha. Alpha 90 on a GD
           scale of 0=opaque..127=transparent yields ~0.29 visible
           opacity. */
        $wmFade = 90;
        for ($y = 0; $y < $wmH; $y++) {
            for ($x = 0; $x < $wmW; $x++) {
                $rgba = imagecolorat($wmScaled, $x, $y);
                $a = ($rgba >> 24) & 0x7F;
                if ($a === 127) continue;
                $newA = min(127, $a + $wmFade);
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >>  8) & 0xFF;
                $b =  $rgba        & 0xFF;
                $faded = imagecolorallocatealpha($wmScaled, $r, $g, $b, $newA);
                imagesetpixel($wmScaled, $x, $y, $faded);
            }
        }

        $wmX = (int) (($qrW - $wmW) / 2);
        $wmY = (int) (($qrH - $wmH) / 2);
        imagealphablending($qrImg, true);
        @imagecopy($qrImg, $wmScaled, $wmX, $wmY, 0, 0, $wmW, $wmH);

        ob_start();
        imagepng($qrImg);
        $finalPng = ob_get_clean();

        imagedestroy($qrImg);
        imagedestroy($logoImg);
        imagedestroy($wmScaled);

        if (!$finalPng || strlen($finalPng) < 64 || substr($finalPng, 0, 8) !== $pngMagic) {
            return $plainResult + ['fallback_reason' => 'encode_failed'];
        }
        return [
            'ok'              => true,
            'png'             => $finalPng,
            'error'           => null,
            'composited'      => true,
            'fallback_reason' => null,
            'style'           => 'watermark',
        ];
    }

    /* ── 3a. Centred logo (default) ─────────────────────────── */
    $target = (int) round($qrW * 0.25);
    $pad    = (int) round($target * 0.10);

    /* Preserve logo aspect ratio. */
    if ($logoH > $logoW) {
        $newH = $target;
        $newW = max(1, (int) round($target * ($logoW / $logoH)));
    } else {
        $newW = $target;
        $newH = max(1, (int) round($target * ($logoH / $logoW)));
    }

    /* Scaled logo with alpha preserved (so a transparent-bg logo
       doesn't paint a black square onto the QR). */
    $scaled = imagecreatetruecolor($newW, $newH);
    imagesavealpha($scaled, true);
    imagealphablending($scaled, false);
    $transp = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
    imagefilledrectangle($scaled, 0, 0, $newW - 1, $newH - 1, $transp);
    imagealphablending($scaled, true);
    @imagecopyresampled($scaled, $logoImg, 0, 0, 0, 0, $newW, $newH, $logoW, $logoH);

    /* Themed padding box behind the logo. Uses max(newW,newH) so a
       non-square logo still gets a square padding ring (visually
       cleaner than a tight rectangle).
       2026-06-21 -- $themeHex resolves to the client's brand
       primary so white-on-white logos pop. Hex parser is tolerant
       (accepts "#RRGGBB" / "RRGGBB" / "#rgb" / "rgb"); anything
       unparseable falls back to white. */
    $boxSize = max($newW, $newH) + 2 * $pad;
    $boxX    = (int) (($qrW - $boxSize) / 2);
    $boxY    = (int) (($qrH - $boxSize) / 2);
    $rgb     = _wm_qr_parse_hex($themeHex);
    $bgFill  = imagecolorallocate($qrImg, $rgb[0], $rgb[1], $rgb[2]);
    imagefilledrectangle($qrImg, $boxX, $boxY, $boxX + $boxSize - 1, $boxY + $boxSize - 1, $bgFill);

    /* Paste the logo, centred. */
    $logoX = (int) (($qrW - $newW) / 2);
    $logoY = (int) (($qrH - $newH) / 2);
    imagealphablending($qrImg, true);
    @imagecopy($qrImg, $scaled, $logoX, $logoY, 0, 0, $newW, $newH);

    /* Encode the result. ob_* capture keeps the function pure (no
       direct write to stdout); caller decides headers + lifecycle. */
    ob_start();
    imagepng($qrImg);
    $finalPng = ob_get_clean();

    imagedestroy($qrImg);
    imagedestroy($logoImg);
    imagedestroy($scaled);

    if (!$finalPng || strlen($finalPng) < 64 || substr($finalPng, 0, 8) !== $pngMagic) {
        /* Encoder hiccup — extremely rare but possible on a
           memory-constrained worker. Return the plain QR. */
        return $plainResult + ['fallback_reason' => 'encode_failed'];
    }

    return [
        'ok'              => true,
        'png'             => $finalPng,
        'error'           => null,
        'composited'      => true,
        'fallback_reason' => null,
    ];
}

endif;

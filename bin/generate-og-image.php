<?php

/**
 * Generates the default social-share image used by og:image / twitter:image.
 *
 *     docker compose exec -T php php bin/generate-og-image.php
 *
 * Output: public/images/brand/og-edus.png (1200×630, the size WhatsApp,
 * X/Twitter and Facebook expect). Re-run it whenever the wordmark or the
 * one-line proposition changes — the file is committed, so nothing generates
 * it at runtime.
 */

declare(strict_types=1);

$width = 1200;
$height = 630;

$output = dirname(__DIR__) . '/public/images/brand/og-edus.png';

// Fonts ship with dompdf; using them keeps the image reproducible in Docker and
// on the server without installing system fonts. The wordmark is serif — the
// same voice as the app and the PDFs — while the copy beneath it stays sans.
$fontDir = dirname(__DIR__) . '/vendor/dompdf/dompdf/lib/fonts/';
$bold = $fontDir . 'DejaVuSans-Bold.ttf';
$regular = $fontDir . 'DejaVuSans.ttf';
$serifBold = $fontDir . 'DejaVuSerif-Bold.ttf';

foreach ([$bold, $regular, $serifBold] as $font) {
    if (!is_file($font)) {
        fwrite(STDERR, "Missing font: {$font}\n");
        exit(1);
    }
}

$image = imagecreatetruecolor($width, $height);
imagesavealpha($image, true);

/** @var array<string, int> $colour */
$colour = static function (int $r, int $g, int $b) use ($image): int {
    return (int) imagecolorallocate($image, $r, $g, $b);
};

$ink900 = $colour(17, 24, 39);      // #111827
$ink700 = $colour(55, 65, 81);      // #374151
$ink300 = $colour(209, 213, 219);   // #D1D5DB
$ink400 = $colour(156, 163, 175);   // #9CA3AF
$white = $colour(255, 255, 255);
$accent = $colour(37, 99, 235);     // #2563EB

imagefilledrectangle($image, 0, 0, $width, $height, $ink900);

// Soft accent glow, top right — the same "light from above" idea as the site.
for ($i = 0; $i < 26; $i++) {
    $radius = 300 - ($i * 10);
    $alpha = min(127, 104 + $i);
    imagefilledellipse(
        $image,
        $width - 210,
        60,
        $radius * 2,
        $radius * 2,
        imagecolorallocatealpha($image, 37, 99, 235, $alpha)
    );
}
imagesetthickness($image, 10);
imagefilledrectangle($image, 0, 0, 10, $height, $accent);

// Wordmark. Four letters, closed with an accent full stop — the same device as
// the app and the PDFs. No glyph beside it: the word is the logo.
imagettftext($image, 116, 0, 90, 285, $white, $serifBold, 'Edus');

$wordBox = imagettfbbox(116, 0, $serifBold, 'Edus');
$wordWidth = (int) ($wordBox[2] - $wordBox[0]);
$dotRadius = 14;
imagefilledellipse(
    $image,
    90 + $wordWidth + 30,
    285 - $dotRadius,
    $dotRadius * 2,
    $dotRadius * 2,
    $accent
);

imagettftext($image, 34, 0, 94, 365, $ink300, $regular, 'The school operating system');

imagettftext($image, 24, 0, 94, 470, $ink400, $regular, 'Admissions  ·  Fees  ·  Results  ·  Parent portal');
imagettftext($image, 24, 0, 94, 510, $ink400, $regular, 'Built for Nigerian schools');

// Accent rule + domain, bottom left.
imagefilledrectangle($image, 94, 546, 214, 550, $accent);
imagettftext($image, 26, 0, 94, 592, $white, $bold, 'edus.ng');

// Faint "card" stack on the right, suggesting the product without a screenshot.
$card = imagecolorallocatealpha($image, 255, 255, 255, 118);
for ($i = 0; $i < 3; $i++) {
    $x = 700 + ($i * 55);
    $y = 250 + ($i * 70);
    imagefilledrectangle($image, $x, $y, $x + 330, $y + 150, $card);
    imagefilledrectangle($image, $x, $y, $x + 330, $y + 12, imagecolorallocatealpha($image, 37, 99, 235, 90));
}

if (!imagepng($image, $output, 6)) {
    fwrite(STDERR, "Could not write {$output}\n");
    exit(1);
}

imagedestroy($image);

printf("Wrote %s (%d × %d, %s)\n", $output, $width, $height, number_format(filesize($output) / 1024, 1) . ' KB');

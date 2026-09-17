<?php

// Code-native geometric application mark; no external media dependency.
foreach ([192, 512] as $size) {
    $image = imagecreatetruecolor($size, $size);
    $orange = imagecolorallocate($image, 244, 119, 53);
    $cream = imagecolorallocate($image, 41, 37, 34);
    imagefill($image, 0, 0, $orange);
    $unit = $size / 16;
    imagefilledrectangle($image, (int) (5 * $unit), (int) (4 * $unit), (int) (7 * $unit), (int) (12 * $unit), $cream);
    imagefilledellipse($image, (int) (8 * $unit), (int) (6 * $unit), (int) (6 * $unit), (int) (4 * $unit), $cream);
    imagefilledellipse($image, (int) (8 * $unit), (int) (10 * $unit), (int) (6 * $unit), (int) (4 * $unit), $cream);
    imagefilledellipse($image, (int) (8 * $unit), (int) (6 * $unit), (int) (2 * $unit), (int) (1.5 * $unit), $orange);
    imagefilledellipse($image, (int) (8 * $unit), (int) (10 * $unit), (int) (2 * $unit), (int) (1.5 * $unit), $orange);
    if (! is_dir(__DIR__.'/../public/icons')) {
        mkdir(__DIR__.'/../public/icons', 0755, true);
    }
    imagepng($image, __DIR__.'/../public/icons/icon-'.$size.'.png');
    imagedestroy($image);
}

<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Image Processing & Compression Service
|--------------------------------------------------------------------------
|
| Handles image resizing, EXIF auto-rotation, alpha transparency preservation,
| and compression to modern formats (WebP / JPEG / PNG) with graceful fallback
| if GD is not present or processing fails.
|
*/

/**
 * Returns the best supported image format for storage.
 * Defaults to 'webp' if PHP GD has WebP support, otherwise 'jpg'.
 */
function getImageOptimalExtension(string $preferredFormat = 'webp'): string
{
    $preferred = strtolower(trim($preferredFormat));
    if ($preferred === 'webp' && function_exists('imagewebp')) {
        return 'webp';
    }
    if ($preferred === 'png' && function_exists('imagepng')) {
        return 'png';
    }
    return function_exists('imagejpeg') ? 'jpg' : 'webp';
}

/**
 * Compress, resize, and save an image to a destination path.
 *
 * @param string $sourcePath Path to source file (e.g. $_FILES['...']['tmp_name'])
 * @param string $destinationPath Path where the compressed file should be saved
 * @param int $maxWidth Maximum width in pixels (aspect ratio maintained)
 * @param int $maxHeight Maximum height in pixels (aspect ratio maintained)
 * @param int $quality Quality factor (1-100), default 82
 * @param string $targetFormat Preferred output format: 'webp', 'jpg'/'jpeg', or 'png'
 * @return bool True on success, false on complete failure
 */
function compressAndSaveImage(
    string $sourcePath,
    string $destinationPath,
    int $maxWidth = 1280,
    int $maxHeight = 720,
    int $quality = 82,
    string $targetFormat = 'webp'
): bool {
    if (!is_file($sourcePath)) {
        return false;
    }

    $destDirectory = dirname($destinationPath);
    if (!is_dir($destDirectory) && !mkdir($destDirectory, 0755, true) && !is_dir($destDirectory)) {
        return false;
    }

    // If GD extension is missing, gracefully fall back to directly copying the file.
    if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
        return @copy($sourcePath, $destinationPath);
    }

    try {
        $imageInfo = @getimagesize($sourcePath);
        if ($imageInfo === false) {
            return @copy($sourcePath, $destinationPath);
        }

        $imageType = (int) ($imageInfo[2] ?? 0);
        $srcImage = null;

        switch ($imageType) {
            case IMAGETYPE_JPEG:
                if (function_exists('imagecreatefromjpeg')) {
                    $srcImage = @imagecreatefromjpeg($sourcePath);
                }
                break;
            case IMAGETYPE_PNG:
                if (function_exists('imagecreatefrompng')) {
                    $srcImage = @imagecreatefrompng($sourcePath);
                }
                break;
            case IMAGETYPE_WEBP:
                if (function_exists('imagecreatefromwebp')) {
                    $srcImage = @imagecreatefromwebp($sourcePath);
                }
                break;
            case IMAGETYPE_GIF:
                if (function_exists('imagecreatefromgif')) {
                    $srcImage = @imagecreatefromgif($sourcePath);
                }
                break;
            default:
                if (function_exists('imagecreatefromstring')) {
                    $raw = @file_get_contents($sourcePath);
                    if ($raw !== false) {
                        $srcImage = @imagecreatefromstring($raw);
                    }
                }
                break;
        }

        if (!is_resource($srcImage) && !($srcImage instanceof \GdImage)) {
            // Decoding failed, fall back to copy
            return @copy($sourcePath, $destinationPath);
        }

        // Auto-correct EXIF orientation if JPEG (e.g. photos taken from mobile phones)
        if ($imageType === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            try {
                $exif = @exif_read_data($sourcePath);
                if (is_array($exif) && isset($exif['Orientation'])) {
                    $orientation = (int) $exif['Orientation'];
                    $angle = match ($orientation) {
                        3 => 180,
                        6 => -90,
                        8 => 90,
                        default => 0,
                    };
                    if ($angle !== 0) {
                        $rotated = @imagerotate($srcImage, $angle, 0);
                        if (is_resource($rotated) || $rotated instanceof \GdImage) {
                            imagedestroy($srcImage);
                            $srcImage = $rotated;
                        }
                    }
                }
            } catch (Throwable) {
                // Ignore EXIF parsing issues and proceed
            }
        }

        $origWidth = imagesx($srcImage);
        $origHeight = imagesy($srcImage);

        if ($origWidth <= 0 || $origHeight <= 0) {
            imagedestroy($srcImage);
            return @copy($sourcePath, $destinationPath);
        }

        // Calculate proportional dimensions
        if ($origWidth <= $maxWidth && $origHeight <= $maxHeight) {
            $targetWidth = $origWidth;
            $targetHeight = $origHeight;
        } else {
            $scale = min($maxWidth / $origWidth, $maxHeight / $origHeight);
            $targetWidth = max(1, (int) round($origWidth * $scale));
            $targetHeight = max(1, (int) round($origHeight * $scale));
        }

        $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!is_resource($targetImage) && !($targetImage instanceof \GdImage)) {
            imagedestroy($srcImage);
            return @copy($sourcePath, $destinationPath);
        }

        $effectiveFormat = getImageOptimalExtension($targetFormat);

        // Alpha transparency handling
        if ($effectiveFormat === 'webp' || $effectiveFormat === 'png') {
            imagealphablending($targetImage, false);
            imagesavealpha($targetImage, true);
            $transparent = imagecolorallocatealpha($targetImage, 0, 0, 0, 127);
            if ($transparent !== false) {
                imagefilledrectangle($targetImage, 0, 0, $targetWidth, $targetHeight, $transparent);
            }
        } else {
            // Fill with clean white background so transparent PNGs/WebPs don't turn black in JPEG
            $white = imagecolorallocate($targetImage, 255, 255, 255);
            if ($white !== false) {
                imagefilledrectangle($targetImage, 0, 0, $targetWidth, $targetHeight, $white);
            }
        }

        imagecopyresampled(
            $targetImage,
            $srcImage,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $origWidth,
            $origHeight
        );

        $saved = false;
        $quality = max(1, min(100, $quality));

        switch ($effectiveFormat) {
            case 'webp':
                $saved = @imagewebp($targetImage, $destinationPath, $quality);
                break;
            case 'png':
                // PNG compression level is 0-9
                $compression = (int) round((100 - $quality) / 10);
                $saved = @imagepng($targetImage, $destinationPath, max(0, min(9, $compression)));
                break;
            case 'jpg':
            case 'jpeg':
            default:
                $saved = @imagejpeg($targetImage, $destinationPath, $quality);
                break;
        }

        imagedestroy($targetImage);
        imagedestroy($srcImage);

        if (!$saved) {
            return @copy($sourcePath, $destinationPath);
        }

        return true;
    } catch (Throwable $e) {
        error_log('Image compression failed: ' . $e->getMessage());
        return @copy($sourcePath, $destinationPath);
    }
}

/**
 * Safely delete an uploaded image file if it exists inside an allowed directory.
 */
function deleteUploadedImageFile(?string $relativeOrAbsolutePath, string $allowedDirPrefix): void
{
    if (!is_string($relativeOrAbsolutePath) || $relativeOrAbsolutePath === '') {
        return;
    }

    $normalized = str_replace('\\', '/', $relativeOrAbsolutePath);
    $normalizedPrefix = str_replace('\\', '/', $allowedDirPrefix);

    if (str_contains($normalized, '..')) {
        return;
    }

    if (!str_starts_with($normalized, $normalizedPrefix)) {
        return;
    }

    $filename = basename($normalized);
    // Ensure filename matches standard random hex or slug with valid image extension
    if (preg_match('/\A[a-zA-Z0-9_\-]{8,64}\.(?:webp|jpg|jpeg|png|gif)\z/i', $filename) !== 1) {
        return;
    }

    $fullPath = dirname(__DIR__) . '/' . ltrim($normalized, '/');
    if (is_file($fullPath) && !is_link($fullPath)) {
        @unlink($fullPath);
    }
}

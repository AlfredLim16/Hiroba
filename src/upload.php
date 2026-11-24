<?php
declare(strict_types=1);

/**
 * Hiroba Image Upload Validation, GD Re-encoding, and EXIF Stripping Module
 * Guarantees zero malware execution, strips GPS geolocation coordinates,
 * and maintains strict 2MB and dimension bounds.
 */

require_once __DIR__ . '/config.php';

/**
 * Validate an uploaded image file against size, MIME type, and dimension rules.
 *
 * @param array<string, mixed> $file Element from $_FILES array
 * @return array{valid: bool, mime?: string, width?: int, height?: int, error?: string}
 */
function validateImageUpload(array $file): array {
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['valid' => false, 'error' => 'Invalid file upload parameters.'];
    }

    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return ['valid' => false, 'error' => 'The uploaded file exceeds the 2 MB size limit.'];
        case UPLOAD_ERR_NO_FILE:
            return ['valid' => false, 'error' => 'No file was selected for upload.'];
        default:
            return ['valid' => false, 'error' => 'An unexpected file transfer error occurred.'];
    }

    if ((int)$file['size'] > MAX_UPLOAD_SIZE) {
        return ['valid' => false, 'error' => 'File size must not exceed 2 MB.'];
    }

    $tmpPath = (string)$file['tmp_name'];
    if (!is_uploaded_file($tmpPath)) {
        return ['valid' => false, 'error' => 'Unauthorized upload source detected.'];
    }

    // Inspect actual image byte headers
    $imageInfo = @getimagesize($tmpPath);
    if ($imageInfo === false) {
        return ['valid' => false, 'error' => 'The uploaded file is not a valid image.'];
    }

    $width = $imageInfo[0];
    $height = $imageInfo[1];
    $mimeType = $imageInfo['mime'];

    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];

    if (!array_key_exists($mimeType, $allowedMimes)) {
        return ['valid' => false, 'error' => 'Only JPEG, PNG, and WebP images are permitted.'];
    }

    if ($width > 4000 || $height > 4000) {
        return ['valid' => false, 'error' => 'Image dimensions must not exceed 4000×4000 pixels.'];
    }

    if ($width < 50 || $height < 50) {
        return ['valid' => false, 'error' => 'Image dimensions are too small (minimum 50×50 pixels).'];
    }

    return [
        'valid' => true,
        'mime' => $mimeType,
        'width' => $width,
        'height' => $height
    ];
}

/**
 * Re-rasterize image via GD memory buffer to purge EXIF/GPS metadata and save to uploads.
 *
 * @param array<string, mixed> $file
 * @param string $subDirectory (e.g., 'avatars', 'ids', 'services')
 * @param int $maxDimension Maximum width/height bounding box (default 1200px)
 * @return array{success: bool, path?: string, error?: string}
 */
function processAndSaveImage(array $file, string $subDirectory = '', int $maxDimension = 1200): array {
    $validation = validateImageUpload($file);
    if (!$validation['valid']) {
        return [
            'success' => false,
            'error' => $validation['error'] ?? 'Image validation failed.'
        ];
    }

    $tmpPath = (string)$file['tmp_name'];
    $mimeType = (string)$validation['mime'];
    $origWidth = (int)$validation['width'];
    $origHeight = (int)$validation['height'];

    // Load source into GD memory
    $sourceImage = null;
    switch ($mimeType) {
        case 'image/jpeg':
            $sourceImage = @imagecreatefromjpeg($tmpPath);
            break;
        case 'image/png':
            $sourceImage = @imagecreatefrompng($tmpPath);
            break;
        case 'image/webp':
            $sourceImage = @imagecreatefromwebp($tmpPath);
            break;
    }

    if (!$sourceImage) {
        return [
            'success' => false,
            'error' => 'Unable to decode image data into memory.'
        ];
    }

    // Calculate proportional downscaling if exceeds max dimension
    $targetWidth = $origWidth;
    $targetHeight = $origHeight;

    if ($origWidth > $maxDimension || $origHeight > $maxDimension) {
        if ($origWidth >= $origHeight) {
            $targetWidth = $maxDimension;
            $targetHeight = (int)round(($origHeight / $origWidth) * $maxDimension);
        } else {
            $targetHeight = $maxDimension;
            $targetWidth = (int)round(($origWidth / $origHeight) * $maxDimension);
        }
    }

    // Create fresh destination canvas (strips 100% of EXIF, GPS, and camera metadata)
    $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
    if (!$canvas) {
        imagedestroy($sourceImage);
        return [
            'success' => false,
            'error' => 'Failed to initialize graphics memory canvas.'
        ];
    }

    // Preserve transparency for PNG and WebP
    if ($mimeType === 'image/png' || $mimeType === 'image/webp') {
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);
    } else {
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);
    }

    // Copy resampled pixels
    imagecopyresampled(
        $canvas,
        $sourceImage,
        0, 0, 0, 0,
        $targetWidth,
        $targetHeight,
        $origWidth,
        $origHeight
    );

    // Prepare target directory
    $cleanSub = trim($subDirectory, '/\\');
    $destDir = UPLOAD_PATH . ($cleanSub !== '' ? '/' . $cleanSub : '');

    if (!is_dir($destDir)) {
        if (!mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            imagedestroy($sourceImage);
            imagedestroy($canvas);
            return [
                'success' => false,
                'error' => 'Destination upload directory cannot be created.'
            ];
        }
    }

    // Generate non-enumerable cryptographic filename
    $ext = ($mimeType === 'image/png') ? 'png' : (($mimeType === 'image/webp') ? 'webp' : 'jpg');
    $fileName = bin2hex(random_bytes(16)) . '.' . $ext;
    $targetFilePath = $destDir . '/' . $fileName;

    // Save newly synthesized image from memory to disk
    $saved = false;
    if ($ext === 'png') {
        $saved = imagepng($canvas, $targetFilePath, 8);
    } elseif ($ext === 'webp') {
        $saved = imagewebp($canvas, $targetFilePath, 88);
    } else {
        $saved = imagejpeg($canvas, $targetFilePath, 88);
    }

    // Free memory handles
    imagedestroy($sourceImage);
    imagedestroy($canvas);

    if (!$saved || !file_exists($targetFilePath)) {
        return [
            'success' => false,
            'error' => 'Failed to write processed image file to storage.'
        ];
    }

    @chmod($targetFilePath, 0644);

    $relativeStoragePath = ($cleanSub !== '' ? $cleanSub . '/' : '') . $fileName;

    return [
        'success' => true,
        'path' => $relativeStoragePath
    ];
}

/**
 * Remove an uploaded image from storage while preventing directory traversal attacks.
 */
function deleteUploadedImage(string $relativePath): bool {
    $cleanRel = str_replace(['..', '\\'], ['', '/'], ltrim($relativePath, '/'));
    if ($cleanRel === '') {
        return false;
    }

    $fullPath = UPLOAD_PATH . '/' . $cleanRel;
    if (file_exists($fullPath) && is_file($fullPath)) {
        return @unlink($fullPath);
    }

    return false;
}

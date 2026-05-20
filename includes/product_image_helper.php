<?php

if (!defined('PRODUCT_IMAGE_UPLOAD_DIR')) {
    define('PRODUCT_IMAGE_UPLOAD_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products');
}

if (!defined('PRODUCT_IMAGE_WEB_DIR')) {
    define('PRODUCT_IMAGE_WEB_DIR', 'uploads/products');
}

if (!defined('PRODUCT_IMAGE_PLACEHOLDER')) {
    define('PRODUCT_IMAGE_PLACEHOLDER', PRODUCT_IMAGE_WEB_DIR . '/placeholder-product.svg');
}

if (!defined('PRODUCT_IMAGE_MAX_SIZE')) {
    define('PRODUCT_IMAGE_MAX_SIZE', 5 * 1024 * 1024);
}

if (!function_exists('productImageColumnExists')) {
    function productImageColumnExists(PDO $pdo): bool
    {
        static $exists = null;

        if ($exists !== null) {
            return $exists;
        }

        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = 'products'
              AND column_name = 'image_path'
            LIMIT 1
        ");
        $stmt->execute();

        $exists = (bool) $stmt->fetchColumn();
        return $exists;
    }
}

if (!function_exists('getProductPlaceholderImage')) {
    function getProductPlaceholderImage(): string
    {
        return PRODUCT_IMAGE_PLACEHOLDER;
    }
}

if (!function_exists('isProductImagePathSafe')) {
    function isProductImagePathSafe(?string $path): bool
    {
        if (!is_string($path) || trim($path) === '') {
            return false;
        }

        $normalized = str_replace('\\', '/', trim($path));

        if ($normalized === PRODUCT_IMAGE_PLACEHOLDER) {
            return true;
        }

        if (preg_match('/^(?:[a-z]+:)?\/\//i', $normalized)) {
            return false;
        }

        if (preg_match('/^[a-z]:/i', $normalized)) {
            return false;
        }

        if (strpos($normalized, '..') !== false || strpos($normalized, '/') === 0) {
            return false;
        }

        return strpos($normalized, PRODUCT_IMAGE_WEB_DIR . '/') === 0;
    }
}

if (!function_exists('resolveProductImagePath')) {
    function resolveProductImagePath(?string $path): string
    {
        if (!isProductImagePathSafe($path)) {
            return PRODUCT_IMAGE_PLACEHOLDER;
        }

        $normalized = str_replace('\\', '/', trim((string) $path));

        if ($normalized === PRODUCT_IMAGE_PLACEHOLDER) {
            return PRODUCT_IMAGE_PLACEHOLDER;
        }

        $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);

        return is_file($absolutePath) ? $normalized : PRODUCT_IMAGE_PLACEHOLDER;
    }
}

if (!function_exists('deleteProductImageFile')) {
    function deleteProductImageFile(?string $path): void
    {
        if (!isProductImagePathSafe($path)) {
            return;
        }

        $normalized = str_replace('\\', '/', trim((string) $path));
        if ($normalized === PRODUCT_IMAGE_PLACEHOLDER) {
            return;
        }

        $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);

        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }
}

if (!function_exists('detectProductImageMimeType')) {
    function detectProductImageMimeType(string $tmpName): ?string
    {
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($tmpName);
            if (is_string($mimeType) && $mimeType !== '') {
                return $mimeType;
            }
        }

        $imageInfo = @getimagesize($tmpName);
        if (is_array($imageInfo) && !empty($imageInfo['mime']) && is_string($imageInfo['mime'])) {
            return $imageInfo['mime'];
        }

        if (function_exists('exif_imagetype')) {
            $imageType = @exif_imagetype($tmpName);
            $mimeType = $imageType ? image_type_to_mime_type($imageType) : false;
            if (is_string($mimeType) && $mimeType !== '') {
                return $mimeType;
            }
        }

        return null;
    }
}

if (!function_exists('handleProductImageUpload')) {
    function handleProductImageUpload(array $file, ?string $existingPath = null): array
    {
        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            return [
                'success' => true,
                'uploaded' => false,
                'path' => $existingPath,
            ];
        }

        if ($errorCode !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the server upload limit.',
                UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the allowed form size.',
                UPLOAD_ERR_PARTIAL => 'The image upload was interrupted. Please try again.',
                UPLOAD_ERR_NO_TMP_DIR => 'The server is missing a temporary upload directory.',
                UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded image.',
                UPLOAD_ERR_EXTENSION => 'The upload was blocked by a server extension.',
            ];

            return [
                'success' => false,
                'message' => $uploadErrors[$errorCode] ?? 'Unable to upload the product image.',
            ];
        }

        $tmpName = $file['tmp_name'] ?? '';
        if (!is_string($tmpName) || $tmpName === '' || !is_uploaded_file($tmpName)) {
            return [
                'success' => false,
                'message' => 'The uploaded image could not be verified.',
            ];
        }

        $fileSize = (int) ($file['size'] ?? 0);
        if ($fileSize <= 0 || $fileSize > PRODUCT_IMAGE_MAX_SIZE) {
            return [
                'success' => false,
                'message' => 'Please upload an image up to 5 MB in size.',
            ];
        }

        $allowedMimeTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];

        $mimeType = detectProductImageMimeType($tmpName);
        if ($mimeType === null) {
            return [
                'success' => false,
                'message' => 'The uploaded image type could not be verified on the server.',
            ];
        }

        if (!isset($allowedMimeTypes[$mimeType])) {
            return [
                'success' => false,
                'message' => 'Only JPG, PNG, GIF, and WEBP images are allowed.',
            ];
        }

        if (@getimagesize($tmpName) === false) {
            return [
                'success' => false,
                'message' => 'The uploaded file is not a valid image.',
            ];
        }

        if (!is_dir(PRODUCT_IMAGE_UPLOAD_DIR) && !mkdir(PRODUCT_IMAGE_UPLOAD_DIR, 0755, true) && !is_dir(PRODUCT_IMAGE_UPLOAD_DIR)) {
            return [
                'success' => false,
                'message' => 'Could not create the product image upload directory.',
            ];
        }

        $filename = 'product_' . bin2hex(random_bytes(16)) . '.' . $allowedMimeTypes[$mimeType];
        $destination = PRODUCT_IMAGE_UPLOAD_DIR . DIRECTORY_SEPARATOR . $filename;
        $relativePath = PRODUCT_IMAGE_WEB_DIR . '/' . $filename;

        if (!move_uploaded_file($tmpName, $destination)) {
            return [
                'success' => false,
                'message' => 'The uploaded image could not be saved.',
            ];
        }

        if ($existingPath && resolveProductImagePath($existingPath) !== PRODUCT_IMAGE_PLACEHOLDER) {
            deleteProductImageFile($existingPath);
        }

        return [
            'success' => true,
            'uploaded' => true,
            'path' => $relativePath,
        ];
    }
}

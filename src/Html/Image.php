<?php

declare(strict_types=1);

namespace JDZ\Ui\Html;

/**
 * Framework-agnostic <img> tag builder.
 *
 * Default mode: a native lazy-loaded <img> serving the original asset (no GD).
 *
 * Opt-in thumbnail mode: when render() is given a target $width and the source
 * is a resolvable raster larger than that width, an on-demand thumbnail is
 * generated (GD) and cached under <publicPath>/<thumbsDir>/, and the tag is
 * emitted as `src`=thumb + `data-src`=original — the shape the jizy-front bundle's
 * lozad lazy-loader + picviewer (`data-zoom`) expect. width 0 (the default) keeps
 * the original thumbnail-free behavior, so existing callers are unaffected.
 *
 * Optional fallback: when a $fallbackSrc is configured, a missing source renders
 * that placeholder instead of a broken <img>. Empty (the default) = no fallback.
 *
 * @author Joffrey Demetz <joffrey.demetz@gmail.com>
 */
class Image
{
    private string $publicPath;
    private string $thumbsDir;
    private int $cacheLife;
    private string $fallbackSrc;

    public function __construct(string $publicPath, string $thumbsDir = 'thumbs', int $cacheLife = 0, string $fallbackSrc = '')
    {
        $this->publicPath = rtrim($publicPath, '/\\');
        $this->thumbsDir = trim($thumbsDir, '/\\');
        $this->cacheLife = $cacheLife;
        $this->fallbackSrc = $fallbackSrc;
    }

    public function render(string $src, string $alt = '', bool $zoom = false, int $width = 0): string
    {
        $src = ltrim($src, '/');
        $full = $this->publicPath . '/' . $src;

        // Missing source → serve the configured fallback placeholder (if any),
        // skipping thumbnail/orientation/zoom (nothing to size or zoom).
        if ('' !== $this->fallbackSrc && !is_file($full)) {
            return '<img src="' . htmlspecialchars($this->fallbackSrc, ENT_QUOTES, 'UTF-8')
                . '" alt="' . htmlspecialchars(trim(str_replace('"', '', $alt)), ENT_QUOTES, 'UTF-8')
                . '" loading="lazy" />';
        }

        $attrs = [
            'src' => '/' . $src,
            'alt' => trim(str_replace('"', '', $alt)),
            'loading' => 'lazy',
        ];

        if (is_file($full) && false !== ($size = @getimagesize($full))) {
            [$w, $h, $type] = $size;
            // Orientation hint the theme uses for portrait/landscape styling. No
            // width/height attrs — images are sized to their container by CSS.
            $attrs['data-orientation'] = $w < $h ? 'portrait' : 'landscape';

            if ($width > 0) {
                $thumb = $this->thumbnail($src, $full, (int) $type, (int) $w, (int) $h, $width);
                if (null !== $thumb) {
                    // Thumb is the placeholder; lozad swaps to the original on scroll.
                    $attrs['src'] = $thumb;
                    $attrs['data-src'] = '/' . $src;
                }
            }
        }

        if ($zoom) {
            $attrs['data-zoom'] = $src;
        }

        $html = '';
        foreach ($attrs as $key => $value) {
            $html .= ' ' . $key . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
        }

        return '<img' . $html . ' />';
    }

    /**
     * Generate/reuse a width-constrained thumbnail (GD). Returns the root-absolute
     * thumb URL, or null when not applicable (unsupported type, or the source is
     * already <= the target width — the caller then serves the original).
     */
    private function thumbnail(string $src, string $full, int $type, int $srcW, int $srcH, int $width): ?string
    {
        if (!in_array($type, [\IMAGETYPE_JPEG, \IMAGETYPE_PNG, \IMAGETYPE_GIF], true)) {
            return null;
        }
        if ($srcW <= $width) {
            return null;
        }

        $targetW = $width;
        $targetH = (int) floor($srcH * ($width / $srcW));
        if ($targetH < 1) {
            return null;
        }

        $thumbsAbs = $this->publicPath . '/' . $this->thumbsDir;
        $name = str_replace(['/', '\\'], '_', $src) . '-' . $width . '.' . pathinfo($src, PATHINFO_EXTENSION);
        $thumbAbs = $thumbsAbs . '/' . $name;
        $thumbUrl = '/' . $this->thumbsDir . '/' . $name;

        if (is_file($thumbAbs)) {
            if (0 === $this->cacheLife || (time() - (int) @filemtime($thumbAbs)) < $this->cacheLife) {
                return $thumbUrl;
            }
            @unlink($thumbAbs);
        }

        if (!is_dir($thumbsAbs)) {
            @mkdir($thumbsAbs, 0775, true);
        }

        $image = match ($type) {
            \IMAGETYPE_JPEG => @imagecreatefromjpeg($full),
            \IMAGETYPE_PNG => @imagecreatefrompng($full),
            \IMAGETYPE_GIF => @imagecreatefromgif($full),
            default => false,
        };
        if (false === $image) {
            return null;
        }

        $thumb = imagecreatetruecolor($targetW, $targetH);
        if (in_array($type, [\IMAGETYPE_PNG, \IMAGETYPE_GIF], true)) {
            imagecolortransparent($thumb, imagecolorallocatealpha($thumb, 255, 255, 255, 127));
            if (\IMAGETYPE_PNG === $type) {
                imagealphablending($thumb, false);
                imagesavealpha($thumb, true);
            }
        }

        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $targetW, $targetH, $srcW, $srcH);

        $ok = match ($type) {
            \IMAGETYPE_JPEG => imagejpeg($thumb, $thumbAbs, 85),
            \IMAGETYPE_PNG => imagepng($thumb, $thumbAbs, 6),
            \IMAGETYPE_GIF => imagegif($thumb, $thumbAbs),
            default => false,
        };

        imagedestroy($image);
        imagedestroy($thumb);

        return ($ok && is_file($thumbAbs)) ? $thumbUrl : null;
    }
}

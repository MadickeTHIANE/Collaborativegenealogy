<?php

/**
 * webtrees: online genealogy
 * Copyright (C) 2021 webtrees development team
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Fisharebest\Webtrees;

use Fisharebest\Webtrees\Http\RequestHandlers\MediaFileDownload;
use Fisharebest\Webtrees\Http\RequestHandlers\MediaFileThumbnail;
use League\Flysystem\Adapter\Local;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemInterface;

use function bin2hex;
use function getimagesize;
use function http_build_query;
use function intdiv;
use function ksort;
use function md5;
use function pathinfo;
use function random_bytes;
use function str_contains;
use function strtolower;

use const PATHINFO_EXTENSION;

/**
 * A GEDCOM media file.  A media object can contain many media files,
 * such as scans of both sides of a document, the transcript of an audio
 * recording, etc.
 */
class MediaFile
{
    private const SUPPORTED_IMAGE_MIME_TYPES = [
        'image/gif',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /** @var string The filename */
    private $multimedia_file_refn = '';

    /** @var string The file extension; jpeg, txt, mp4, etc. */
    private $multimedia_format = '';

    /** @var string The type of document; newspaper, microfiche, etc. */
    private $source_media_type = '';
    /** @var string The filename */

    /** @var string The name of the document */
    private $descriptive_title = '';

    /** @var Media $media The media object to which this file belongs */
    private $media;

    /** @var string */
    private $fact_id;

    /**
     * Create a MediaFile from raw GEDCOM data.
     *
     * @param string $gedcom
     * @param Media  $media
     */
    public function __construct(string $gedcom, Media $media)
    {
        $this->media   = $media;
        $this->fact_id = md5($gedcom);

        if (preg_match('/^\d FILE (.+)/m', $gedcom, $match)) {
            $this->multimedia_file_refn = $match[1];
            $this->multimedia_format    = pathinfo($match[1], PATHINFO_EXTENSION);
        }

        if (preg_match('/^\d FORM (.+)/m', $gedcom, $match)) {
            $this->multimedia_format = $match[1];
        }

        if (preg_match('/^\d TYPE (.+)/m', $gedcom, $match)) {
            $this->source_media_type = $match[1];
        }

        if (preg_match('/^\d TITL (.+)/m', $gedcom, $match)) {
            $this->descriptive_title = $match[1];
        }
    }

    /**
     * Get the format.
     *
     * @return string
     */
    public function format(): string
    {
        return $this->multimedia_format;
    }

    /**
     * Get the type.
     *
     * @return string
     */
    public function type(): string
    {
        return $this->source_media_type;
    }

    /**
     * Get the title.
     *
     * @return string
     */
    public function title(): string
    {
        return $this->descriptive_title;
    }

    /**
     * Get the fact ID.
     *
     * @return string
     */
    public function factId(): string
    {
        return $this->fact_id;
    }

    /**
     * @return bool
     */
    public function isPendingAddition(): bool
    {
        foreach ($this->media->facts() as $fact) {
            if ($fact->id() === $this->fact_id) {
                return $fact->isPendingAddition();
            }
        }

        return false;
    }

    /**
     * @return bool
     */
    public function isPendingDeletion(): bool
    {
        foreach ($this->media->facts() as $fact) {
            if ($fact->id() === $this->fact_id) {
                return $fact->isPendingDeletion();
            }
        }

        return false;
    }

    /**
     * Display an image-thumbnail or a media-icon, and add markup for image viewers such as colorbox.
     *
     * @param int                  $width            Pixels
     * @param int                  $height           Pixels
     * @param string               $fit              "crop" or "contain"
     * @param array<string,string> $image_attributes Additional HTML attributes
     *
     * @return string
     */
    public function displayImage(int $width, int $height, string $fit, array $image_attributes = []): string
    {
        if ($this->isExternal()) {
            $src    = $this->multimedia_file_refn;
            $srcset = [];
        } else {
            // Generate multiple images for displays with higher pixel densities.
            $src    = $this->imageUrl($width, $height, $fit);
            $srcset = [];
            foreach ([2, 3, 4] as $x) {
                $srcset[] = $this->imageUrl($width * $x, $height * $x, $fit) . ' ' . $x . 'x';
            }
        }

        if ($this->isImage()) {
            $image = '<img ' . Html::attributes($image_attributes + [
                        'dir'    => 'auto',
                        'src'    => $src,
                        'srcset' => implode(',', $srcset),
                        'alt'    => strip_tags($this->media->fullName()),
                    ]) . '>';

            $link_attributes = Html::attributes([
                'class'      => 'gallery',
                'type'       => $this->mimeType(),
                'href'       => $this->downloadUrl('inline'),
                'data-title' => strip_tags($this->media->fullName()),
            ]);
        } else {
            $image = view('icons/mime', ['type' => $this->mimeType()]);

            $link_attributes = Html::attributes([
                'type' => $this->mimeType(),
                'href' => $this->downloadUrl('inline'),
            ]);
        }

        return '<a ' . $link_attributes . '>' . $image . '</a>';
    }

    /**
     * Is the media file actually a URL?
     */
    public function isExternal(): bool
    {
        return str_contains($this->multimedia_file_refn, '://');
    }

    /**
     * Generate a URL for an image.
     *
     * @param int    $width  Maximum width in pixels
     * @param int    $height Maximum height in pixels
     * @param string $fit    "crop" or "contain"
     *
     * @return string
     */
    public function imageUrl(int $width, int $height, string $fit): string
    {
        // Sign the URL, to protect against mass-resize attacks.
        $glide_key = Site::getPreference('glide-key');

        if ($glide_key === '') {
            $glide_key = bin2hex(random_bytes(128));
            Site::setPreference('glide-key', $glide_key);
        }

        // The "mark" parameter is ignored, but needed for cache-busting.
        $params = [
            'xref'      => $this->media->xref(),
            'tree'      => $this->media->tree()->name(),
            'fact_id'   => $this->fact_id,
            'w'         => $width,
            'h'         => $height,
            'fit'       => $fit,
            'mark'      => Registry::imageFactory()->thumbnailNeedsWatermark($this, Auth::user())
        ];

        $params['s'] = $this->signature($params);

        return route(MediaFileThumbnail::class, $params);
    }

    /**
     * Is the media file an image?
     */
    public function isImage(): bool
    {
        return in_array($this->mimeType(), self::SUPPORTED_IMAGE_MIME_TYPES, true);
    }

    /**
     * What is the mime-type of this object?
     * For simplicity and efficiency, use the extension, rather than the contents.
     *
     * @return string
     */
    public function mimeType(): string
    {
        $extension = strtolower(pathinfo($this->multimedia_file_refn, PATHINFO_EXTENSION));

        return Mime::TYPES[$extension] ?? Mime::DEFAULT_TYPE;
    }

    /**
     * Generate a URL to download a media file.
     *
     * @param string $disposition How should the image be returned - "attachment" or "inline"
     *
     * @return string
     */
    public function downloadUrl(string $disposition): string
    {
        // The "mark" parameter is ignored, but needed for cache-busting.
        return route(MediaFileDownload::class, [
            'xref'        => $this->media->xref(),
            'tree'        => $this->media->tree()->name(),
            'fact_id'     => $this->fact_id,
            'disposition' => $disposition,
            'mark'        => Registry::imageFactory()->fileNeedsWatermark($this, Auth::user())
        ]);
    }

    /**
     * A list of image attributes
     *
     * @param FilesystemInterface $data_filesystem
     *
     * @return array<string>
     */
    public function attributes(FilesystemInterface $data_filesystem): array
    {
        $attributes = [];

        if (!$this->isExternal() || $this->fileExists($data_filesystem)) {
            try {
                $bytes                       = $this->media()->tree()->mediaFilesystem($data_filesystem)->getSize($this->filename());
                $kb                          = intdiv($bytes + 1023, 1024);
                $attributes['__FILE_SIZE__'] = I18N::translate('%s KB', I18N::number($kb));
            } catch (FileNotFoundException $ex) {
                // External/missing files have no size.
            }

            // Note: getAdapter() is defined on Filesystem, but not on FilesystemInterface.
            $filesystem = $this->media()->tree()->mediaFilesystem($data_filesystem);
            if ($filesystem instanceof Filesystem) {
                $adapter = $filesystem->getAdapter();
                // Only works for local filesystems.
                if ($adapter instanceof Local) {
                    $file = $adapter->applyPathPrefix($this->filename());
                    [$width, $height] = getimagesize($file);
                    $attributes['__IMAGE_SIZE__'] = I18N::translate('%1$s ?? %2$s pixels', I18N::number($width), I18N::number($height));
                }
            }
        }

        return $attributes;
    }

    /**
     * Read the contents of a media file.
     *
     * @param FilesystemInterface $data_filesystem
     *
     * @return string
     */
    public function fileContents(FilesystemInterface $data_filesystem): string
    {
        return $this->media->tree()->mediaFilesystem($data_filesystem)->read($this->multimedia_file_refn);
    }

    /**
     * Check if the file exists on this server
     *
     * @param FilesystemInterface $data_filesystem
     *
     * @return bool
     */
    public function fileExists(FilesystemInterface $data_filesystem): bool
    {
        return $this->media->tree()->mediaFilesystem($data_filesystem)->has($this->multimedia_file_refn);
    }

    /**
     * @return Media
     */
    public function media(): Media
    {
        return $this->media;
    }

    /**
     * Get the filename.
     *
     * @return string
     */
    public function filename(): string
    {
        return $this->multimedia_file_refn;
    }

    /**
     * What file extension is used by this file?
     *
     * @return string
     *
     * @deprecated since 2.0.4.  Will be removed in 2.1.0
     */
    public function extension(): string
    {
        return pathinfo($this->multimedia_file_refn, PATHINFO_EXTENSION);
    }

    /**
     * Create a URL signature parameter, using the same algorithm as league/glide,
     * for compatibility with URLs generated by older versions of webtrees.
     *
     * @param array<mixed> $params
     *
     * @return string
     */
    public function signature(array $params): string
    {
        unset($params['s']);

        ksort($params);

        // Sign the URL, to protect against mass-resize attacks.
        $glide_key = Site::getPreference('glide-key');

        if ($glide_key === '') {
            $glide_key = bin2hex(random_bytes(128));
            Site::setPreference('glide-key', $glide_key);
        }

        return md5($glide_key . ':?' . http_build_query($params));
    }
}

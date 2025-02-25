<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Model\Asset\Image\Thumbnail;

use Pimcore\Cache\RuntimeCache;
use Pimcore\Logger;
use Pimcore\Model;
use Pimcore\Tool\Serialize;

/**
 * @method bool isWriteable()
 * @method string getWriteTarget()
 * @method void delete(bool $forceClearTempFiles = false)
 * @method void save(bool $forceClearTempFiles = false)
 */
final class Config extends Model\AbstractModel
{
    use Model\Asset\Thumbnail\ClearTempFilesTrait;

    /**
     * @internal
     */
    protected const PREVIEW_THUMBNAIL_NAME = 'pimcore-system-treepreview';

    /**
     * format of array:
     * array(
     array(
     "method" => "myName",
     "arguments" =>
     array(
     "width" => 345,
     "height" => 200
     )
     )
     * )
     *
     * @internal
     *
     * @var array
     */
    protected $items = [];

    /**
     * @internal
     *
     * @var array
     */
    protected $medias = [];

    /**
     * @internal
     *
     * @var string
     */
    protected $name = '';

    /**
     * @internal
     *
     * @var string
     */
    protected $description = '';

    /**
     * @internal
     *
     * @var string
     */
    protected $group = '';

    /**
     * @internal
     *
     * @var string
     */
    protected $format = 'SOURCE';

    /**
     * @internal
     *
     * @var int
     */
    protected $quality = 85;

    /**
     * @internal
     *
     * @var float|null
     */
    protected $highResolution;

    /**
     * @internal
     *
     * @var bool
     */
    protected $preserveColor = false;

    /**
     * @internal
     *
     * @var bool
     */
    protected $preserveMetaData = false;

    /**
     * @internal
     *
     * @var bool
     */
    protected $rasterizeSVG = false;

    /**
     * @internal
     *
     * @var bool
     */
    protected $downloadable = false;

    /**
     * @internal
     *
     * @var int|null
     */
    protected $modificationDate;

    /**
     * @internal
     *
     * @var int|null
     */
    protected $creationDate;

    /**
     * @internal
     *
     * @var string|null
     */
    protected $filenameSuffix;

    /**
     * @internal
     *
     * @var bool
     */
    protected $preserveAnimation = false;

    /**
     * @internal
     *
     * @param string|array|self $config
     *
     * @return self|null
     */
    public static function getByAutoDetect($config)
    {
        $thumbnail = null;

        if (is_string($config)) {
            try {
                $thumbnail = self::getByName($config);
            } catch (\Exception $e) {
                Logger::error('requested thumbnail ' . $config . ' is not defined');

                return null;
            }
        } elseif (is_array($config)) {
            // check if it is a legacy config or a new one
            if (array_key_exists('items', $config)) {
                $thumbnail = self::getByArrayConfig($config);
            } else {
                $thumbnail = self::getByLegacyConfig($config);
            }
        } elseif ($config instanceof self) {
            $thumbnail = $config;
        }

        return $thumbnail;
    }

    /**
     * @param string $name
     *
     * @return null|Config
     *
     * @throws \Exception
     */
    public static function getByName($name)
    {
        $cacheKey = self::getCacheKey($name);

        if ($name === self::PREVIEW_THUMBNAIL_NAME) {
            return self::getPreviewConfig();
        }

        try {
            $thumbnail = RuntimeCache::get($cacheKey);
            if (!$thumbnail) {
                throw new \Exception('Thumbnail in registry is null');
            }

            $thumbnail->setName($name);
        } catch (\Exception $e) {
            try {
                $thumbnail = new self();
                /** @var Model\Asset\Image\Thumbnail\Config\Dao $dao */
                $dao = $thumbnail->getDao();
                $dao->getByName($name);
                RuntimeCache::set($cacheKey, $thumbnail);
            } catch (Model\Exception\NotFoundException $e) {
                return null;
            }
        }

        // only return clones of configs, this is necessary since we cache the configs in the registry (see above)
        // sometimes, e.g. when using the cropping tools, the thumbnail configuration is modified on-the-fly, since
        // pass-by-reference this modifications would then go to the cache/registry (singleton), by cloning the config
        // we can bypass this problem in an elegant way without parsing the XML config again and again
        $clone = clone $thumbnail;

        return $clone;
    }

    /**
     * @param string $name
     *
     * @return string
     */
    protected static function getCacheKey(string $name): string
    {
        return 'imagethumb_' . crc32($name);
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public static function exists(string $name): bool
    {
        $cacheKey = self::getCacheKey($name);
        if (RuntimeCache::isRegistered($cacheKey)) {
            return true;
        }

        if ($name === self::PREVIEW_THUMBNAIL_NAME) {
            return true;
        }

        return (bool) self::getByName($name);
    }

    /**
     * @internal
     *
     * @return Config
     */
    public static function getPreviewConfig()
    {
        $customPreviewImageThumbnail = \Pimcore::getContainer()->getParameter('pimcore.config')['assets']['preview_image_thumbnail'];
        $thumbnail = null;

        if ($customPreviewImageThumbnail) {
            $thumbnail = self::getByName($customPreviewImageThumbnail);
        }

        if (!$thumbnail) {
            $thumbnail = new self();
            $thumbnail->setName(self::PREVIEW_THUMBNAIL_NAME);
            $thumbnail->addItem('scaleByWidth', [
                'width' => 400,
            ]);
            $thumbnail->addItem('setBackgroundImage', [
                'path' => '/bundles/pimcoreadmin/img/tree-preview-transparent-background.png',
                'mode' => 'asTexture',
            ]);
            $thumbnail->setQuality(60);
            $thumbnail->setFormat('PJPEG');
        }

        $thumbnail->setHighResolution(2);

        return $thumbnail;
    }

    /**
     * @param string $name
     */
    protected function createMediaIfNotExists($name)
    {
        if (!array_key_exists($name, $this->medias)) {
            $this->medias[$name] = [];
        }
    }

    /**
     * @internal
     *
     * @param string|callable $name
     * @param array $parameters
     * @param string|null $media
     *
     * @return bool
     */
    public function addItem($name, $parameters, $media = null)
    {
        $item = [
            'method' => $name,
            'arguments' => $parameters,
        ];

        // default is added to $this->items for compatibility reasons
        if (!$media || $media == 'default') {
            $this->items[] = $item;
        } else {
            $this->createMediaIfNotExists($media);
            $this->medias[$media][] = $item;
        }

        return true;
    }

    /**
     * @internal
     *
     * @param int $position
     * @param string|callable $name
     * @param array $parameters
     * @param string|null $media
     *
     * @return bool
     */
    public function addItemAt($position, $name, $parameters, $media = null)
    {
        if (!$media || $media == 'default') {
            $itemContainer = &$this->items;
        } else {
            $this->createMediaIfNotExists($media);
            $itemContainer = &$this->medias[$media];
        }

        array_splice($itemContainer, $position, 0, [[
            'method' => $name,
            'arguments' => $parameters,
        ]]);

        return true;
    }

    /**
     * @internal
     */
    public function resetItems()
    {
        $this->items = [];
        $this->medias = [];
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public function selectMedia($name)
    {
        if (preg_match('/^[0-9a-f]{8}$/', $name)) {
            $hash = $name;
        } else {
            $hash = hash('crc32b', $name);
        }

        foreach ($this->medias as $key => $value) {
            $currentHash = hash('crc32b', $key);
            if ($key === $name || $currentHash === $hash) {
                $this->setItems($value);
                $this->setFilenameSuffix('media--' . $currentHash . '--query');

                return true;
            }
        }

        return false;
    }

    /**
     * @param string $description
     *
     * @return $this
     */
    public function setDescription($description)
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * @param array $items
     *
     * @return $this
     */
    public function setItems($items)
    {
        $this->items = $items;

        return $this;
    }

    /**
     * @return array
     */
    public function getItems()
    {
        return $this->items;
    }

    /**
     * @param string $name
     *
     * @return $this
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param string $format
     *
     * @return $this
     */
    public function setFormat($format)
    {
        $this->format = $format;

        return $this;
    }

    /**
     * @return string
     */
    public function getFormat()
    {
        return $this->format;
    }

    /**
     * @param int $quality
     *
     * @return $this
     */
    public function setQuality($quality)
    {
        if ($quality) {
            $this->quality = (int) $quality;
        }

        return $this;
    }

    /**
     * @return int
     */
    public function getQuality()
    {
        return $this->quality;
    }

    /**
     * @param float|null $highResolution
     */
    public function setHighResolution($highResolution)
    {
        $this->highResolution = (float) $highResolution;
    }

    /**
     * @return float|null
     */
    public function getHighResolution()
    {
        return $this->highResolution;
    }

    /**
     * @param array $medias
     */
    public function setMedias($medias)
    {
        $this->medias = $medias;
    }

    /**
     * @return array
     */
    public function getMedias()
    {
        return $this->medias;
    }

    /**
     * @return bool
     */
    public function hasMedias()
    {
        return !empty($this->medias);
    }

    /**
     * @param string $filenameSuffix
     */
    public function setFilenameSuffix($filenameSuffix)
    {
        $this->filenameSuffix = $filenameSuffix;
    }

    /**
     * @return string|null
     */
    public function getFilenameSuffix()
    {
        return $this->filenameSuffix;
    }

    /**
     * @internal
     *
     * @param array $config
     *
     * @return self
     */
    public static function getByArrayConfig($config)
    {
        $pipe = new self();

        if (isset($config['format']) && $config['format']) {
            $pipe->setFormat($config['format']);
        }
        if (isset($config['quality']) && $config['quality']) {
            $pipe->setQuality($config['quality']);
        }
        if (isset($config['items']) && $config['items']) {
            $pipe->setItems($config['items']);
        }

        if (isset($config['highResolution']) && $config['highResolution']) {
            $pipe->setHighResolution($config['highResolution']);
        }

        // set name
        $pipe->generateAutoName();

        return $pipe;
    }

    /**
     * This is mainly here for backward compatibility
     *
     * @internal
     *
     * @param array $config
     *
     * @return self
     */
    public static function getByLegacyConfig($config)
    {
        $pipe = new self();

        if (isset($config['format'])) {
            $pipe->setFormat($config['format']);
        }

        if (isset($config['quality'])) {
            $pipe->setQuality($config['quality']);
        }

        if (isset($config['cover'])) {
            $pipe->addItem('cover', [
                'width' => $config['width'],
                'height' => $config['height'],
                'positioning' => ((isset($config['positioning']) && !empty($config['positioning'])) ? (string)$config['positioning'] : 'center'),
                'forceResize' => (isset($config['forceResize']) ? (bool)$config['forceResize'] : false),
            ]);
        } elseif (isset($config['contain'])) {
            $pipe->addItem('contain', [
                'width' => $config['width'],
                'height' => $config['height'],
                'forceResize' => (isset($config['forceResize']) ? (bool)$config['forceResize'] : false),
            ]);
        } elseif (isset($config['frame'])) {
            $pipe->addItem('frame', [
                'width' => $config['width'],
                'height' => $config['height'],
                'forceResize' => (isset($config['forceResize']) ? (bool)$config['forceResize'] : false),
            ]);
        } elseif (isset($config['aspectratio']) && $config['aspectratio']) {
            if (isset($config['height']) && isset($config['width']) && $config['height'] > 0 && $config['width'] > 0) {
                $pipe->addItem('contain', [
                    'width' => $config['width'],
                    'height' => $config['height'],
                    'forceResize' => (isset($config['forceResize']) ? (bool)$config['forceResize'] : false),
                ]);
            } elseif (isset($config['height']) && $config['height'] > 0) {
                $pipe->addItem('scaleByHeight', [
                    'height' => $config['height'],
                    'forceResize' => (isset($config['forceResize']) ? (bool)$config['forceResize'] : false),
                ]);
            } else {
                $pipe->addItem('scaleByWidth', [
                    'width' => $config['width'],
                    'forceResize' => (isset($config['forceResize']) ? (bool)$config['forceResize'] : false),
                ]);
            }
        } else {
            if (!isset($config['width']) && isset($config['height'])) {
                $pipe->addItem('scaleByHeight', [
                    'height' => $config['height'],
                    'forceResize' => (isset($config['forceResize']) ? (bool)$config['forceResize'] : false),
                ]);
            } elseif (isset($config['width']) && !isset($config['height'])) {
                $pipe->addItem('scaleByWidth', [
                    'width' => $config['width'],
                    'forceResize' => (isset($config['forceResize']) ? (bool)$config['forceResize'] : false),
                ]);
            } elseif (isset($config['width']) && isset($config['height'])) {
                $pipe->addItem('resize', [
                    'width' => $config['width'],
                    'height' => $config['height'],
                ]);
            }
        }

        if (isset($config['highResolution'])) {
            $pipe->setHighResolution($config['highResolution']);
        }

        $pipe->generateAutoName();

        return $pipe;
    }

    /**
     * @internal
     *
     * @param Model\Asset\Image $asset
     *
     * @return array
     */
    public function getEstimatedDimensions($asset)
    {
        $originalWidth = $asset->getWidth();
        $originalHeight = $asset->getHeight();

        $dimensions = [
            'width' => $originalWidth,
            'height' => $originalHeight,
        ];

        $transformations = $this->getItems();
        if (is_array($transformations) && count($transformations) > 0) {
            if ($originalWidth && $originalHeight) {
                foreach ($transformations as $transformation) {
                    if (!empty($transformation)) {
                        $arg = $transformation['arguments'];

                        $forceResize = false;
                        if (isset($arg['forceResize']) && $arg['forceResize'] === true) {
                            $forceResize = true;
                        }

                        if (in_array($transformation['method'], ['resize', 'cover', 'frame', 'crop'])) {
                            $dimensions['width'] = $arg['width'];
                            $dimensions['height'] = $arg['height'];
                        } elseif ($transformation['method'] == '1x1_pixel') {
                            return [
                                'width' => 1,
                                'height' => 1,
                            ];
                        } elseif ($transformation['method'] == 'scaleByWidth') {
                            if ($arg['width'] <= $dimensions['width'] || $asset->isVectorGraphic() || $forceResize) {
                                $dimensions['height'] = round(($arg['width'] / $dimensions['width']) * $dimensions['height'], 0);
                                $dimensions['width'] = $arg['width'];
                            }
                        } elseif ($transformation['method'] == 'scaleByHeight') {
                            if ($arg['height'] < $dimensions['height'] || $asset->isVectorGraphic() || $forceResize) {
                                $dimensions['width'] = round(($arg['height'] / $dimensions['height']) * $dimensions['width'], 0);
                                $dimensions['height'] = $arg['height'];
                            }
                        } elseif ($transformation['method'] == 'contain') {
                            $x = $dimensions['width'] / $arg['width'];
                            $y = $dimensions['height'] / $arg['height'];

                            if (!$forceResize && $x <= 1 && $y <= 1 && !$asset->isVectorGraphic()) {
                                continue;
                            }

                            if ($x > $y) {
                                $dimensions['height'] = round(($arg['width'] / $dimensions['width']) * $dimensions['height'], 0);
                                $dimensions['width'] = $arg['width'];
                            } else {
                                $dimensions['width'] = round(($arg['height'] / $dimensions['height']) * $dimensions['width'], 0);
                                $dimensions['height'] = $arg['height'];
                            }
                        } elseif ($transformation['method'] == 'cropPercent') {
                            $dimensions['width'] = ceil($dimensions['width'] * ($arg['width'] / 100));
                            $dimensions['height'] = ceil($dimensions['height'] * ($arg['height'] / 100));
                        } elseif (in_array($transformation['method'], ['rotate', 'trim'])) {
                            // unable to calculate dimensions -> return empty
                            return [];
                        }
                    }
                }
            } else {
                // this method is only if we don't have the source dimensions
                // this doesn't necessarily return both with & height
                // and is only a very rough estimate, you should avoid falling back to this functionality
                foreach ($transformations as $transformation) {
                    if (!empty($transformation)) {
                        if (is_array($transformation['arguments']) && in_array($transformation['method'], ['resize', 'scaleByWidth', 'scaleByHeight', 'cover', 'frame'])) {
                            foreach ($transformation['arguments'] as $key => $value) {
                                if ($key == 'width' || $key == 'height') {
                                    $dimensions[$key] = $value;
                                }
                            }
                        }
                    }
                }
            }
        }

        // ensure we return int's, sometimes $arg[...] contain strings
        $dimensions['width'] = (int) $dimensions['width'] * ($this->getHighResolution() ?: 1);
        $dimensions['height'] = (int) $dimensions['height'] * ($this->getHighResolution() ?: 1);

        return $dimensions;
    }

    /**
     * @return int|null
     */
    public function getModificationDate()
    {
        return $this->modificationDate;
    }

    /**
     * @param int $modificationDate
     */
    public function setModificationDate($modificationDate)
    {
        $this->modificationDate = $modificationDate;
    }

    /**
     * @return int|null
     */
    public function getCreationDate()
    {
        return $this->creationDate;
    }

    /**
     * @param int $creationDate
     */
    public function setCreationDate($creationDate)
    {
        $this->creationDate = $creationDate;
    }

    /**
     * @return bool
     */
    public function isPreserveColor()
    {
        return $this->preserveColor;
    }

    /**
     * @param bool $preserveColor
     */
    public function setPreserveColor($preserveColor)
    {
        $this->preserveColor = $preserveColor;
    }

    /**
     * @return bool
     */
    public function isPreserveMetaData()
    {
        return $this->preserveMetaData;
    }

    /**
     * @param bool $preserveMetaData
     */
    public function setPreserveMetaData($preserveMetaData)
    {
        $this->preserveMetaData = $preserveMetaData;
    }

    /**
     * @return bool
     */
    public function isRasterizeSVG(): bool
    {
        return $this->rasterizeSVG;
    }

    /**
     * @param bool $rasterizeSVG
     */
    public function setRasterizeSVG(bool $rasterizeSVG): void
    {
        $this->rasterizeSVG = $rasterizeSVG;
    }

    /**
     * @return bool
     */
    public function isSvgTargetFormatPossible()
    {
        $supportedTransformations = ['resize', 'scaleByWidth', 'scaleByHeight'];
        foreach ($this->getItems() as $item) {
            if (!in_array($item['method'], $supportedTransformations)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return string
     */
    public function getGroup(): string
    {
        return $this->group;
    }

    /**
     * @param string $group
     */
    public function setGroup(string $group): void
    {
        $this->group = $group;
    }

    /**
     * @return bool
     */
    public function getPreserveAnimation(): bool
    {
        return $this->preserveAnimation;
    }

    /**
     * @param bool $preserveAnimation
     */
    public function setPreserveAnimation(bool $preserveAnimation): void
    {
        $this->preserveAnimation = $preserveAnimation;
    }

    /**
     * @return bool
     */
    public function isDownloadable(): bool
    {
        return $this->downloadable;
    }

    /**
     * @param bool $downloadable
     */
    public function setDownloadable(bool $downloadable): void
    {
        $this->downloadable = $downloadable;
    }

    public function __clone()
    {
        if ($this->dao) {
            $this->dao = clone $this->dao;
            $this->dao->setModel($this);
        }

        //rebuild asset path for overlays
        foreach ($this->items as &$item) {
            if (in_array($item['method'], ['addOverlay', 'addOverlayFit'])) {
                if (isset($item['arguments']['id'])) {
                    $img = Model\Asset\Image::getById($item['arguments']['id']);
                    if ($img) {
                        $item['arguments']['path'] = $img->getFullPath();
                    }
                }
            }
        }
    }

    /**
     * @internal
     *
     * @return array
     */
    public static function getAutoFormats(): array
    {
        return \Pimcore::getContainer()->getParameter('pimcore.config')['assets']['image']['thumbnails']['auto_formats'];
    }

    /**
     * @internal
     *
     * @return Config[]
     */
    public function getAutoFormatThumbnailConfigs(): array
    {
        $autoFormatThumbnails = [];

        foreach ($this->getAutoFormats() as $autoFormat => $autoFormatConfig) {
            if (Model\Asset\Image\Thumbnail::supportsFormat($autoFormat) && $autoFormatConfig['enabled']) {
                $autoFormatThumbnail = clone $this;
                $autoFormatThumbnail->setFormat($autoFormat);
                if (!empty($autoFormatConfig['quality'])) {
                    $autoFormatThumbnail->setQuality($autoFormatConfig['quality']);
                }

                $autoFormatThumbnails[$autoFormat] = $autoFormatThumbnail;
            }
        }

        return $autoFormatThumbnails;
    }

    /**
     * @internal
     */
    public function generateAutoName(): void
    {
        $serialized = Serialize::serialize($this->getItems());

        $this->setName($this->getName() . '_auto_' . md5($serialized));
    }
}

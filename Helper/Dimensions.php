<?php

namespace Swissup\Image\Helper;

use \Magento\Framework\UrlInterface;
use \Magento\Framework\App\Filesystem\DirectoryList;
use \Magento\Store\Model\Store;
use \Magento\Store\Model\ScopeInterface;

class Dimensions extends \Magento\Framework\App\Helper\AbstractHelper
{
    /**
     * @var array
     */
    private $memo = [];

    /**
     * @var \Magento\Framework\App\View\Deployment\Version
     */
    private $deploymentVersion;

    /**
     * @var \Magento\Framework\Filesystem
     */
    private $filesystem;

    /**
     * @var \Magento\Framework\Filesystem\Driver\File
     */
    private $fileDriver;

    /**
     * @var \Magento\Framework\HTTP\Adapter\CurlFactory
     */
    private $curlFactory;

    /**
     * @var \FastImageSize\FastImageSize
     */
    private $remoteImage;

    /**
     * @var bool
     */
    private $isCustomEntryPoint = false;

    /**
     * @param \Magento\Framework\App\Helper\Context $context
     * @param \Magento\Framework\App\View\Deployment\Version $deploymentVersion
     * @param \Magento\Framework\Filesystem $filesystem
     * @param \Magento\Framework\Filesystem\Driver\File $fileDriver
     * @param \Magento\Framework\HTTP\Adapter\CurlFactory $curlFactory
     * @param \FastImageSize\FastImageSize $remoteImage
     * @param bool $isCustomEntryPoint
     */
    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Framework\App\View\Deployment\Version $deploymentVersion,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Framework\Filesystem\Driver\File $fileDriver,
        \Magento\Framework\HTTP\Adapter\CurlFactory $curlFactory,
        \FastImageSize\FastImageSize $remoteImage,
        $isCustomEntryPoint = false
    ) {
        $this->deploymentVersion = $deploymentVersion;
        $this->filesystem = $filesystem;
        $this->fileDriver = $fileDriver;
        $this->curlFactory = $curlFactory;
        $this->remoteImage = $remoteImage;
        $this->isCustomEntryPoint = $isCustomEntryPoint;
        parent::__construct($context);
    }

    /**
     * @param  string $url Image url or path
     * @return int|float
     */
    public function getWidth($url)
    {
        $dimensions = $this->getDimensions($url);

        return $dimensions['width'];
    }

    /**
     * @param  string $url Image url or path
     * @return int|float
     */
    public function getHeight($url)
    {
        $dimensions = $this->getDimensions($url);

        return $dimensions['height'];
    }

    /**
     * @param  string $url Image url or path
     * @return array
     */
    public function getDimensions($url)
    {
        if (!empty($this->memo[$url])) {
            return $this->memo[$url];
        }

        if (substr($url, -4) === '.svg') {
            $dimensions = $this->getSvgDimensions($url);
        } else {
            $dimensions = $this->getImageDimensions($url);
        }

        if (!$dimensions) {
            $dimensions = [0, 0];
        }

        $this->memo[$url] = [
            'width' => $dimensions[0],
            'height' => $dimensions[1],
        ];

        return $this->memo[$url];
    }

    /**
     * Get image dimensions
     *
     * 1. Try to locate image locally and use getimagesize
     * 2. Use FastImageSize lib to detect remote image dimensions
     *
     * @param string $url
     * @return array|false
     */
    private function getImageDimensions($url)
    {
        $path = $this->convertUrlToPath($url);

        if ($this->fileDriver->isExists($path)) {
            if ($this->fileDriver->isDirectory($path) ||
                !$this->fileDriver->isReadable($path) ||
                0 == filesize($path)
            ) {
                return false;
            }
            $dimensions = getimagesize($path);
        } else {
            $dimensions = $this->remoteImage->getImageSize($url);
            if ($dimensions) {
                $dimensions = array_values($dimensions);
            }
        }

        return $dimensions;
    }

    /**
     * @param string $url
     * @return array|false
     * @see https://github.com/contao/imagine-svg/blob/master/src/Image.php#L245-L281
     */
    private function getSvgDimensions($url)
    {
        $data = false;
        $path = $this->convertUrlToPath($url);

        if ($this->fileDriver->isExists($path)) {
            try {
                $data = $this->fileDriver->fileGetContents($path);
            } catch (\Exception $e) {
                return false;
            }
        } elseif ($this->isUrl($url)) {
            $curl = $this->curlFactory->create()->setConfig([
                'header' => false,
                'verifypeer' => false,
            ]);
            $curl->write('GET', $url);

            $data = $curl->read();
            $responseCode = (int) $curl->getInfo(CURLINFO_HTTP_CODE);

            $curl->close();

            if ($responseCode !== 200) {
                return false;
            }
        }

        if (!$data) {
            return false;
        }

        try {
            $document = new \DOMDocument();
            $document->loadXML($data, LIBXML_NONET);
            $svg = $document->documentElement;
        } catch (\Exception $e) {
            return false;
        }

        if (!$svg || 'svg' !== strtolower($svg->tagName)) {
            return false;
        }

        $width = (float) $svg->getAttribute('width');
        $height = (float) $svg->getAttribute('height');

        if ($width && $height) {
            return [$width, $height];
        }

        $viewBox = preg_split('/[\s,]+/', $svg->getAttribute('viewBox') ?: '', -1);
        $viewBoxWidth = (float) ($viewBox[2] ?? 0);
        $viewBoxHeight = (float) ($viewBox[3] ?? 0);

        return [$viewBoxWidth, $viewBoxHeight];
    }

    /**
     * @param string $string
     * @return boolean
     */
    private function isUrl($string)
    {
        return strpos($string, 'http') === 0;
    }

    /**
     * Check if used entry point is custom
     *
     * @return bool
     */
    private function isCustomEntryPoint()
    {
        return $this->isCustomEntryPoint;
    }

    /**
     * @param string $url
     * @return string
     */
    private function convertUrlToPath($url)
    {
        if (!$this->isUrl($url)) {
            return $url;
        }

        $rootPath = $this->filesystem
            ->getDirectoryRead(DirectoryList::ROOT)
            ->getAbsolutePath();

        $baseUrls = [
            $this->_urlBuilder->getBaseUrl([
                '_type' => UrlInterface::URL_TYPE_DIRECT_LINK,
            ]),
            $this->_urlBuilder->getBaseUrl([
                '_type' => UrlInterface::URL_TYPE_DIRECT_LINK,
                '_secure' => true
            ])
        ];

        // Remove scriptname (index.php) from baseUrl \Magento\Store\Model\Store::_updatePathUseRewrites
        $isUseRewrite = $this->scopeConfig->isSetFlag(
            Store::XML_PATH_USE_REWRITES,
            ScopeInterface::SCOPE_STORE
        );
        if (!$isUseRewrite) {
            if ($this->isCustomEntryPoint()) {
                $indexFileName = 'index.php';
            } else {
                $scriptFilename = $this->_request->getServer('SCRIPT_FILENAME');
                // phpcs:ignore Magento2.Functions.DiscouragedFunction
                $indexFileName = basename($scriptFilename);
            }
            foreach ($baseUrls as &$baseUrl) {
                $baseUrl = preg_replace("/{$indexFileName}\/$/", '', $baseUrl);
            }
        }

        // Replace domain name with root path
        $localPath = str_replace(
            $baseUrls,
            $rootPath,
            $url
        );

        // Remove '/version...' from the path to the static image
        if ($this->scopeConfig->getValue('dev/static/sign') &&
            strpos($localPath, '/static/version') !== false
        ) {
            $localPath = str_replace(
                'static/version' . $this->deploymentVersion->getValue(),
                'static',
                $localPath
            );
        }

        // Add 'pub/' to the path
        $rules = [
            $rootPath . 'media/' => $rootPath . 'pub/media/',
            $rootPath . 'static/' => $rootPath . 'pub/static/',
        ];
        $localPath = str_replace(
            array_keys($rules),
            array_values($rules),
            $localPath
        );

        return $localPath;
    }
}

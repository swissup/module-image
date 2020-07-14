# swissup/module-image

**swissup/module-image** - is a Magento module to calculate image dimensions
using PHP. It uses `getimagesize` function when image is found in filesystem,
otherwise uses fallback to [marc1706/fast-image-size](https://github.com/marc1706/fast-image-size)
library. Supported formats: bmp, gif, ico, iff, jp2, jpeg, png, psd, svg, tif,
wbmpm, webp.

## Installation

```bash
composer require swissup/module-image
bin/magento setup:upgrade
```

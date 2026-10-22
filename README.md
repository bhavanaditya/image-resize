# Image Resize

[![Tests](https://github.com/bhavanaditya/image-resize/actions/workflows/tests.yml/badge.svg)](https://github.com/bhavanaditya/image-resize/actions/workflows/tests.yml)

Resize a whole batch of images in one go: every image is scaled down to fit a maximum size, keeping its shape.

I use it to shrink large photo dumps before uploading them to WordPress or HubSpot, so pages load faster and the media library stays light.

It comes in two forms:

- **[Web app](https://bhavanaditya.github.io/image-resize/)**: nothing to install. Works on any device, and your images never leave it.
- **PHP command-line tool**: for whole folder trees on a computer or server, with ImageMagick or GD.

## Web app

Open **[bhavanaditya.github.io/image-resize](https://bhavanaditya.github.io/image-resize/)**, choose images or a folder (or drag them in), pick a size and click **Resize**.

- Size presets for thumbnails (500 px), social media (1080 px), websites (1600 px) and high quality (2048 px), or type your own, like `800x600`
- Save as JPG, WebP or PNG, or keep each image's format, with a quality slider
- Shows the size of every image before and after, and how much you saved in total
- **Save to a folder** in Chrome and Edge on a computer, or **download a ZIP** in any browser
- Folders keep their structure, transparent images get a white background in JPG, and phone photos are turned upright
- Location and camera details are removed from the saved images

Everything runs in your browser. Images are not uploaded anywhere.

## Command-line tool

### Features

- Resizes a whole folder tree in one command and mirrors the folder structure in the output
- Keeps the aspect ratio and only ever shrinks images, never enlarges them
- Leaves your originals untouched by writing to a separate output folder
- Converts to JPG, PNG or WebP, or keeps each file's original format
- Rotates phone photos upright using their EXIF orientation
- Removes metadata to save space (ImageMagick engine)
- Flattens transparent PNGs onto white when converting to JPG, instead of black
- Skips hidden files (like `.DS_Store`) and anything that isn't an image
- Uses ImageMagick when it's installed and falls back to PHP's built-in GD extension
- Dry-run mode, so you can see what will happen first

### Requirements

- PHP 7.4 or newer
- One of:
  - [ImageMagick](https://imagemagick.org/) (recommended; handles more formats, including TIFF, HEIC and AVIF)
  - The PHP GD extension (bundled with most PHP installs)

Check what you have:

```bash
php -v
php -m | grep -i gd
magick -version    # or: convert -version
```

### Installation

Clone the repository, or just download `image_resize.php`. It's a single file with no dependencies.

```bash
git clone https://github.com/bhavanaditya/image-resize.git
cd image-resize
```

### Usage

```bash
php image_resize.php <source-folder> [options]
```

By default, images are resized to fit within 500 × 500 pixels, saved as JPG at quality 85, and written to a new folder next to the source called `<source>-resized`.

#### Options

| Option | Description | Default |
| --- | --- | --- |
| `-o`, `--output DIR` | Folder to write the resized images to | `<source>-resized` |
| `-s`, `--size WxH` | Maximum width and height. A single number means a square, e.g. `800` | `500x500` |
| `-q`, `--quality N` | JPG and WebP quality, from 1 to 100 | `85` |
| `-f`, `--format FMT` | `jpg`, `png`, `webp`, or `keep` to keep each file's format | `jpg` |
| `--engine NAME` | `auto`, `magick` or `gd` | `auto` |
| `-n`, `--dry-run` | Show what would happen without writing any files | off |
| `--overwrite` | Replace files that already exist in the output folder | off |
| `-h`, `--help` | Show help | |
| `--version` | Show the version | |

Options can go before or after the source folder.

#### Examples

Resize a folder using the defaults:

```bash
php image_resize.php ./photos
```

Make 300 × 300 thumbnails at quality 80 in a folder of your choice:

```bash
php image_resize.php ./photos -o ./thumbs -s 300x300 -q 80
```

Convert a folder of uploads to WebP, with a 1200px maximum:

```bash
php image_resize.php ./uploads --format webp --size 1200
```

Preview first, then run it for real:

```bash
php image_resize.php ./photos --dry-run
php image_resize.php ./photos
```

Example output:

```text
Resizing 3 image(s) from /Users/me/photos to fit 500x500 using ImageMagick (magick)
Output: /Users/me/photos-resized

  resize  beach.png -> beach.jpg
  resize  trips/goa.jpeg -> trips/goa.jpg
  skip    logo.jpg (already exists, use --overwrite)

Done: 2 resized, 1 skipped, 0 failed.
```

### How it works

- **Sizing:** every image is scaled to fit inside the box you give. A 1200 × 800 photo with `--size 500x500` becomes 500 × 333. Images that are already smaller are kept at their size (but still converted and recompressed).
- **File names:** each output file keeps its name and changes only its extension. If two files would end up with the same name, for example `photo.png` and `photo.jpg` both becoming `photo.jpg`, the second one is saved as `photo-png.jpg`, so nothing gets lost.
- **Existing files:** files already in the output folder are skipped, so it's safe to run the script again after adding new images. Use `--overwrite` to redo them.
- **Animated GIFs and multi-page TIFFs:** only the first frame is kept.
- **Exit codes:** `0` if everything went fine, `1` if any image failed, `2` if the options were invalid.

#### Supported formats

| Engine | Reads | Writes |
| --- | --- | --- |
| ImageMagick | JPG, PNG, GIF, WebP, BMP, TIFF, HEIC, AVIF (depending on your build) | Everything it reads |
| GD | JPG, PNG, GIF, WebP, BMP | JPG, PNG, WebP, GIF, BMP |

## Running the tests

The tests create sample images in a temporary folder, run the script against them and check the results.

```bash
php tests/run.php gd
php tests/run.php magick
```

The web app's sizing and naming rules have their own tests:

```bash
node --test tests/web.test.mjs
```

They all run on GitHub Actions for every push. The web app is published to GitHub Pages from the `web` folder whenever `master` changes; to try it locally, serve that folder, for example with `npx serve web`.

## Changelog

### 2.1.0

- Added the web app: resize, convert and compress images in the browser, with presets, before and after sizes, save to a folder or download a ZIP

### 2.0.0

- Rewritten as a proper command-line tool with options for size, quality, format and output folder
- Writes to a separate output folder instead of next to the originals
- Safe handling of file names with spaces and special characters
- Added the GD fallback, EXIF auto-rotation, dry-run mode and tests

### 1.0.0

- First version: a script with a hard-coded folder that resized images to 500 × 500 with ImageMagick

## License

Released under the [MIT License](LICENSE).

## Author

**Bhavanaditya D R** · [bhavanaditya.com](https://www.bhavanaditya.com/) · [LinkedIn](https://www.linkedin.com/in/drbhavanaditya/)

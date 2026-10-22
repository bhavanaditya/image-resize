#!/usr/bin/env php
<?php
/**
 * Batch image resizer.
 *
 * Walks a folder recursively, scales every image down to fit inside a box
 * (keeping its aspect ratio) and writes the result to an output folder that
 * mirrors the source layout. Uses ImageMagick when it is installed and falls
 * back to PHP's GD extension otherwise.
 *
 * Usage: php image_resize.php <source> [options]   (run with --help for details)
 */

declare(strict_types=1);

const VERSION = '2.0.0';
const INPUT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'heic', 'avif'];
const OUTPUT_FORMATS = ['jpg', 'png', 'webp', 'keep'];
const ENGINES = ['auto', 'magick', 'gd'];

exit(main($argv));

function main(array $argv): int
{
    try {
        $config = parse_args($argv);

        $source = realpath($config['source']);
        if ($source === false || !is_dir($source)) {
            throw new InvalidArgumentException("Source folder not found: {$config['source']}");
        }

        $output = rtrim($config['output'] ?? $source . '-resized', '/\\');
        if (!$config['dry_run'] && !is_dir($output) && !mkdir($output, 0775, true) && !is_dir($output)) {
            throw new RuntimeException("Could not create output folder: $output");
        }
        $output = absolute_path($output);

        $engine = pick_engine($config['engine']);
    } catch (InvalidArgumentException | RuntimeException $e) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
        return 2;
    }

    $files = find_images($source, $output);

    printf(
        "%sResizing %d image(s) from %s to fit %dx%d using %s\nOutput: %s\n\n",
        $config['dry_run'] ? '[dry run] ' : '',
        count($files),
        $source,
        $config['width'],
        $config['height'],
        $engine['name'],
        $output
    );

    $stats = ['resized' => 0, 'skipped' => 0, 'failed' => 0];
    $claimed = [];

    foreach ($files as $path) {
        $relative = substr($path, strlen($source) + 1);
        $relativeTarget = target_path($relative, $config['format'], $claimed);
        $target = $output . DIRECTORY_SEPARATOR . $relativeTarget;

        if (is_file($target) && !$config['overwrite']) {
            echo "  skip    $relativeTarget (already exists, use --overwrite)\n";
            $stats['skipped']++;
            continue;
        }

        if ($config['dry_run']) {
            echo "  resize  $relative -> $relativeTarget\n";
            $stats['resized']++;
            continue;
        }

        try {
            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException("Could not create folder $dir");
            }

            if ($engine['binary'] !== null) {
                resize_with_magick($engine['binary'], $path, $target, $config);
            } else {
                resize_with_gd($path, $target, $config);
            }

            echo "  resize  $relative -> $relativeTarget\n";
            $stats['resized']++;
        } catch (RuntimeException $e) {
            echo "  FAIL    $relative: {$e->getMessage()}\n";
            $stats['failed']++;
        }
    }

    printf(
        "\nDone: %d resized, %d skipped, %d failed.\n",
        $stats['resized'],
        $stats['skipped'],
        $stats['failed']
    );

    return $stats['failed'] > 0 ? 1 : 0;
}

/**
 * Parses command line arguments. Options may come before or after the source folder.
 */
function parse_args(array $argv): array
{
    $config = [
        'source'    => null,
        'output'    => null,
        'width'     => 500,
        'height'    => 500,
        'quality'   => 85,
        'format'    => 'jpg',
        'engine'    => 'auto',
        'dry_run'   => false,
        'overwrite' => false,
    ];

    $flags = [
        '-n'          => 'dry_run',
        '--dry-run'   => 'dry_run',
        '--overwrite' => 'overwrite',
    ];

    $valued = [
        '-o'        => 'output',
        '--output'  => 'output',
        '-s'        => 'size',
        '--size'    => 'size',
        '-q'        => 'quality',
        '--quality' => 'quality',
        '-f'        => 'format',
        '--format'  => 'format',
        '--engine'  => 'engine',
    ];

    $args = array_slice($argv, 1);

    while ($args) {
        $arg = array_shift($args);

        if ($arg === '-h' || $arg === '--help') {
            print_help();
            exit(0);
        }

        if ($arg === '--version') {
            echo VERSION, PHP_EOL;
            exit(0);
        }

        if (isset($flags[$arg])) {
            $config[$flags[$arg]] = true;
            continue;
        }

        $value = null;
        if (strpos($arg, '--') === 0 && strpos($arg, '=') !== false) {
            [$arg, $value] = explode('=', $arg, 2);
        }

        if (isset($valued[$arg])) {
            if ($value === null) {
                if (!$args) {
                    throw new InvalidArgumentException("Missing value for $arg");
                }
                $value = array_shift($args);
            }
            set_option($config, $valued[$arg], $value);
            continue;
        }

        if ($arg !== '' && $arg[0] === '-') {
            throw new InvalidArgumentException("Unknown option: $arg");
        }

        if ($config['source'] !== null) {
            throw new InvalidArgumentException('Only one source folder can be given.');
        }

        $config['source'] = $arg;
    }

    if ($config['source'] === null) {
        throw new InvalidArgumentException('Missing source folder. Run with --help for usage.');
    }

    return $config;
}

function set_option(array &$config, string $name, string $value): void
{
    switch ($name) {
        case 'size':
            if (!preg_match('/^(\d+)(?:x(\d+))?$/i', $value, $m) || (int) $m[1] < 1 || (isset($m[2]) && (int) $m[2] < 1)) {
                throw new InvalidArgumentException("Invalid size \"$value\". Use WIDTHxHEIGHT (e.g. 800x600) or one number for a square.");
            }
            $config['width'] = (int) $m[1];
            $config['height'] = isset($m[2]) ? (int) $m[2] : (int) $m[1];
            break;

        case 'quality':
            if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 100) {
                throw new InvalidArgumentException("Invalid quality \"$value\". Use a number from 1 to 100.");
            }
            $config['quality'] = (int) $value;
            break;

        case 'format':
            $value = strtolower($value) === 'jpeg' ? 'jpg' : strtolower($value);
            if (!in_array($value, OUTPUT_FORMATS, true)) {
                throw new InvalidArgumentException("Invalid format \"$value\". Use one of: " . implode(', ', OUTPUT_FORMATS) . '.');
            }
            $config['format'] = $value;
            break;

        case 'engine':
            $value = strtolower($value);
            if (!in_array($value, ENGINES, true)) {
                throw new InvalidArgumentException("Invalid engine \"$value\". Use one of: " . implode(', ', ENGINES) . '.');
            }
            $config['engine'] = $value;
            break;

        default:
            $config[$name] = $value;
    }
}

function print_help(): void
{
    $formats = implode(', ', OUTPUT_FORMATS);
    $version = VERSION;

    echo <<<HELP
Image Resize v$version

Resize every image in a folder (and its subfolders) to fit inside a box,
keeping the aspect ratio. Images are only ever made smaller, never larger.

Usage:
  php image_resize.php <source> [options]

Options:
  -o, --output DIR    Where to write resized images. The folder structure of
                      <source> is mirrored here. Default: <source>-resized
  -s, --size WxH      Maximum width and height. Default: 500x500
  -q, --quality N     JPEG/WebP quality, 1-100. Default: 85
  -f, --format FMT    Output format: $formats. Default: jpg
      --engine NAME   auto, magick or gd. Default: auto (ImageMagick if
                      installed, otherwise PHP GD)
  -n, --dry-run       Show what would happen without writing anything
      --overwrite     Replace files that already exist in the output folder
  -h, --help          Show this help
      --version       Show the version

Examples:
  php image_resize.php ./photos
  php image_resize.php ./photos -o ./thumbs -s 300x300 -q 80
  php image_resize.php ./uploads --format webp --size 1200x1200

HELP;
}

/**
 * Returns the sorted list of image files under $source, skipping hidden files
 * and folders (.DS_Store, .git) and the output folder if it sits inside $source.
 */
function find_images(string $source, string $output): array
{
    $filter = function (SplFileInfo $file) use ($output): bool {
        if ($file->getFilename()[0] === '.') {
            return false;
        }
        if ($file->isDir()) {
            return $file->getPathname() !== $output;
        }
        return in_array(strtolower($file->getExtension()), INPUT_EXTENSIONS, true);
    };

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            $filter
        )
    );

    $files = [];
    foreach ($iterator as $file) {
        $files[] = $file->getPathname();
    }
    sort($files);

    return $files;
}

/**
 * Works out the output path (relative to the output folder) for one image.
 * If two sources would produce the same name, e.g. photo.png and photo.jpg
 * both becoming photo.jpg, the second one gets its original extension added.
 */
function target_path(string $relative, string $format, array &$claimed): string
{
    $info = pathinfo($relative);
    $dir = $info['dirname'] === '.' ? '' : $info['dirname'] . DIRECTORY_SEPARATOR;
    $ext = strtolower($info['extension']);

    if ($format === 'keep') {
        $outExt = $ext === 'jpeg' ? 'jpg' : $ext;
    } else {
        $outExt = $format;
    }

    $candidate = $dir . $info['filename'] . '.' . $outExt;
    if (isset($claimed[$candidate])) {
        $candidate = $dir . $info['filename'] . '-' . $ext . '.' . $outExt;
    }
    $claimed[$candidate] = true;

    return $candidate;
}

function absolute_path(string $path): string
{
    $real = realpath($path);
    if ($real !== false) {
        return $real;
    }

    $isAbsolute = $path !== '' && ($path[0] === '/' || $path[0] === '\\' || preg_match('/^[A-Za-z]:/', $path));

    return $isAbsolute ? $path : getcwd() . DIRECTORY_SEPARATOR . $path;
}

function pick_engine(string $choice): array
{
    if ($choice !== 'gd') {
        // On Windows "convert" is a built-in disk tool, so only look for "magick" there.
        $binaries = DIRECTORY_SEPARATOR === '\\' ? ['magick'] : ['magick', 'convert'];

        foreach ($binaries as $binary) {
            if (command_exists($binary)) {
                return ['name' => "ImageMagick ($binary)", 'binary' => $binary];
            }
        }

        if ($choice === 'magick') {
            throw new RuntimeException('ImageMagick was not found. Install it, or use --engine gd.');
        }
    }

    if (!extension_loaded('gd')) {
        throw new RuntimeException($choice === 'gd'
            ? 'The PHP GD extension is not loaded.'
            : 'Neither ImageMagick nor the PHP GD extension is available. Install one of them.');
    }

    return ['name' => 'PHP GD', 'binary' => null];
}

function command_exists(string $binary): bool
{
    $finder = DIRECTORY_SEPARATOR === '\\' ? 'where' : 'command -v';
    exec($finder . ' ' . escapeshellarg($binary) . ' 2>&1', $lines, $code);

    return $code === 0;
}

function is_jpeg_path(string $path): bool
{
    return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg'], true);
}

function resize_with_magick(string $binary, string $input, string $output, array $config): void
{
    $args = [
        escapeshellarg($binary),
        // [0] reads only the first frame of animated GIFs and multi-page TIFFs.
        escapeshellarg($input . '[0]'),
        '-auto-orient',
        // ">" means shrink only, never enlarge.
        '-resize', escapeshellarg(sprintf('%dx%d>', $config['width'], $config['height'])),
        '-strip',
    ];

    if (is_jpeg_path($output)) {
        // JPEG has no transparency, so flatten onto white instead of black.
        array_push($args, '-background', 'white', '-alpha', 'remove', '-alpha', 'off');
    }

    array_push($args, '-quality', (string) $config['quality'], escapeshellarg($output));

    exec(implode(' ', $args) . ' 2>&1', $lines, $code);

    if ($code !== 0) {
        $message = trim(implode(' ', $lines));
        throw new RuntimeException($message !== '' ? $message : "ImageMagick exited with code $code");
    }
}

function resize_with_gd(string $input, string $output, array $config): void
{
    [$image, $type] = gd_load($input);

    if ($type === IMAGETYPE_JPEG) {
        $image = gd_auto_orient($image, $input);
    }

    $width = imagesx($image);
    $height = imagesy($image);
    $scale = min(1, $config['width'] / $width, $config['height'] / $height);
    $newWidth = max(1, (int) round($width * $scale));
    $newHeight = max(1, (int) round($height * $scale));

    $canvas = imagecreatetruecolor($newWidth, $newHeight);

    if (is_jpeg_path($output)) {
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    } else {
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
    }

    imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    $ext = strtolower(pathinfo($output, PATHINFO_EXTENSION));

    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            $saved = imagejpeg($canvas, $output, $config['quality']);
            break;
        case 'png':
            $saved = imagepng($canvas, $output, 9);
            break;
        case 'webp':
            if (!function_exists('imagewebp')) {
                throw new RuntimeException('This GD build has no WebP support. Install ImageMagick or pick another --format.');
            }
            $saved = imagewebp($canvas, $output, $config['quality']);
            break;
        case 'gif':
            $saved = imagegif($canvas, $output);
            break;
        case 'bmp':
            $saved = imagebmp($canvas, $output);
            break;
        default:
            throw new RuntimeException("GD cannot write .$ext files. Install ImageMagick or pick another --format.");
    }

    if (!$saved) {
        throw new RuntimeException("Could not write $output");
    }
}

/**
 * Loads an image with GD. Returns [image, IMAGETYPE_* constant].
 */
function gd_load(string $path): array
{
    $info = @getimagesize($path);
    if ($info === false) {
        throw new RuntimeException('Not a readable image.');
    }

    $loaders = [
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG  => 'imagecreatefrompng',
        IMAGETYPE_GIF  => 'imagecreatefromgif',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
        IMAGETYPE_BMP  => 'imagecreatefrombmp',
    ];

    $loader = $loaders[$info[2]] ?? null;
    if ($loader === null || !function_exists($loader)) {
        throw new RuntimeException('GD cannot read this format. Install ImageMagick to handle it.');
    }

    $image = @$loader($path);
    if ($image === false) {
        throw new RuntimeException('Could not decode the image.');
    }

    return [$image, $info[2]];
}

/**
 * Rotates a JPEG according to its EXIF orientation, so phone photos come out upright.
 */
function gd_auto_orient($image, string $path)
{
    if (!function_exists('exif_read_data')) {
        return $image;
    }

    $exif = @exif_read_data($path);
    $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

    switch ($orientation) {
        case 3:
            return imagerotate($image, 180, 0);
        case 6:
            return imagerotate($image, -90, 0);
        case 8:
            return imagerotate($image, 90, 0);
        default:
            return $image;
    }
}

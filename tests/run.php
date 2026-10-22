<?php
/**
 * Smoke test for image_resize.php.
 *
 * Usage: php tests/run.php [gd|magick]
 */

declare(strict_types=1);

$engine = $argv[1] ?? 'gd';
$script = dirname(__DIR__) . '/image_resize.php';
$root = sys_get_temp_dir() . '/image-resize-test-' . getmypid();
$source = "$root/photos";
$output = "$root/out";
$failures = 0;

function check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? '  ok    ' : '  FAIL  ') . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}

function run(array $args): array
{
    global $script;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }
    exec($cmd . ' 2>&1', $lines, $code);
    return [$code, implode(PHP_EOL, $lines)];
}

function remove_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

// Fixtures.
mkdir("$source/nested", 0777, true);

$wide = imagecreatetruecolor(1200, 800);
imagesavealpha($wide, true);
imagefill($wide, 0, 0, imagecolorallocatealpha($wide, 0, 0, 0, 127));
imagepng($wide, "$source/nested/wide.png");

imagejpeg(imagecreatetruecolor(300, 200), "$source/small.jpg");
imagepng(imagecreatetruecolor(100, 100), "$source/small.png");
imagejpeg(imagecreatetruecolor(400, 1600), "$source/tall.jpeg");
file_put_contents("$source/notes.txt", 'not an image');
file_put_contents("$source/.DS_Store", '');

echo "Engine: $engine" . PHP_EOL;

// First run resizes everything.
[$code, $log] = run([$source, '--output', $output, '--size', '500x500', '--engine', $engine]);
echo $log . PHP_EOL . PHP_EOL;
check($code === 0, 'first run exits with 0');

$expected = [
    'nested/wide.jpg' => [500, 333],
    'small.jpg'       => [300, 200],
    'small-png.jpg'   => [100, 100],
    'tall.jpg'        => [125, 500],
];

foreach ($expected as $file => [$width, $height]) {
    $size = @getimagesize("$output/$file");
    check($size !== false && $size[0] === $width && $size[1] === $height, "$file is {$width}x{$height}");
}

$written = iterator_count(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($output, FilesystemIterator::SKIP_DOTS)));
check($written === count($expected), 'only images are written (no .txt, no hidden files)');

// Second run skips files that already exist.
[$code, $log] = run([$source, '--output', $output, '--engine', $engine]);
check($code === 0 && strpos($log, '4 skipped') !== false, 'second run skips existing files');

// Dry run writes nothing.
[$code] = run([$source, '--output', "$root/dry", '--dry-run', '--engine', $engine]);
check($code === 0 && !is_dir("$root/dry"), 'dry run writes nothing');

// Options may come before the source folder.
[$code] = run(['-s', '50', '-f', 'png', '-o', "$root/png", '--engine', $engine, $source]);
$size = @getimagesize("$root/png/nested/wide.png");
check($code === 0 && $size !== false && $size[0] === 50 && $size[1] === 33, 'options before source, square size, png output');

// Bad input is rejected.
[$code] = run([$source, '--size', 'big']);
check($code === 2, 'invalid --size exits with 2');

[$code] = run(["$root/missing"]);
check($code === 2, 'missing source folder exits with 2');

remove_tree($root);

echo PHP_EOL . ($failures === 0 ? 'All tests passed.' : "$failures test(s) failed.") . PHP_EOL;
exit($failures === 0 ? 0 : 1);

<?php
/**
 * 打包三个插件为可上传安装的 zip。
 *
 * 结构要求（见 Blessing Skin 的 App\Services\Unzip::extract）：
 * zip 被直接解压到 plugins/ 目录，因此包内**必须自带一层插件目录名**，
 * 否则会散落成 plugins/package.json 而扫描不到。
 *
 * 用法：php make_release_packages.php [输出目录]
 */

$root   = __DIR__;
$outDir = $argv[1] ?? ($root . '/release-packages');

if (! is_dir($outDir) && ! mkdir($outDir, 0755, true)) {
    fwrite(STDERR, "无法创建输出目录: {$outDir}\n");
    exit(1);
}

$plugins = ['kokuu-home', 'kokuu-ui', 'kokuu-quote'];

/** 递归收集文件，返回相对 plugins/ 的路径列表 */
$collect = function (string $base, string $prefix = '') use (&$collect): array {
    $items = [];
    $dir   = $base . ($prefix === '' ? '' : '/' . $prefix);
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $rel  = $prefix === '' ? $entry : $prefix . '/' . $entry;
        $path = $base . '/' . $rel;
        // 排除版本控制与缓存
        if ($entry === '.git' || $entry === '__pycache__' || $entry === '.DS_Store') {
            continue;
        }
        $items = array_merge($items, is_dir($path) ? $collect($base, $rel) : [$rel]);
    }

    return $items;
};

foreach ($plugins as $name) {
    $dir = $root . '/plugins/' . $name;
    if (! is_file($dir . '/package.json')) {
        fwrite(STDERR, "跳过 {$name}：缺少 package.json\n");
        continue;
    }

    $manifest = json_decode(file_get_contents($dir . '/package.json'), true);
    $version  = $manifest['version'] ?? '0.0.0';
    $zipPath  = $outDir . "/{$name}-{$version}.zip";
    if (is_file($zipPath)) {
        unlink($zipPath);
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        fwrite(STDERR, "无法创建 {$zipPath}\n");
        exit(1);
    }

    $count = 0;
    foreach ($collect($dir) as $rel) {
        // 包内路径带插件名前缀（关键）
        $zip->addFile($dir . '/' . $rel, $name . '/' . $rel);
        $count++;
    }
    $zip->close();

    printf("  %-22s %6.1f KB  %2d 个文件\n", basename($zipPath), filesize($zipPath) / 1024, $count);
}

echo "完成，输出目录：{$outDir}\n";

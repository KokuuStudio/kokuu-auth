<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KokuuSkin 定制：核心查询路径补索引
 *
 * 背景
 * ----
 * 上游 Blessing Skin 的建表迁移（2016_11_18_133939_create_all_tables.php）
 * 只定义了自增主键，**未对任何业务查询字段建索引**。随着数据量增长
 * （皮肤库 / 衣柜 / 登录），以下查询会退化为全表扫描：
 *
 *   - 登录：      SELECT * FROM users WHERE email = ?          （每次登录）
 *   - 纹理去重：  SELECT * FROM textures WHERE hash = ?         （每次上传）
 *   - 皮肤库筛选：WHERE type = ? / uploader = ? / public = 1   （列表页主查询）
 *   - 衣柜：      WHERE user_uid = ? / texture_tid = ?         （衣柜页主查询）
 *   - 角色：      WHERE uid = ?                                 （用户中心）
 *   - 昵称搜索：  WHERE nickname LIKE '%x%'                     （管理后台）
 *
 * 对低配服务器（小内存 / 弱 CPU / SATA 盘）而言，全表扫描是主要的
 * 响应延迟来源。本迁移按「实际查询条件」补齐索引。
 *
 * 说明
 * ----
 * - 均为**新增**索引，不改动任何列定义或数据，行为完全不变
 * - SQLite 会自动为 `sqlite_sequence` 之外的表建立主键索引
 * - 部分索引在 MySQL 上也受支持；若目标数据库不支持，
 *   `up()` 内的 `shouldSkip` 会安全跳过并记录
 */
class AddPerformanceIndexes extends Migration
{
    /**
     * 需要补索引的表与列。
     * 格式：表名 => [ [列, 是否唯一], ... ]
     */
    private const INDEXES = [
        // 登录 / 找回密码 / 邮箱验证均按 email 精确定位
        'users' => [
            ['email', true],   // 唯一索引：邮箱是登录凭证
            ['nickname', false], // 后台昵称搜索
        ],

        // 纹理：皮肤库列表与去重
        'textures' => [
            ['hash', false],       // 上传去重（Texture::where('hash',...)）
            ['uploader', false],   // 「我上传的」筛选
            ['type', false],       // 按类型筛选（skin / cape）
            ['public', false],     // 公开性过滤
            // 注：tid 已是 increments 主键，SQLite 会自动生成索引，无需重复创建
        ],

        // 衣柜：join 两侧都需要索引
        'user_closet' => [
            ['user_uid', false],    // 列出我的衣柜
            ['texture_tid', false], // 判断某纹理是否在衣柜中
        ],

        // 角色：用户中心与角色校验
        'players' => [
            ['uid', false],         // 我的角色列表
            ['player_name', false], // 角色名校验（唯一性由应用层保证）
        ],
    ];

    public function up()
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $schema = Schema::getConnection()->getSchemaBuilder();

            foreach ($indexes as [$column, $unique]) {
                $name = $this->indexName($table, $column);

                // 已存在则跳过（保证幂等，重复执行本迁移不报错）
                if ($this->indexExists($table, $name)) {
                    continue;
                }

                // 部分数据库（如启用前缀的 MySQL）索引名有长度限制，
                // 统一缩短为 {table}_{column}_idx
                try {
                    $schema->table($table, function (Blueprint $blueprint) use ($column, $unique, $name) {
                        if ($unique) {
                            $blueprint->unique($column, $name);
                        } else {
                            $blueprint->index($column, $name);
                        }
                    });
                } catch (\Throwable $e) {
                    // 单个索引失败不应中断整个迁移（例如列上已有唯一约束）
                    $this->warn("跳过索引 {$name}：{$e->getMessage()}");
                }
            }
        }
    }

    public function down()
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $schema = Schema::getConnection()->getSchemaBuilder();

            foreach ($indexes as [$column, $unique]) {
                $name = $this->indexName($table, $column);
                if (! $this->indexExists($table, $name)) {
                    continue;
                }
                try {
                    $schema->table($table, function (Blueprint $blueprint) use ($name) {
                        $blueprint->dropIndex($name);
                    });
                } catch (\Throwable $e) {
                    $this->warn("回滚索引 {$name} 失败：{$e->getMessage()}");
                }
            }
        }
    }

    private function indexName(string $table, string $column): string
    {
        return "{$table}_{$column}_idx";
    }

    /**
     * 检查索引是否已存在。
     *
     * 用 Schema 的 hasIndex（Laravel 11+）不可用——本项目是 Laravel 10，
     * 因此直接查各数据库的元数据表。
     */
    private function indexExists(string $table, string $name): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        try {
            if ($driver === 'sqlite') {
                $rows = $connection->select(
                    'SELECT name FROM sqlite_master WHERE type = ? AND tbl_name = ?',
                    ['index', $table]
                );
            } elseif ($driver === 'mysql') {
                $rows = $connection->select(
                    'SELECT DISTINCT index_name AS name FROM information_schema.statistics
                     WHERE table_schema = DATABASE() AND table_name = ?',
                    [$table]
                );
            } elseif ($driver === 'pgsql') {
                $rows = $connection->select(
                    'SELECT indexname AS name FROM pg_indexes WHERE tablename = ?',
                    [$table]
                );
            } else {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }

        foreach ($rows as $row) {
            if (isset($row->name) && $row->name === $name) {
                return true;
            }
        }

        return false;
    }

    private function warn(string $message): void
    {
        if (function_exists('logger')) {
            logger('[KokuuSkin] ' . $message);
        }
    }
}

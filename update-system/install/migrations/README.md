# 版本更新系统 - 数据库 Migration 目录

此目录存放数据库升级脚本（Migration）。

## 命名规则

每个 Migration 文件以 `schema_version` 数字命名：

```
14.sql    → Schema 从 13 升到 14
15.sql    → Schema 从 14 升到 15
16.sql    → Schema 从 15 升到 16
...
```

## 执行顺序

VersionManager 会按照 `schema_version` 从小到大依次执行尚未执行的 Migration。

例如：当前 Schema 13，目标 Schema 16：

```
14.sql → 15.sql → 16.sql
```

## 执行记录

每个 Migration 执行成功后会记录到 `nb_system_migrations` 表：

| 字段        | 说明                          |
|-------------|-------------------------------|
| version     | Migration 版本号              |
| checksum    | SQL 文件的 SHA-256 校验值      |
| executed_at | 执行时间                       |

已执行的 Migration 不会重复执行。

## 首个 Migration

`14.sql` 创建了版本更新系统所需的两张表：

- `nb_system_migrations` — Migration 执行记录
- `nb_system_update_history` — 系统更新历史

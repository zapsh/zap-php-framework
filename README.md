# Zap PHP Framework

轻量级 PHP MVC 框架，开箱即用，适合快速开发中小型 Web 应用。

## 环境要求

- PHP >= 8.2
- PDO 扩展（MySQL / PostgreSQL / SQLite）
- OpenSSL 扩展
- Redis 扩展或 Predis（可选，Redis 缓存需要）
- Monolog 日志
- Twig 模板引擎

## 安装

```bash
composer require zapsh/zap-php-framework
```

## 项目目录结构

```
project/
├── app/
│   ├── controllers/       # 控制器
│   ├── models/            # 模型
│   ├── middlewares/       # 中间件
│   └── views/             # 视图模板
├── config/                # 配置文件
│   ├── config.php         # 应用配置
│   ├── database.php       # 数据库配置
│   ├── log.php            # 日志配置
│   ├── cache.php          # 缓存配置
│   └── route.php          # 路由配置
├── public/                # 入口目录
│   └── index.php          # 入口文件
├── var/
│   └── cache/             # 缓存文件
├── vendor/
└── composer.json
```

## 快速开始

### 入口文件 `public/index.php`

```php
<?php

define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . DIRECTORY_SEPARATOR . 'app');
define('CONFIG_PATH', ROOT_PATH . DIRECTORY_SEPARATOR . 'config');
define('VAR_PATH', ROOT_PATH . DIRECTORY_SEPARATOR . 'var');
define('VENDOR_PATH', ROOT_PATH . DIRECTORY_SEPARATOR . 'vendor');

require_once VENDOR_PATH . '/autoload.php';

// 启动应用（自动加载 config/route.php 中的路由并分发请求）
(new \zap\App(ROOT_PATH))->run();
```


## URL Rewrite

### Apache

```apacheconf
Options +FollowSymLinks -Indexes
RewriteEngine On

RewriteCond %{HTTP:Authorization} .
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [QSA,PT,L]
```

### Nginx

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

---

## License

MIT License

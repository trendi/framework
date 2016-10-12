# a fast php framework

 include rpc server, web server, connection pool server

### 快速体验:

* 只支持linux

* 先安装 swoole,mbstring,posix扩展

* 安装

``
composer require trendi/framework
``

* 执行

```
sudo ./vendor/bin/trendi create:project mela

sudo composer dumpautoload

sudo chmod 0777 trendi

sudo ./trendi server:restart
```

* 在浏览器打开地址

``
http://127.0.0.1:7000/
``

### 文档

[目录](doc/index.md)